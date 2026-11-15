# 全家人記帳系統 — Task 4 交付說明

## 1. 驗收清單 (Acceptance checklist)

| # | PRD / Task 4 接受標準 | 如何滿足 |
|---|---|---|
| 1 | `delete.php` 僅接受 POST | `$_SERVER['REQUEST_METHOD'] !== 'POST'` 時直接導回 `./list.php?error=1` |
| 2 | `requireLogin()` 必須先通過 | `delete.php` 調用 `requireLogin()`；未登入導向 `./login.php` |
| 3 | 接收 `id` 參數 | `filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)`；非整數/缺失即錯誤 |
| 4 | 軟刪除用 prepared statement | `UPDATE entries SET deleted = 1 WHERE id = :id AND deleted = 0`，具名佔位符，不拼字串 SQL |
| 5 | 只作廢尚未刪除的記錄 | `AND deleted = 0`；`rowCount() === 0`（id 不存在或已作廢）視為失敗 |
| 6 | 操作後導回 `./list.php` 並帶成功旗標 | `redirect('./list.php?success=1')` / `?error=1`；全程相對路徑 |
| 7 | 成功/失敗訊息經 `$_GET` 顯示 | `list.php` 讀取 `$_GET['success']` / `$_GET['error']`，經 `h()` 轉義後輸出 |
| 8 | 所有輸出經 `h()` 轉義 | 訊息與備註皆用 `<?php echo h(...); ?>` 輸出 |
| 9 | 所有路徑為相對路徑 | `./list.php` 等；`redirect()` 內部也歸一化為 `./` 開頭 |
| 10 | `formatCents()` 只定義於 `functions.php` | 從 `overview.php` 與 `list.php` 刪除重複定義，兩頁皆呼叫共用函式 |
| 11 | `overview.php` 與 `list.php` 底部有備份註記 | 單句備註已加入兩頁，經 `h()` 轉義 |
| 12 | 專案硬性約束皆維持 | PDO + prepared statements 只；`PRAGMA journal_mode=WAL` + `busy_timeout=5000` (`db.php`)；金額存 INT 分（不含 FLOAT）；家庭密碼在 `config.php`；session 登入；`./` 相對路徑；DB 於 `private_data/cookies_xxxx.db` |

## 2. Hostinger 部署步驟

1. **打包上傳**：將專案資料夾內全部檔案（含 `delete.php`、`NOTES.md`、`private_data/`、`assets/`、`.htaccess`）壓縮成 ZIP。
2. **上傳並解壓**：在 Hostinger hPanel → 檔案管理員中，進入 `public_html/`，上傳 ZIP 到 `public_html/CookiesAccounting/` 並解壓；或直接上傳到 `public_html/CookiesAccounting/` 子目錄。
3. **確認檔案清單**：`config.php`, `db.php`, `functions.php`, `index.php`, `login.php`, `logout.php`, `overview.php`, `add.php`, `list.php`, `delete.php`, `.htaccess`, `private_data/.htaccess`, `assets/style.css`，以及既有資料庫 `private_data/cookies_xxxx.db`（遷移舊資料時攜上）。
4. **PHP 版本**：hPanel → PHP 版本管理，選擇 **PHP 8.1+**。
5. **擴充功能**：確認已啟用 **pdo_sqlite** 擴充（缺失時 `getPdo()` 會拋出 `RuntimeException`，頁面無法運作）。
6. **寫入權限**：將 `private_data/` 設為可由網頁伺服器寫入（建議 `0770`/`0775`），以便 SQLite 寫入 `.db`、`.db-wal`、`.db-shm`；`private_data/.htaccess` 已經阻擋瀏覽器直接存取該目錄。
7. **預設密碼**：部署前請修改 `config.php` 頂端的 `$FAMILY_PASSWORD`（預設 `ChangeMePlease`）。
8. **驗證**：開啟 `https://你的網域/CookiesAccounting/`，應導向登入頁；登入後可進入總覽與記錄列表。
9. **備份（下載）**：在 Hostinger 檔案管理員中，下載 `private_data/cookies_xxxx.db` 與 `private_data/cookies_xxxx.db-wal`（WAL 檔含尚未 checkpoint 的寫入；兩者合併才是完整備份）。資料庫閒置時 `-wal` 可能不存在或為空，該情況下 `.db` 單檔即可，有 `-wal` 時務必一起下載。

## 3. 新增/變更檔案

- `delete.php` (新)：POST-only 作廢頁面
- `functions.php` (改)：新增 `formatCents()`，供 `overview.php` 與 `list.php` 共用
- `overview.php` (改)：移除重複 `formatCents()`；加入備份註記
- `list.php` (改)：移除重複 `formatCents()`；顯示 `$_GET` 作廢成功/失敗訊息；加入備份註記
- `NOTES.md` (新)：本文件
