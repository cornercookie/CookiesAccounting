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
 * - 自動建立 users / ledgers / ledger_access / entries 五張表（含 login_attempts）
 * - 從 .env 建立種子管理員帳號（users 表尚不存在時）
 *
 * @throws RuntimeException 缺少 pdo_sqlite 擴充時拋出
 */
function getPdo(): PDO
{
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

    // WAL mode + busy timeout on every connection
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA busy_timeout=5000;');

    // Auto-create schema + seed admin on first run
    ensureSchema($pdo);
    seedAdmin($pdo);

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
        created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    );

    CREATE TABLE IF NOT EXISTS ledgers (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        owner_id    INTEGER NOT NULL,
        archived    INTEGER NOT NULL DEFAULT 0,
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
SQL);

    $pdo->exec(<<<'SQL'
    CREATE INDEX IF NOT EXISTS idx_ledgers_owner_id              ON ledgers(owner_id);
    CREATE INDEX IF NOT EXISTS idx_ledger_access_ledger_id       ON ledger_access(ledger_id);
    CREATE INDEX IF NOT EXISTS idx_ledger_access_user_id         ON ledger_access(user_id);
    CREATE INDEX IF NOT EXISTS idx_entries_ledger_id             ON entries(ledger_id);
    CREATE INDEX IF NOT EXISTS idx_entries_user_id               ON entries(user_id);
    CREATE INDEX IF NOT EXISTS idx_entries_entry_date_deleted    ON entries(entry_date, deleted);
    CREATE INDEX IF NOT EXISTS idx_entries_type                  ON entries(type);
SQL);

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
}

/**
 * 從 .env 將管理員帳號種子寫入 users 表，並建立預設共同帳本與存取權限。
 * 若同名帳號已存在，則不做任何變更（不覆寫既有密碼）。
 */
function seedAdmin(PDO $pdo): void
{
    $username = ADMIN_USERNAME;
    $password = ADMIN_PASSWORD;
    if ($username === '' || $password === '') {
        return; // .env 未設定，不建立種子帳號
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
            'INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, :role)'
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
