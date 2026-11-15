# 全家人記帳系統 — Task 4 最終交付說明

## 1. 驗收清單 (Acceptance checklist)

| [ ] | # | 接受標準 | 實作方式（對應檔案） |
|---|---|---|---|
| [ ] | 1 | 以 `.env` 管理員帳號登入 | `config.php` 從 `.env` 讀取 `ADMIN_USERNAME` / `ADMIN_PASSWORD`；`db.php` `seedAdmin()` 在 `users` 表不存在同名帳號時，以 `password_hash()` 寫入種子管理員；`login.php` 以預備陳述式查詢 `users` 並以 `password_verify()` 驗證，登入成功後建立 session 與 `csrf_token`。 |
| [ ] | 2 | 一般使用者只能看到被授權的帳本 | `ledgers.php` 與 `ledger.php` 皆以 `INNER JOIN ledger_access ... AND la.user_id = :user_id`（預備陳述式）過濾，未授權的帳本不會列出，也無法直接進入。 |
| [ ] | 3 | 管理員可建立共同帳本並指定特定使用者 | `admin/ledgers.php` `create_ledger` 動作建立帳本並自動給擁有者 `owner` 權限；`add_access` 動作可將特定使用者加入指定帳本，身份為 `owner` / `member` / `viewer`，所有寫入皆為預備陳述式 + CSRF。 |
| [ ] | 4 | 金額以整數分儲存，不使用 FLOAT | `entries.amount_cents` 定義為 `INTEGER NOT NULL`；`functions.php` `toCents()` 解析為整數分、`formatCents()` 顯示時除以 100；`add_entry.php` 僅處理整數分，全程無 `FLOAT`。 |
| [ ] | 5 | 直接下載 `.db` 回傳 403 | `private_data/.htaccess` 以 `Require all denied`（Apache 2.2 相容 `Deny from all`）封鎖整個 `private_data/` 目錄，涵蓋 `cookies_xxxx.db`、`.db-wal`、`.db-shm`。 |
| [ ] | 6 | 未登入者看不到任何內頁 | `index.php`、`ledgers.php`、`ledger.php`、`add_entry.php`、`delete_entry.php`、`dashboard.php`、`admin/*` 均在最開頭呼叫 `requireLogin()` / `requireAdmin()`，未登入一律導向 `./login.php`。 |
| [ ] | 7 | 所有寫入表單皆有 CSRF | `add_entry.php`、`delete_entry.php`、`admin/users.php`（建立/重設密碼/變更角色/刪除使用者）、`admin/ledgers.php`（建立帳本/新增存取/移除存取）每張表單皆有隱藏 `csrf_token` 欄位；POST 時以 `checkCsrf()`（內部 `hash_equals()` 常數時間比較）驗證，失敗則拒絕。 |
| [ ] | 8 | 手機新增記錄在 10 秒內完成 | `add_entry.php` 為純服務端輕量表單，無框架、無 Node.js、無大型前端庫，頁面極輕，在一般行動網路下可於 10 秒內載入與提交（實際耗時視 cPanel 主機回應與網路狀況）。 |
| [ ] | 9 | `pdo_sqlite` 缺失時顯示明確錯誤而非空白頁 | `db.php` `getPdo()` 在 `extension_loaded('pdo_sqlite')` 為 false 時拋出含中英文說明的 `RuntimeException`；PDO 亦設定 `ATTR_ERRMODE_EXCEPTION`，錯誤不會靜默。（註：若要在瀏覽器看到例外訊息，需在 cPanel 的 MultiPHP INI Editor（或 `php.ini`）開啟 `display_errors`，或檢查錯誤日誌。） |

## 2. cPanel 部署步驟

1. **ZIP 打包上傳**：將專案資料夾全部檔案壓縮成 ZIP（**不包含含真實密碼的 `.env`**；僅帶上 `.env.example`，部署後再於主機上建立 `.env`）。
2. **上傳並解壓**：在 cPanel → 檔案管理員中，進入 `public_html/`，上傳 ZIP 到 `public_html/CookiesAccounting/` 並解壓。
3. **PHP 版本**：cPanel → MultiPHP 管理員（或「選擇 PHP 版本」），選擇 **PHP 8.1+**。
4. **擴充功能**：確認已啟用 **pdo_sqlite** 擴充（缺失時 `getPdo()` 會拋出 `RuntimeException`，系統無法運作）。
5. **寫入權限**：將 `private_data/` 設為網頁伺服器可寫入（建議 `0770` 或 `0775`），以便 SQLite 寫入 `.db`、`.db-wal`、`.db-shm`；`private_data/.htaccess` 已阻擋瀏覽器直接存取該目錄。
6. **建立 `.env`**：複製 `.env.example` 為 `.env`，並將 `ADMIN_PASSWORD` 設為強密碼（必要時也可調整 `ADMIN_USERNAME`）。
7. **驗證防護**：以瀏覽器直接訪問 `https://你的網域/CookiesAccounting/.env` 與 `https://你的網域/CookiesAccounting/private_data/`，應分別回傳 **403**。
8. **驗證登入**：開啟 `https://你的網域/CookiesAccounting/`，應導向登入頁；以 `.env` 管理員帳密登入後應進入儀表板，且管理員能看到「後台：使用者管理」與「後台：帳本管理」連結。
9. **驗證一般使用者隔離**：管理員於後台建立成員使用者與帳本，並授權存取後，該成員僅能看到被授權的帳本，看不到未授權帳本。
10. **備份（下載）**：在 cPanel 檔案管理員中，下載 `private_data/cookies_xxxx.db` 與 `private_data/cookies_xxxx.db-wal`（WAL 檔含尚未 checkpoint 的寫入；兩者合併才是完整備份，有 `-wal` 時務必一起下載；資料庫閒置時 `-wal` 可能不存在，該情況下 `.db` 單檔即可）。

## 3. 檔案清單 (File inventory)

專案根目錄 `C:\tempDevelopmenyEnviroment\CookiesAccounting` 以下共有 19 個檔案：

**根目錄：**
- `.env` — 生產環境秘密（含真實 `ADMIN_PASSWORD`，不得隨 ZIP 上傳，以 `.env.example` 代替）
- `.env.example` (新) — 環境變數範本，不含真實密碼
- `.htaccess` — 關閉目錄列舉，以 `<Files ".env">` 封鎖 `.env` 直接存取
- `FINAL_NOTES.md` (新) — 本驗收與部署說明
- `add_entry.php` — 新增記帳記錄（CSRF、整數分、預備陳述式、帳本權限檢查）
- `config.php` — 載入 `.env`、定義 `ADMIN_*` / `DB_PATH`、Session 設定（httponly / samesite）
- `dashboard.php` — 登入後儀表板（`role === admin` 時顯示後台連結）
- `db.php` — `getPdo()`：`pdo_sqlite` 檢查、WAL、`busy_timeout=5000`、自動建表、種子管理員
- `delete_entry.php` — 作廢記錄（POST-only、CSRF、預備陳述式、帳本權限檢查）
- `functions.php` — `h()`、`redirect()`、`requireLogin()`、`requireAdmin()`、`csrfToken()`、`checkCsrf()`、`toCents()`、`formatCents()`、`isValidDate()`
- `index.php` — 前端路由（未登入導 `login.php`，已登入導 `dashboard.php`）
- `ledger.php` — 帳本詳情與本月收支彙整（授權檢查）
- `ledgers.php` — 帳本列表（僅顯示該使用者有存取權的帳本）
- `login.php` — 登入頁（`password_verify`，登入成功產生 session 與 `csrf_token`）
- `logout.php` — 登出（清除 session 與 cookie）
- `NOTES.md` — 舊版 Task 4 附註（參照舊版檔名 `delete.php`/`list.php`/`overview.php`/`add.php`，與本專案實際檔案不符，已由 `FINAL_NOTES.md` 取代）
- `TASK3_NOTES.md` — Task 3 後台管理說明

**`admin/`：**
- `admin/users.php` — 後台使用者管理（僅管理員：建立/重設密碼/變更角色/刪除使用者，皆 CSRF + 預備陳述式）
- `admin/ledgers.php` — 後台共同帳本管理（僅管理員：建立帳本、新增/更新/移除存取，皆 CSRF + 預備陳述式）

**`assets/`：**
- `assets/style.css` — 樣式表

**`private_data/`：**
- `private_data/.htaccess` — 封鎖整個私密資料目錄的直接瀏覽器存取（403）

**註：** 私下資料庫 `cookies_xxxx.db` 於首次執行由 `db.php` 自動建立，不納交付檔案；備份時連同 `.db-wal` 一起下載。

## 4. 安全說明 (Security notes)

- **`.env` 不得可由網頁直接存取**：根目錄 `.htaccess` 已以 `<Files ".env"> Require all denied`（Apache 2.2 相容 `Deny from all`）封鎖；部署後請以瀏覽器直接訪問確認回傳 403。
- **Session Cookie**：`config.php` 設定 `session.cookie_httponly=1`、`session.use_only_cookies=1`、`session.cookie_samesite=Lax`；登入成功會 `session_regenerate_id(true)` 防禦 session fixation。
- **密碼雜湊**：所有密碼以 `password_hash($password, PASSWORD_DEFAULT)` 儲存，登入以 `password_verify()` 比對；絕無純文字密碼寫入資料庫或輸出。
- **SQL 注入防護**：全站僅使用 PDO + 預備陳述式（具名佔位符），`db.php` 設定 `ATTR_EMULATE_PREPARES=false`，不拼字串 SQL。
- **金額安全**：`entries.amount_cents` 為 `INTEGER`（整數分），顯示時除以 100，全程無 `FLOAT`。
- **目錄防護**：`private_data/.htaccess` 封鎖整個目錄，直接下載 `.db` / `.db-wal` / `.db-shm` 皆回傳 403。
- **CSRF**：所有狀態改寫表單（新增/刪除記錄、建立/編輯使用者與帳本、存取權限變更）皆含 `csrf_token` 並以 `hash_equals()` 常數時間比較驗證。

## 5. 其他檔案變更確認

已檢視專案全部 PHP 檔案（`config.php`、`db.php`、`functions.php`、`index.php`、`login.php`、`logout.php`、`dashboard.php`、`ledgers.php`、`ledger.php`、`add_entry.php`、`delete_entry.php`、`admin/users.php`、`admin/ledgers.php`），各項硬性約束（PDO + 預備陳述式、WAL + `busy_timeout=5000`、整數分金額、`.env` 管理員帳號、Session 登入、相對路徑 `./`、私密資料庫路徑）皆已滿足。**本次交付僅新增 `.env.example` 與 `FINAL_NOTES.md`，不需要修改任何現有 PHP 檔案。**
