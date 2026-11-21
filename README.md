# 全家人記帳系統

純 PHP + SQLite 的家庭共同帳本。多人、多帳本、分級權限（owner / member / viewer），金額以整數分儲存。部署目標是 cPanel 主機（PHP 8.1+、`pdo_sqlite`），不依賴 Composer 或 Node.js。

## 不會進這個 repo 的東西

- `.env`：真實管理員帳號與密碼。請從 [`.env.example`](.env.example) 複製後在主機上自行建立。
- `private_data/*.db`（含 `-wal` / `-shm`）：全家的記帳資料。首次執行會自動建立，請自行備份，不要提交。
- `.codex/`、`.cursor/`、`.gemini/`、`.impeccable/`：本機編輯器與設計審查產物。

`private_data/.htaccess` 會一併封鎖瀏覽器直接下載資料庫，請保留。

## 日誌

- **錯誤 Log**：PHP 錯誤與未捕捉例外寫入 `private_data/error.log`（僅主機可讀，`display_errors` 已關閉，瀏覽器只會看到通用錯誤頁）。無自動輪替，請定期備份後手動清理。
- **操作審計 Log**：登入／登出、新增／作廢記錄、密碼變更與所有後台管理操作寫入 `audit_log` 資料表；管理員可在「操作紀錄」頁（`admin/audit_log.php`）檢視，唯讀、不可從網頁刪除。詳細見 `TASK5_NOTES.md`。

## 部署

1. 上傳程式碼到 `public_html/CookiesAccounting/`（不要上傳 `.env` 或既有 `.db`）。
2. PHP 設為 8.1+，並啟用 `pdo_sqlite`。
3. `private_data/` 設為網頁伺服器可寫（`0770` 或 `0775`）。
4. 複製 `.env.example` 為 `.env`，把 `ADMIN_USERNAME` 與 `ADMIN_PASSWORD` 替換為您自行設定的強密碼，嚴禁沿用範本佔位符值（`YOUR_STRONG_PASSWORD_HERE`）。
5. 確認直接開啟 `/.env` 與 `/private_data/` 都回傳 403。

部署細節見 `FINAL_NOTES.md`。
