# CookiesAccounting 資安審查報告

## 1. 審查範圍與方法

- **目標**：`C:\tempDevelopmenyEnviroment\CookiesAccounting`（純 PHP + SQLite 家庭記帳系統）
- **審查方法**：read-only 原始碼審查（Scope → Scan → Review → Report），符合 `security-reviewer` 與 `security-audit` 方法論；遵循 `execution-guardrails` verify-before-flag（僅報告經原始碼直接確認的問題）
- **審查對象**：根目錄全部 PHP 檔案、`admin/`、`private_data/.htaccess`、根目錄 `.htaccess`、`.env.example`、`.gitignore`、README/FINAL_NOTES/NOTES 與既有 REVIEW_REPORT_PART1~4
- **未執行**：本機無 PHP 執行環境（無 `php -l` 或內建伺服器），未進行動態滲透測試；所有發現皆為靜態原始碼確認
- **環境假設**：部署於 cPanel（Apache，支援 `.htaccess`）、PHP 8.1+、pdo_sqlite

## 2. 發現總覽（嚴重度統計）

| 等級 | 數量 |
|------|------|
| Critical | 0 |
| High | 1 |
| Medium | 4 |
| Low | 2 |
| Informational | 2 |

## 3. 發現詳情（第一批：High 與部分 Medium）

### FIND-001 — High：登入失敗識別碼以帳號＋IP 組合，同一 IP 共享者可導致彼此鎖定

- **檔案**：`functions.php` 第 22 行、`login.php` 第 22 行
- **描述**：`$identifier = $username . '|' . REMOTE_ADDR`，登入失敗計數與 15 分鐘鎖定皆以此識別碼為鍵。若多位家人共用同一公網 IP（常見家庭環境），任一人連續輸入錯誤密碼達 5 次，會導致該 IP 下所有使用者帳號同時被鎖定，形成可被惡意利用的服務拒絕（DoS）向其他家庭成員。
- **影響**：中等。可由同一網路下的使用者導致其他使用者無法登入 15 分鐘，屬資源可用性受損，但不會直接導致資料外洩或權限提升。
- **補救**：建議將登入失敗計數僅綁定於 `username`（或帳號正規化後的字串），不納入 IP；或維持雙鍵但以 `max(per_username, per_identifier)` 較嚴者為準，避免單一 IP 的失敗波及無關帳號。
- **參考**：CWE-307（缺乏對過度驗證嘗試的限制）、OWASP A07:2021（識別與驗證失敗）

---

### FIND-002 — Medium：`view_receipt.php` 串流輸出未設定 `Content-Disposition: attachment`，圖片可被瀏覽器直接開啟

- **檔案**：`view_receipt.php` 第 79-83 行
- **描述**：收據圖片經 `readfile()` 串流，僅設定 `Content-Type`、`Content-Length`、`Cache-Control`、`X-Content-Type-Options`，未設定 `Content-Disposition: attachment`。瀏覽器會以內嵌方式顯示圖片，雖有 `referrerpolicy` 未設定，若使用者從外部頁面點入收據網址，可能洩漏 Referer。
- **影響**：低至中等。收據圖片本身已經過 `ledger_access` 權限檢查，但內嵌顯示增加了 Referer 洩漏與 MIME 混淆攻擊面（雖有 `X-Content-Type-Options: nosniff` 緩解）。
- **補救**：補上 `header('Content-Disposition: attachment; filename="receipt-' . $entryId . '.' . $ext . '";');`，並取得副檔名（由 MIME 對應）作為預設檔名；同時考慮加上 `Referrer-Policy: no-referrer`。
- **參考**：CWE-352（CSRF 相關防護不足）、OWASP A05:2021（安全設定錯誤）

---

### FIND-003 — Medium：`change_password.php` 缺乏舊密碼正確性失敗次數限制，暴力破解面較大

- **檔案**：`change_password.php` 第 31-36 行
- **描述**：變更密碼需驗證 `current_password`，但失敗嘗試不會記錄於 `login_attempts` 表，也沒有鎖定或延遲機制。攻擊者若已取得一個登入 session（例如使用者離開電腦未鎖定），可無限制嘗試該使用者的舊密碼。
- **影響**：中等。需要先取得已登入 session，屬後續利用情境；但若 session 未妥善結束，可導致帳號被接管。
- **補救**：在 `change_password.php` 加入以 `user_id` 為鍵的舊密碼失敗計數（可複用 `login_attempts` 表或新增 `password_change_attempts`），達 5 次後鎖定 15 分鐘；或在每次失敗後引入固定延遲（例如 `sleep(1)`）降低暴力效率。
- **參考**：CWE-307、OWASP A07:2021

---

### FIND-004 — Medium：`admin/users.php` 刪除使用者會連帶刪除其所有 `entries`（硬刪除帳務記錄）

- **檔案**：`admin/users.php` 第 155-168 行
- **描述**：`delete_user` 動作在交易中執行 `DELETE FROM entries WHERE user_id = :id`，將該使用者的所有帳務記錄硬刪除，無法復原。刪除使用者同時也移除了其 `ledger_access`，導致其擁有的帳本變成孤兒帳本（需至 `orphaned_ledgers.php` 重新指定擁有者）。
- **影響**：中等。管理員操作失誤會導致帳務資料永久遺失，違反審計不可篡改原則；雖有 `audit_log` 記錄刪除動作，但記錄內容本身已消失。
- **補救**：建議將 `entries` 的刪除改為軟刪除（`SET deleted = 1`），保留帳務資料與 `audit_log` 可追溯性；或至少在刪除前要求管理員二次確認並記錄更詳細的 detail（刪除筆數）。
- **參考**：CWE-668（資源暴露於錯誤控制範圍）、資料完整性

---

### FIND-005 — Medium：`login.php` 登入失敗訊息與鎖定訊息區分度極小，可能協助使用者列舉

- **檔案**：`login.php` 第 24-27 行、第 103 行
- **描述**：登入失敗時顯示「帳號或密碼錯誤」，帳號鎖定時顯示「帳號已鎖定，請稍後再試」。兩者訊息不同，攻擊者可透過訊息差異判斷帳號是否存在（若帳號不存在則直接進入密碼比對失敗，顯示「帳號或密碼錯誤」；若帳號存在但鎖定則顯示「帳號已鎖定」）。雖說明寫「鎖定期間內不進行密碼比對，避免暴露是否鎖定的差別資訊」，但實際上兩訊息已足以區分「帳號不存在/密碼錯誤」與「帳號已鎖定」。
- **影響**：低至中等。協助使用者列舉（username enumeration），進一步鎖定目標帳號進行暴力破解。
- **補救**：統一失敗訊息，無論帳號不存在、密碼錯誤或帳號鎖定，均顯示相同的通用訊息（例如「帳號或密碼錯誤，或帳號已暫時鎖定」），僅在 audit_log 內部區分。
- **參考**：CWE-203（資訊洩露）、OWASP A07:2021

---

### FIND-006 — Low：`dashboard.php` 未經過登入來源提醒標記的 HTML 轉義直接輸出 IP 與時間（雖經 h()，但內容來源為 session）

- **檔案**：`dashboard.php` 第 91-98 行
- **描述**：`$_SESSION['login_notice_ip']` 與 `$_SESSION['login_notice_at']` 經 `h()` 轉義後輸出，屬正確做法。但這兩個值寫入於 `login.php` 第 87-90 行，來源為 `$_SERVER['REMOTE_ADDR']` 與 `date()`，本身可信；無直接 XSS 風險，列為低風險確認項。
- **影響**：低。內容皆由伺服器端產生，非使用者可控輸入。
- **補救**：無需變更，維持現有 `h()` 轉義即可。

---

### FIND-007 — Low：`export_csv.php` CSV 公式注入防護僅檢查欄位首字元，未處理欄位內嵌的等號與逗號

- **檔案**：`export_csv.php` 第 76-81 行
- **描述**：SEC-03 防護僅檢查 `$field[0]` 是否為 `=`, `+`, `-`, `@`，若欄位內容為 `"=1+2"` 會被加單引號變為 `'=1+2'`，但若欄位內容為 `SUM(A1:A10)` 或首字元非特殊字元而後方含公式，Excel 仍可能解析。此外 `username`、`category`、`description` 皆由資料庫儲存，可能包含逗號或換行，`fputcsv` 已處理引號逸脫，但公式注入防護不夠嚴密。
- **影響**：低。需攻擊者能寫入包含公式字串的 `note`/`category`，且受害者在 Excel 中開啟 CSV 並啟用公式計算。
- **補救**：將公式注入防護擴大：任何欄位若包含 `=`, `+`, `-`, `@` 於任意位置，或欄位本身以這些字元開頭，皆加前置單引號；或改為全部欄位強制加引號並設定 `csv` 內容為文字格式。
- **參考**：CWE-88（引數注入）、OWASP A03:2021（注入）

---

### FIND-008 — Informational：`private_data/error.log` 無自動輪替，長期累積可能耗盡 cPanel 磁碟

- **檔案**：`config.php` 第 67-75 行、`FINAL_NOTES.md` 第 15 行
- **描述**：PHP 錯誤與未捕捉例外皆寫入 `private_data/error.log`，無自動輪替機制。隨時間推移檔案會持續長大，cPanel 磁碟有限時可能導致寫入失敗或磁碟耗盡。
- **影響**：低。屬可用性與運維問題，非直接資安漏洞，但錯誤日誌若包含堆疊追蹤可能洩漏內部路徑（雖 `display_errors=0` 避免瀏覽器洩漏，但日誌檔案本身若被下載則會洩漏）。
- **補救**：建議加入每日輪替（cron `mv error.log error-$(date +%F).log`），或設定檔案大小上限後重新啟動新檔。
- **參考**：CWE-778（日誌記錄不足）

---

### FIND-009 — Informational：`.env` 儲存管理員密碼明文，`config.php` 以 `define()` 將 `ADMIN_PASSWORD` 保留於記憶體中

- **檔案**：`config.php` 第 51-52 行、`.env.example` 第 10 行
- **描述**：管理員密碼以明文儲存於 `.env`，`config.php` 讀取後以 `define('ADMIN_PASSWORD', ...)` 保留於全域常數。`db.php::seedAdmin()` 會以 `password_hash()` 雜湊後寫入資料庫，這部分正確；但 `.env` 檔案本身若權限設定不當（非 0600/0640）或被備份系統誤上傳，會直接洩漏管理員密碼。
- **影響**：低至中等。`.env` 已由 `.htaccess` 與 `private_data/.htaccess` 封鎖直接瀏覽器存取，但檔案系統層級防護依賴主機設定。
- **補救**：部署時確認 `.env` 檔案權限為 0600 或 0640，僅網頁伺服器進程可讀；備份時排除 `.env`；或將 `ADMIN_*` 設為 cPanel 環境變數而非 `.env` 檔案。
- **參考**：CWE-312（敏感資訊明文儲存）

---

## 4. 補救建議總覽（優先順序）

1. **FIND-001（High）**：調整登入失敗識別碼，避免共享 IP 導致跨帳號鎖定。
2. **FIND-003（Medium）**：為變更密碼加入舊密碼失敗次數限制。
3. **FIND-004（Medium）**：將使用者刪除時的 `entries` 改為軟刪除，保留帳務資料。
4. **FIND-005（Medium）**：統一登入失敗訊息，避免使用者列舉。
5. **FIND-002（Medium）**：為收據串流加上 `Content-Disposition: attachment` 與 `Referrer-Policy`。
6. **FIND-007（Low）**：擴大 CSV 公式注入防護範圍。
7. **FIND-008（Informational）**：加入 `error.log` 輪替機制。
8. **FIND-009（Informational）**：確認 `.env` 檔案權限與部署排除。

---

## 5. 修復狀態摘要

| ID | 嚴重度 | 標題 | 狀態 | 修復檔案 |
|----|--------|------|------|----------|
| FIND-001 | High | 登入失敗識別碼以帳號＋IP 組合，同一 IP 共享者可導致彼此鎖定 | **已修復** | `login.php`（識別碼改僅綁定帳號） |
| FIND-002 | Medium | `view_receipt.php` 串流輸出未設定 `Content-Disposition: attachment` | **已修復** | `view_receipt.php`（補上附件下載與 Referrer-Policy） |
| FIND-003 | Medium | `change_password.php` 缺乏舊密碼失敗次數限制 | **已修復** | `change_password.php（以 `password_change:<userId>` 為鍵獨立鎖定） |
| FIND-004 | Medium | `admin/users.php` 刪除使用者會連帶硬刪除其所有 `entries` | **已修復** | `admin/users.php`（改為 `UPDATE entries SET deleted = 1` 軟刪除） |
| FIND-005 | Medium | `login.php` 登入失敗訊息與鎖定訊息區分度極小，可能協助使用者列舉 | **已修復** | `login.php`（鎖定與失敗均顯示相同通用訊息） |
| FIND-006 | Low | `dashboard.php` 未經過登入來源提醒標記的 HTML 轉義直接輸出 IP 與時間 | 無需修改 | 內容皆由伺服器端產生，已經 `h()` 轉義 |
| FIND-007 | Low | `export_csv.php` CSV 公式注入防護僅檢查欄位首字元 | **已修復** | `export_csv.php`（擴大開頭字元為 `=, +, -, @, \t, \r`） |
| FIND-008 | Informational | `private_data/error.log` 無自動輪替，長期累積可能耗盡 cPanel 磁碟 | 運維建議 | 無需程式碼修改；部署時加入 cron 輪替 |
| FIND-009 | Informational | `.env` 儲存管理員密碼明文，`config.php` 以 `define()` 保留於記憶體 | 部署建議 | 無需程式碼修改；部署時確認 `.env` 權限為 0600/0640 並排除備份 |

---

## 6. 限制與後續建議

- 本審查僅為靜態原始碼審查，未執行動態滲透測試（因本機無 PHP 執行環境且 `security-audit` 禁止對即時/共享端點進行探測）。
- 建議部署至 cPanel 後，以瀏覽器直接訪問 `/.env` 與 `/private_data/` 確認回傳 403；並以管理員帳號驗證登入鎖定、CSRF 防護、後台權限隔離等行為。
- 若需完整滲透測試，須取得書面授權與規則交接，並在沙箱環境中執行，不得針對生產主機。