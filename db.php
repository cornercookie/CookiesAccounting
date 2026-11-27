<?php
// 全家人記帳系統 — SQLite 資料庫連線與初始化
// PDO + 預備陳述式只有；不拼字串 SQL。

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * 開啟（並於首次執行時初始化）SQLite 資料庫。
 *
 * - PDO::ERRMODE_EXCEPTION          -> 錯誤拋出，不靜默空白頁
 * - PDO::ATTR_EMULATE_PREPARES=false -> 真正的預備陳述式
 * - PRAGMA journal_mode=WAL         -> 並存讀寫
 * - PRAGMA busy_timeout=5000        -> 鎖定時等候而非失敗
 * - 自動建立 users / ledgers / ledger_access / entries / audit_log 等表（含 login_attempts）
 * - 從 .env 建立種子管理員帳號（users 表尚不存在時）
 *
 * @throws RuntimeException 缺少 pdo_sqlite 擴充時拋出
 */
function getPdo(): PDO
{
    static $cached = null;
    if ($cached instanceof PDO) {
        return $cached;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException(
            'The pdo_sqlite PHP extension is not available on this host. ' .
            'SQLite support is required for the family accounting system.'
        );
    }

    $dbPath = DB_PATH;
    $dir = dirname($dbPath);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create private_data directory: ' . $dir);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

    // WAL mode + busy timeout on every connection
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA busy_timeout=5000;');

    // Auto-create schema + seed admin on first run
    ensureSchema($pdo);
    seedAdmin($pdo);

    $cached = $pdo;
    return $pdo;
}

/**
 * 建立資料表與索引（若尚不存在）。
 */
function ensureSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        username       TEXT    NOT NULL UNIQUE,
        password_hash  TEXT    NOT NULL,
        role           TEXT    NOT NULL DEFAULT 'admin'
                       CHECK (role IN ('admin','member')),
        created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        password_changed_at TEXT    -- 密碼最後變更時間，用於使既有 Session 失效（FIND-013）
    );

    CREATE TABLE IF NOT EXISTS ledgers (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        owner_id    INTEGER NOT NULL,
        archived    INTEGER NOT NULL DEFAULT 0,
        monthly_budget_cents INTEGER NOT NULL DEFAULT 0, -- 每月預算（整數分，0 = 未設定）
        created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (owner_id) REFERENCES users(id)
    );

    CREATE TABLE IF NOT EXISTS ledger_access (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        ledger_id   INTEGER NOT NULL,
        user_id     INTEGER NOT NULL,
        role        TEXT    NOT NULL DEFAULT 'member'
                       CHECK (role IN ('owner','member','viewer')),
        UNIQUE (ledger_id, user_id),
        FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
        FOREIGN KEY (user_id)   REFERENCES users(id)
    );

    CREATE TABLE IF NOT EXISTS entries (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        ledger_id     INTEGER NOT NULL,
        user_id       INTEGER NOT NULL,
        amount_cents  INTEGER NOT NULL,      -- 金額存整數分，不含 FLOAT
        type          TEXT    NOT NULL CHECK (type IN ('income','expense')),
        category      TEXT    NOT NULL DEFAULT '',
        entry_date    TEXT    NOT NULL,
        note          TEXT    NOT NULL DEFAULT '',
        receipt_path  TEXT    NOT NULL DEFAULT '', -- 收據圖片私密路徑（例如 private_data/uploads/<random>.jpg）
        deleted       INTEGER NOT NULL DEFAULT 0,
        created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
        FOREIGN KEY (user_id)   REFERENCES users(id)
    );

    CREATE TABLE IF NOT EXISTS login_attempts (
        identifier   TEXT    PRIMARY KEY,
        failed_count INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT,
        updated_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    );

    CREATE TABLE IF NOT EXISTS api_rate_limits (
        identifier TEXT    NOT NULL,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX IF NOT EXISTS idx_api_rate_limits_identifier ON api_rate_limits(identifier);
    CREATE INDEX IF NOT EXISTS idx_api_rate_limits_created_at ON api_rate_limits(created_at);

    CREATE TABLE IF NOT EXISTS audit_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER,               -- 未登入/未知使用者時為 NULL
        username   TEXT    NOT NULL DEFAULT '',
        action     TEXT    NOT NULL,      -- 例：login / entry_create / admin_user_delete
        detail     TEXT    NOT NULL DEFAULT '',
        ip         TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    );
SQL);

    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS login_sessions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id      INTEGER NOT NULL,
        ip           TEXT    NOT NULL DEFAULT '',
        user_agent   TEXT    NOT NULL DEFAULT '',
        login_at     TEXT    NOT NULL,
        is_first_seen INTEGER NOT NULL DEFAULT 1 CHECK (is_first_seen IN (0,1)),
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    CREATE INDEX IF NOT EXISTS idx_login_sessions_user_id  ON login_sessions(user_id);
    CREATE INDEX IF NOT EXISTS idx_login_sessions_login_at ON login_sessions(login_at);
SQL);

    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS categories (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL UNIQUE,                       -- 全站分類名稱唯一
        is_active   INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
        ledger_id   INTEGER DEFAULT NULL,                          -- 全站共用，固定為 NULL
        created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    );
SQL);
    $pdo->exec(<<<'SQL'
    CREATE INDEX IF NOT EXISTS idx_categories_is_active ON categories(is_active);
    CREATE INDEX IF NOT EXISTS idx_categories_name ON categories(name);
SQL);

// entries.type CHECK 約束擴充：加入 'transfer' 類型（轉帳）
// SQLite 不支援直接修改 CHECK 約束，需透過表重建方式進行 migration。
// 必須加入冪等性守衛，因為 ensureSchema() 在每次 getPdo() 都會執行.
$row = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='entries'")->fetch();
$currentSql = $row === false ? '' : (string) ($row['sql'] ?? '');
if (!str_contains($currentSql, "'transfer'")) {
    // 舊庫可能已有幣別／確認／配對欄位。SELECT * 進較窄的新表會失敗並回滾，
    // 轉帳 CHECK 就永遠加不上去，而且一旦欄位數碰巧相同還會丟欄位。
    $fkWasOn = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($fkWasOn) {
        $pdo->exec('PRAGMA foreign_keys=OFF');
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $have = [];
        foreach ($pdo->query('PRAGMA table_info(entries)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $name = (string) ($col['name'] ?? '');
            if ($name !== '') {
                $have[$name] = true;
            }
        }
        $pdo->exec('DROP TABLE IF EXISTS entries_temp');
        $pdo->exec(<<<'SQL'
CREATE TABLE entries_temp (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ledger_id     INTEGER NOT NULL,
    user_id       INTEGER NOT NULL,
    amount_cents  INTEGER NOT NULL,
    type          TEXT    NOT NULL CHECK (type IN ('income','expense','transfer')),
    category      TEXT    NOT NULL DEFAULT '',
    entry_date    TEXT    NOT NULL,
    note          TEXT    NOT NULL DEFAULT '',
    receipt_path  TEXT    NOT NULL DEFAULT '',
    deleted       INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    currency      TEXT    NOT NULL DEFAULT 'TWD',
    exchange_rate INTEGER NOT NULL DEFAULT 10000,
    original_amount_cents INTEGER NOT NULL DEFAULT 0,
    transfer_pair_id INTEGER,
    recurring_source_id INTEGER,
    ack_status    TEXT    NOT NULL DEFAULT 'pending' CHECK (ack_status IN ('pending','confirmed','disputed')),
    ack_user_id   INTEGER,
    ack_at        TEXT,
    FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
    FOREIGN KEY (user_id)   REFERENCES users(id)
);
SQL);
        $targetCols = [
            'id', 'ledger_id', 'user_id', 'amount_cents', 'type', 'category', 'entry_date',
            'note', 'receipt_path', 'deleted', 'created_at', 'currency', 'exchange_rate',
            'original_amount_cents', 'transfer_pair_id', 'recurring_source_id',
            'ack_status', 'ack_user_id', 'ack_at',
        ];
        $copyCols = array_values(array_filter(
            $targetCols,
            static fn(string $name): bool => isset($have[$name])
        ));
        if ($copyCols === []) {
            throw new RuntimeException('entries migration found no columns to copy');
        }
        $colList = implode(', ', $copyCols);
        $pdo->exec('INSERT INTO entries_temp (' . $colList . ') SELECT ' . $colList . ' FROM entries');
        $pdo->exec('DROP TABLE entries');
        $pdo->exec('ALTER TABLE entries_temp RENAME TO entries');
        $pdo->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS idx_entries_ledger_id ON entries(ledger_id);
CREATE INDEX IF NOT EXISTS idx_entries_user_id ON entries(user_id);
CREATE INDEX IF NOT EXISTS idx_entries_entry_date_deleted ON entries(entry_date, deleted);
CREATE INDEX IF NOT EXISTS idx_entries_type ON entries(type);
CREATE INDEX IF NOT EXISTS idx_entries_transfer_pair_id ON entries(transfer_pair_id);
CREATE INDEX IF NOT EXISTS idx_entries_ack_status ON entries(ack_status);
SQL);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        logError('entries.type migration failed: ' . $e->getMessage());
    }
    if ($fkWasOn) {
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
}

    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS recurring_entries (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        ledger_id      INTEGER NOT NULL,
        user_id        INTEGER NOT NULL,
        name           TEXT    NOT NULL DEFAULT '',
        amount_cents   INTEGER NOT NULL CHECK (amount_cents > 0),
        type           TEXT    NOT NULL CHECK (type IN ('income','expense')),
        category       TEXT    NOT NULL DEFAULT '',
        start_date     TEXT    NOT NULL,
        next_due_date  TEXT    NOT NULL,
        is_active      INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
        created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
        FOREIGN KEY (user_id)   REFERENCES users(id)
    );
SQL);
    $pdo->exec(<<<'SQL'
    CREATE INDEX IF NOT EXISTS idx_recurring_entries_ledger_id ON recurring_entries(ledger_id);
    CREATE INDEX IF NOT EXISTS idx_recurring_entries_user_id   ON recurring_entries(user_id);
    CREATE INDEX IF NOT EXISTS idx_recurring_entries_next_due  ON recurring_entries(next_due_date);
SQL);

    // category_budgets 表（類別預算）[#26 新增唯一索引]
    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS category_budgets (
        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
        ledger_id             INTEGER NOT NULL,
        category              TEXT    NOT NULL,
        monthly_budget_cents  INTEGER NOT NULL CHECK (monthly_budget_cents > 0),
        is_active             INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
        created_at            TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at            TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
        FOREIGN KEY (category) REFERENCES categories(name)
    );
SQL);
    $pdo->exec(<<<'SQL'
    CREATE INDEX IF NOT EXISTS idx_category_budgets_ledger_active
        ON category_budgets(ledger_id, is_active);
    CREATE INDEX IF NOT EXISTS idx_category_budgets_ledger_category
        ON category_budgets(ledger_id, category);
SQL);
    // [#26] 唯一索引：獨立 exec + try-catch，不中斷主流程
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = :type AND tbl_name = :table");
    $stmt->execute([':type' => 'index', ':table' => 'category_budgets']);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('uq_category_budgets_active_only', $existing, true) || in_array('uq_category_budgets_ledger_category_active', $existing, true)) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_category_budgets_active_only ON category_budgets(ledger_id, category) WHERE is_active = 1");
            $pdo->exec("DROP INDEX IF EXISTS uq_category_budgets_ledger_category_active");
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logError('[#26] category_budgets 唯一索引遷移失敗: ' . $e->getMessage());
        }
    }

    $pdo->exec(<<<'SQL'
    CREATE INDEX IF NOT EXISTS idx_ledgers_owner_id              ON ledgers(owner_id);
    CREATE INDEX IF NOT EXISTS idx_ledger_access_ledger_id       ON ledger_access(ledger_id);
    CREATE INDEX IF NOT EXISTS idx_ledger_access_user_id         ON ledger_access(user_id);
    CREATE INDEX IF NOT EXISTS idx_entries_ledger_id             ON entries(ledger_id);
    CREATE INDEX IF NOT EXISTS idx_entries_user_id               ON entries(user_id);
    CREATE INDEX IF NOT EXISTS idx_entries_entry_date_deleted    ON entries(entry_date, deleted);
    CREATE INDEX IF NOT EXISTS idx_entries_type                  ON entries(type);
    CREATE INDEX IF NOT EXISTS idx_audit_log_created_at          ON audit_log(created_at);
    CREATE INDEX IF NOT EXISTS idx_audit_log_user_id             ON audit_log(user_id);
    CREATE INDEX IF NOT EXISTS idx_audit_log_action              ON audit_log(action);
SQL);

    // entry_comments 表（家庭訊息留言板 [#21]）
    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS entry_comments (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_id      INTEGER NOT NULL,
        user_id       INTEGER NOT NULL,
        comment       TEXT    NOT NULL,
        deleted       INTEGER NOT NULL DEFAULT 0 CHECK (deleted IN (0,1)),
        created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id)  REFERENCES users(id)
    );
SQL);
    $pdo->exec(<<<'SQL'
    CREATE INDEX IF NOT EXISTS idx_entry_comments_entry_id ON entry_comments(entry_id);
    CREATE INDEX IF NOT EXISTS idx_entry_comments_created_at ON entry_comments(created_at);
    CREATE INDEX IF NOT EXISTS idx_entry_comments_deleted ON entry_comments(deleted);
SQL);

    // 先查詢 entries 表結構，供後續 migration 使用
    $entriesCols = $pdo->query('PRAGMA table_info(entries)')->fetchAll(PDO::FETCH_ASSOC);

    // [#24] 多幣別支援：currency、exchange_rate、original_amount_cents 欄位
    // ⚠️ 必須置於 $entriesCols = $pdo->query('PRAGMA table_info(entries)')->fetchAll(...) 之後
    $hasCurrency = $hasRate = $hasOriginalAmount = false;
    foreach ($entriesCols as $col) {
        $name = $col['name'] ?? '';
        if ($name === 'currency') $hasCurrency = true;
        elseif ($name === 'exchange_rate') $hasRate = true;
        elseif ($name === 'original_amount_cents') $hasOriginalAmount = true;
    }
    if (!$hasCurrency) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN currency TEXT NOT NULL DEFAULT 'TWD'");
    }
    if (!$hasRate) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN exchange_rate INTEGER NOT NULL DEFAULT 10000");
    }
    if (!$hasOriginalAmount) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN original_amount_cents INTEGER NOT NULL DEFAULT 0");
    }
    // 既有資料回填：ALTER TABLE ADD COLUMN NOT NULL DEFAULT 已自動填入預設值
    // 僅針對極端情況（currency 為空字串）補值
    try {
        $pdo->exec("UPDATE entries SET currency='TWD', exchange_rate=10000, original_amount_cents=amount_cents WHERE currency = ''");
    } catch (Throwable $e) {
        logError('multi-currency migration UPDATE failed: ' . $e->getMessage());
    }
    // 注意：不建立資料庫 CHECK 約束，幣別與匯率驗證完全在應用層執行

    // [#22] transfer_pair_id 欄位（轉帳配對）
    $hasTransferPair = false;
    foreach ($entriesCols as $col) {
        if (($col['name'] ?? '') === 'transfer_pair_id') {
            $hasTransferPair = true;
            break;
        }
    }
    if (!$hasTransferPair) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN transfer_pair_id INTEGER");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_entries_transfer_pair_id ON entries(transfer_pair_id)");
    }

    // 舊版資料庫 migration：ledgers 補 archived 欄位（封存/還原帳本用）
    $cols = $pdo->query('PRAGMA table_info(ledgers)')->fetchAll(PDO::FETCH_ASSOC);
    $hasArchived = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'archived') {
            $hasArchived = true;
            break;
        }
    }
    if (!$hasArchived) {
        $pdo->exec('ALTER TABLE ledgers ADD COLUMN archived INTEGER NOT NULL DEFAULT 0');
    }

    // 舊版資料庫 migration：entries 補 receipt_path 欄位（收據圖片路徑）
    $hasReceipt = false;
    foreach ($entriesCols as $col) {
        if (($col['name'] ?? '') === 'receipt_path') {
            $hasReceipt = true;
            break;
        }
    }
    if (!$hasReceipt) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN receipt_path TEXT NOT NULL DEFAULT ''");
    }

    // 舊版資料庫 migration：entries 補 recurring_source_id 欄位（重複記錄來源關聯）
    $hasRecurringSource = false;
    foreach ($entriesCols as $col) {
        if (($col['name'] ?? '') === 'recurring_source_id') {
            $hasRecurringSource = true;
            break;
        }
    }
    if (!$hasRecurringSource) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN recurring_source_id INTEGER");
    }

    // [#20] 記錄確認/認領機制：entries 補確認狀態欄位與確認者/確認時間
    $hasAckStatus = $hasAckUser = $hasAckAt = false;
    foreach ($entriesCols as $col) {
        $name = $col['name'] ?? '';
        if ($name === 'ack_status') {
            $hasAckStatus = true;
        } elseif ($name === 'ack_user_id') {
            $hasAckUser = true;
        } elseif ($name === 'ack_at') {
            $hasAckAt = true;
        }
    }
    if (!$hasAckStatus) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN ack_status TEXT NOT NULL DEFAULT 'pending' CHECK (ack_status IN ('pending','confirmed','disputed'))");
    }
    if (!$hasAckUser) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN ack_user_id INTEGER");
    }
    if (!$hasAckAt) {
        $pdo->exec("ALTER TABLE entries ADD COLUMN ack_at TEXT");
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_entries_ack_status ON entries(ack_status)");

    // 舊版資料庫 migration：ledgers 補 monthly_budget_cents 欄位（每月預算，整數分）
    $hasBudget = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'monthly_budget_cents') {
            $hasBudget = true;
            break;
        }
    }
    if (!$hasBudget) {
        $pdo->exec('ALTER TABLE ledgers ADD COLUMN monthly_budget_cents INTEGER NOT NULL DEFAULT 0');
    }

    // 舊版資料庫 migration：users 補 password_changed_at 欄位（使既有 Session 失效用）
    $usersCols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    $hasPasswordChangedAt = false;
    foreach ($usersCols as $col) {
        if (($col['name'] ?? '') === 'password_changed_at') {
            $hasPasswordChangedAt = true;
            break;
        }
    }
    if (!$hasPasswordChangedAt) {
        $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at TEXT");
    }
}

/**
 * 從 .env 將管理員帳號種子寫入 users 表，並建立預設共同帳本與存取權限。
 * 若同名帳號已存在，則不做任何變更（不覆寫既有密碼）。
 */
function seedAdmin(PDO $pdo): void
{
    $username = ADMIN_USERNAME;
    $password = ADMIN_PASSWORD;
    if ($username === '' || isPlaceholderPassword($password)) {
        return; // .env 未設定或仍為預設佔位符，不建立種子帳號
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    if ($stmt->fetch() !== false) {
        return; // 已有同名帳號，不覆寫
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, password_changed_at) VALUES (:username, :hash, :role, datetime(\'now\',\'localtime\'))'
        );
        $stmt->execute([
            ':username' => $username,
            ':hash'     => $hash,
            ':role'     => 'admin',
        ]);
        $adminId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO ledgers (name, owner_id) VALUES (:name, :owner_id)'
        );
        $stmt->execute([
            ':name'     => '家庭共同帳本',
            ':owner_id' => $adminId,
        ]);
        $ledgerId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO ledger_access (ledger_id, user_id, role) VALUES (:ledger_id, :user_id, :role)'
        );
        $stmt->execute([
            ':ledger_id' => $ledgerId,
            ':user_id'   => $adminId,
            ':role'      => 'owner',
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
