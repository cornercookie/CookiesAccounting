# 全家人記帳系統

純 PHP + SQLite 的家庭共同帳本。多人、多帳本、分級權限（owner / member / viewer），金額以整數分儲存。部署目標是 cPanel 主機（PHP 8.1+、`pdo_sqlite`），不依賴 Composer 或 Node.js。

## 不會進這個 repo 的東西

- `.env`：真實管理員帳號與密碼。請從 [`.env.example`](.env.example) 複製後在主機上自行建立。
- `private_data/*.db`（含 `-wal` / `-shm`）：全家的記帳資料。首次執行會自動建立，請自行備份，不要提交。
- `.codex/`、`.cursor/`、`.gemini/`、`.impeccable/`：本機編輯器與設計審查產物。

`private_data/.htaccess` 會一併封鎖瀏覽器直接下載資料庫，請保留。

## 日誌

- **錯誤 Log**：PHP 錯誤與未捕捉例外寫入 `private_data/error.log`（僅主機可讀，`display_errors` 已關閉，瀏覽器只會看到通用錯誤頁）。無自動輪替，請定期備份後手動清理。
- **操作審計 Log**：登入／登出、新增／作廢記錄、密碼變更與所有後台管理操作寫入 `audit_log` 資料表；管理員可在「操作紀錄」頁（`admin/audit_log.php`）檢視，唯讀、不可從網頁刪除。詳細見 `docs/TASK5_NOTES.md`。

## 部署

1. 上傳程式碼到 `public_html/CookiesAccounting/`（不要上傳 `.env` 或既有 `.db`）。
2. PHP 設為 8.1+，並啟用 `pdo_sqlite`。
3. `private_data/` 設為網頁伺服器可寫（`0770` 或 `0775`）。
4. 複製 `.env.example` 為 `.env`，把 `ADMIN_USERNAME` 與 `ADMIN_PASSWORD` 替換為您自行設定的強密碼，嚴禁沿用範本佔位符值（`YOUR_STRONG_PASSWORD_HERE`）。
5. 確認直接開啟 `/.env` 與 `/private_data/` 都回傳 403。
6. 確認主機已啟用 HTTPS（Session Cookie 設為 `secure`，若非 HTTPS 將無法傳送登入 Cookie）。

部署細節見 `docs/FINAL_NOTES.md`。

## 資安全防護

本系統已針對以下風險做防護，詳細審查報告請見 [`docs/SECURITY_SCAN_REPORT.md`](docs/SECURITY_SCAN_REPORT.md)。

### 已實作的防護機制

- **SQL Injection**：所有查詢皆使用 PDO Prepared Statements，未發現字串拼接 SQL。
- **XSS**：統一使用 `h()`（`htmlspecialchars` `ENT_QUOTES \| ENT_SUBSTITUTE`，UTF-8）進行輸出轉義。
- **CSRF**：所有狀態變更 POST 請求皆有 CSRF Token，並以 `hash_equals()` 恆時比較。
- **Session**：`HttpOnly`、`SameSite=Lax`、`secure`（需 HTTPS）、登入後 `session_regenerate_id(true)` 防 fixation。
- **登入防護**：`password_hash`/`password_verify`（bcrypt）；登入失敗 5 次鎖定 15 分鐘（綁定「帳號\|IP」）；密碼強度需 ≥10 字元且含 3 類字符。
- **檔案上傳**：MIME 雙重驗證 (`finfo_file`)、亂數重新命名、5MB 上限、`chmod 0640`，且 `private_data/uploads/` 已以 `.htaccess` 封鎖直接存取。
- **收據串流**：`view_receipt.php` 執行 IDOR 權限檢查、路徑遍歷防護、`Content-Disposition: attachment`、`X-Content-Type-Options: nosniff`。
- **安全回應標頭**：CSP（`script-src 'self'`，已移除 `'unsafe-inline'`）、`X-Frame-Options: DENY`、`Referrer-Policy: strict-origin-when-cross-origin`。
- **錯誤不外洩**：`display_errors=0`，堆疊僅寫入 `private_data/error.log`，瀏覽器僅看到通用錯誤頁。
- **審計日誌**：`audit_log` 僅新增不刪除，僅管理員可見，無網頁刪除入口。

### 已修復的資安發現（SEC-002 ~ SEC-005）

| 編號 | 說明 | 修復方式 |
|------|------|----------|
| SEC-002 | CSP 含 `'unsafe-inline'`，降低 XSS 防護 | 內聯 `<script>` 抽離至 `assets/app.js`、`assets/ai-parse.js`；CSP 改為 `script-src 'self'` |
| SEC-003 | 登入速率限制僅綁定帳號，可被換 IP 繞過 | 識別碼改為「帳號\|IP」，同一公網 IP 下不同帳號互不影響 |
| SEC-004 | 登入來源異常比對僅以 IP 判斷，準確性受限 | 比對條件加入 `User-Agent`，同 IP 不同裝置也會觸發提醒 |
| SEC-005 | `.env` 可能仍為預設佔位符 | `config.php` 偵測預設密碼/金鑰值，寫入 `error.log` 警告 |

### 部署前務必自行確認

1. 將 `.env` 中的 `ADMIN_PASSWORD` 替換為強密碼（建議 ≥12 字元，含英數字與符號），嚴禁沿用 `.env.example` 的佔位符值。
2. 確認部署主機已啟用 HTTPS，否則 Session Cookie 無法傳送而導致登入失敗。
3. 確認 `.env` 未加入版控（已由 `.gitignore` 排除）。
4. 在測試主機執行 `php -l` 檢查所有 `.php` 語法。
