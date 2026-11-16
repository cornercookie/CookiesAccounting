# 全家人記帳系統 — Task 5 操作審計 Log + 錯誤 Log

## 1. 新增/變更檔案

| 檔案 | 說明 |
|------|------|
| `TASK5_NOTES.md` | 本說明 |
| `config.php` (改) | 新增 `ERROR_LOG_PATH`（`private_data/error.log`）、`log_errors=1`、`error_log` 導向該檔、`display_errors=0`；並註冊全域例外處理器：未捕捉例外一律寫入 `error.log`，瀏覽器只看到通用錯誤頁（HTTP 500），不外洩路徑／SQL／堆疊 |
| `db.php` (改) | `ensureSchema()` 新增 `audit_log` 表（含 3 個索引）；既有資料庫首次執行時自動建立，不需手動 migration |
| `functions.php` (改) | 新增 `logError()`（明確錯誤點寫入 `error.log`）與 `logAudit()`（寫入 `audit_log` 表；user/ip 自動取自 session 與 REMOTE_ADDR；失敗只改寫 `error.log`，不中斷主流程） |
| `admin/audit_log.php` (新) | 後台「操作紀錄」頁：僅管理員可存取、唯讀；支援關鍵字搜尋（帳號／動作／細節，LIKE 萬用字元已逃脫）與分頁（50 筆／頁） |
| `login.php` (改) | 記錄 `login` / `login_failed` / `login_locked` |
| `logout.php` (改) | 記錄 `logout`（於清除 session 前） |
| `add_entry.php` (改) | 記錄 `entry_create`（entry #、帳本、收支、金額） |
| `delete_entry.php` (改) | 記錄 `entry_delete`（僅軟刪除成功時） |
| `change_password.php` (改) | 記錄 `password_change` |
| `admin/users.php` (改) | 記錄 `admin_user_create` / `admin_user_reset_password` / `admin_user_change_role` / `admin_user_delete`；刪除失敗改寫 `error.log` |
| `admin/ledgers.php` (改) | 記錄 `admin_ledger_create` / `admin_ledger_archive` / `admin_ledger_access_set`（標註新增或更新）/ `admin_ledger_access_remove`；建立失敗改寫 `error.log` |
| `admin/archived_ledgers.php` (改) | 還原封存帳本記錄 `ledger_restore` |
| `archived_ledgers.php` (改) | 擁有者還原封存帳本記錄 `ledger_restore` |
| `admin/orphaned_ledgers.php` (改) | 記錄 `admin_ledger_reassign_owner`；重新指定失敗改寫 `error.log` |
| `dashboard.php` / `ledgers.php` / `ledger.php` / `add_entry.php` / `change_password.php` / `archived_ledgers.php` (改) | 側欄「後台」區塊新增「操作紀錄」連結；儀表板管理員快捷卡新增「操作紀錄 →」 |
| `admin/users.php` / `admin/ledgers.php` / `admin/archived_ledgers.php` / `admin/orphaned_ledgers.php` (改) | 側欄「後台」區塊新增「操作紀錄」連結 |

## 2. audit_log 資料表

```sql
CREATE TABLE IF NOT EXISTS audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,               -- 未登入/未知使用者為 NULL（例如登入失敗）
    username   TEXT    NOT NULL DEFAULT '',
    action     TEXT    NOT NULL,      -- 見下表
    detail     TEXT    NOT NULL DEFAULT '',
    ip         TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
-- idx_audit_log_created_at / idx_audit_log_user_id / idx_audit_log_action
```

### 動作代碼一覽

| action | 說明 | detail 內容 |
|--------|------|-------------|
| `login` | 登入成功 | （空） |
| `login_failed` | 登入失敗 | `identifier: <帳號\|IP>` |
| `login_locked` | 登入通道被鎖定時嘗試 | `identifier: <帳號\|IP>` |
| `logout` | 登出 | （空） |
| `entry_create` | 新增記帳 | `entry #<id> · ledger <帳本> · income\|expense · <金額>` |
| `entry_delete` | 作廢記帳 | `entry #<id> · ledger <帳本>` |
| `password_change` | 自助變更密碼 | （空） |
| `admin_user_create` | 後台建立使用者 | `user_id=… username=… role=…` |
| `admin_user_reset_password` | 後台重設密碼 | `target_user_id=… username=…` |
| `admin_user_change_role` | 後台變更角色 | `target_user_id=… username=… <舊>→<新>` |
| `admin_user_delete` | 後台刪除使用者 | `target_user_id=… username=…` |
| `admin_ledger_create` | 後台建立帳本 | `ledger_id=… name=… owner_id=…` |
| `admin_ledger_archive` | 後台封存帳本 | `ledger_id=…` |
| `ledger_restore` | 還原帳本（擁有者或管理員） | `ledger_id=…` |
| `admin_ledger_access_set` | 新增/更新帳本存取 | `ledger_id=… user_id=… role=… (新增\|更新)` |
| `admin_ledger_access_remove` | 移除帳本存取 | `ledger_id=… user_id=…` |
| `admin_ledger_reassign_owner` | 重新指定孤兒帳本擁有者 | `ledger_id=… new_owner_id=…` |

**刻意不記錄**：密碼、密碼雜湊、`note`（備註）內容。金額與類別會記錄，供事後對帳；若家人備註含有隱私內容，不會進入日誌。

## 3. 錯誤 Log（private_data/error.log）

- `config.php` 以 `ini_set` 將 PHP errors / warnings 與未捕捉例外導向 `private_data/error.log`；`display_errors=0`，錯誤細節不會出現在瀏覽器。
- `logError()` 供明確錯誤點使用（目前埋在三個後台交易失敗的 catch 中）。
- `error.log` 位於 `private_data/`，已被 `private_data/.htaccess` 封鎖直接下載；`*.log` 已被 `.gitignore` 忽略；兩者都不會在部署 ZIP 中誤帶出去（部署時請本來就不要打包 `private_data/` 內容）。
- **沒有自動輪替**：檔案會持續長大。cPanel 磁碟有限時，請定期以檔案管理員下載備份後手動清空或改名歸檔；或以 cron 執行 `mv error.log error-$(date +%F).log` 輪替（注意保留權限 0600/0640）。

## 4. 安全性與一致性

- 審計寫入失敗**不會中斷任何主流程**：`logAudit()` 內部 catch 所有 `Throwable`，改寫 `error.log` 後繼續。帳務操作永遠優先。
- 審計頁面 `admin/audit_log.php`：`requireAdmin()`、全部輸出 `h()` 轉義、LIKE 萬用字元（`%`、`_`、`\`）已逃脫、唯讀（無刪除入口）。
- `audit_log` 只新增不更新，保留「發生當下」的身份快照（`username` 冗餘於 `user_id`，使用者被刪除後紀錄仍在）。
- 例外處理器註冊於 `config.php`，全站所有入口（含後台）統一生效。

## 5. 維持的專案硬性約束

- `declare(strict_types=1)`；PDO + 預備陳述式（具名佔位符）、`ATTR_EMULATE_PREPARES=false`；新查詢均未重用佔位符名稱。
- 相對路徑 `./`（後台頁用 `../`）；金額仍為整數分（審計 detail 僅作顯示格式化，不參與帳務計算）。
- `private_data/` 仍被 `.htaccess` 封鎖；`*.log` 已在 `.gitignore`。

## 6. 本機未執行（重要）

本機沒有 PHP 執行環境（`php` 指令不存在），所有變更皆未以 `php -l` 或內建伺服器實測；已以人工逐行覆查配對與語法。部署後請驗證：

1. 開啟 `admin/audit_log.php`（需管理員），應看到列表頁（首次即可看到自己的登入紀錄）。
2. 執行一次新增/作廢記錄、變更密碼、後台建立使用者，各自應在「操作紀錄」出現對應 `entry_create` / `entry_delete` / `password_change` / `admin_user_create`。
3. 登出後重新登入，紀錄中應出現 `logout` 與 `login`。
4. 以錯誤密碼登入 1 次，應出現 `login_failed`；連錯 5 次後嘗試登入，應出現 `login_locked`。
5. 故意在主機觸發一個例外（例如暫時移走 pdo_sqlite），瀏覽器應看到通用錯誤頁，且 `private_data/error.log` 應有完整堆疊。
