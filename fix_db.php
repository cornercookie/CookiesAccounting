<?php
$content = file_get_contents('db.php');
$old = "SQL);\n\n    \$pdo->exec(<<<'SQL'\n    CREATE TABLE IF NOT EXISTS login_sessions (";
$new = "SQL);

// entries.type CHECK 約束擴充：加入 'transfer' 類型（轉帳）
// SQLite 不支援直接修改 CHECK 約束，需透過表重建方式進行 migration。
// 必須加入冪等性守衛，因為 ensureSchema() 在每次 getPdo() 都會執行.
\$row = \$pdo->query(\"SELECT sql FROM sqlite_master WHERE type='table' AND name='entries'\")->fetch();
\$currentSql = \$row === false ? '' : (string) (\$row['sql'] ?? '');
if (!str_contains(\$currentSql, \"'transfer'\")) {
    \$pdo->exec('BEGIN IMMEDIATE');
    try {
        \$pdo->exec(<<<'SQL'
DROP TABLE IF EXISTS entries_temp;
SQL);
        \$pdo->exec(<<<'SQL'
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
    FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
    FOREIGN KEY (user_id)   REFERENCES users(id)
);
SQL);
        \$pdo->exec('INSERT INTO entries_temp SELECT * FROM entries');
        \$pdo->exec('DROP TABLE entries');
        \$pdo->exec('ALTER TABLE entries_temp RENAME TO entries');
        \$pdo->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS idx_entries_ledger_id ON entries(ledger_id);
CREATE INDEX IF NOT EXISTS idx_entries_user_id ON entries(user_id);
CREATE INDEX IF NOT EXISTS idx_entries_entry_date_deleted ON entries(entry_date, deleted);
CREATE INDEX IF NOT EXISTS idx_entries_type ON entries(type);
SQL);
        \$pdo->exec('COMMIT');
    } catch (Throwable \$e) {
        \$pdo->exec('ROLLBACK');
        logError('entries.type migration failed: ' . \$e->getMessage());
    }
}

    \$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS login_sessions (";

if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    file_put_contents('db.php', $content);
    echo "SUCCESS\n";
} else {
    echo "FAILED: marker not found\n";
    // Show what's around SQL);
    $pos = strpos($content, 'SQL);');
    if ($pos !== false) {
        echo "Found SQL); at pos $pos\n";
        echo "Next 100 chars: [" . substr($content, $pos, 100) . "]\n";
    }
}
