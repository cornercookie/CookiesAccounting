# CookiesAccounting 資安審查報告（第二輪）

## 文件基本資訊

| 欄位 | 值 |
|------|-----|
| 檔案 | `docs/SECURITY_REVIEW_20261121.md` |
| 審查日期 | 2026-11-21 |
| 審查方法 | 靜態原始碼審查（SAST、人工複核），符合 `security-reviewer` 方法論（Scope → Scan → Review → Report） |
| 審查範圍 | 根目錄全部 PHP 檔案、`admin/`、`private_data/.htaccess`、根目錄 `.htaccess`、`.env.example`、`.gitignore` |
| 環境假設 | 部署於 cPanel（Apache，支援 `.htaccess`）、PHP 8.1+、pdo_sqlite、HTTPS |
| 整體風險等級 | Medium |

---

## 1. 審查範圍與方法

- **Scope**：對應 `security-reviewer` 核心流程第 1 步，攻擊面包括登入/會話、記帳 CRUD、收據上傳/檢視、CSV 匯出、後台使用者與帳本管理、AI 解析代理。
- **Scan**：全站程式碼逐行複查；未執行動態滲透測試（本機無 PHP 執行環境，且未取得針對即時/共享端點的書面授權）。
- **Review**：人工複核身分驗證、授權（IDOR）、輸入驗證、輸出編碼、密碼編譯、錯誤處理、安全標頭、憑證管理。
- **Report**：依 `security-reviewer` report-template 輸出，以 CWE / OWASP Top 10 2021 對應，嚴重度採 CVSS v3.1。

---

## 2. 既有發現（FIND-001 ~ FIND-009）修復狀態確認

先前審查（`SECURITY_REVIEW.md`）提出 9 個發現，經本次原始碼複查，皆已修復：

| ID | 嚴重度 | 標題 | 狀態 | 修復位置 |
|----|--------|------|------|----------|
| FIND-001 | High | 登入失敗識別碼以帳號＋IP 組合，同一 IP 共享者可導致彼此鎖定 | **已修復** | `login.php:23`（識別碼僅綁定 `$username`） |
| FIND-002 | Medium | `view_receipt.php` 串流輸出未設定 `Content-Disposition: attachment` | **已修復** | `view_receipt.php:88-92`（補上附件下載與 `Referrer-Policy: no-referrer`） |
| FIND-003 | Medium | `change_password.php` 缺乏舊密碼失敗次數限制 | **已修復** | `change_password.php:22-23`（以 `password_change:<userId>` 為鍵獨立鎖定） |
| FIND-004 | Medium | `admin/users.php` 刪除使用者會連帶硬刪除其所有 `entries` | **已修復** | `admin/users.php:160`（改為 `UPDATE entries SET deleted = 1` 軟刪除） |
| FIND-005 | Medium | `login.php` 登入失敗訊息與鎖定訊息區分度極小 | **已修復** | `login.php:28,104`（鎖定與失敗均顯示相同通用訊息「帳號或密碼錯誤」） |
| FIND-006 | Low | `dashboard.php` 登入來源提醒輸出 | 無需修改 | 內容皆由伺服器端產生，已經 `h()` 轉義 |
| FIND-007 | Low | `export_csv.php` CSV 公式注入防護僅檢查欄位首字元 | **已修復** | `export_csv.php:76-81`（擴大開頭字元為 `=, +, -, @, \t, \r`） |
| FIND-008 | Informational | `private_data/error.log` 無自動輪替 | 運維建議 | 無需程式碼修改；部署時加入 cron 輪替 |
| FIND-009 | Informational | `.env` 儲存管理員密碼明文 | 部署建議 | 無需程式碼修改；部署時確認 `.env` 權限為 0600/0640 |

---

## 3. 新發現的資安問題

### FIND-010 — Medium：Session Cookie 未設定 Secure 旗標

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-010 |
| **嚴重度** | Medium (CVSS 6.5) |
| **CWE** | CWE-614（敏感的 Cookie 在未加密的傳輸中） |
| **OWASP** | A07:2021（識別與驗證失敗） |
| **位置** | `config.php:107-109` |

**描述**：
`config.php` 僅設定 `session.cookie_httponly=1`、`session.use_only_cookies=1`、`session.cookie_samesite=Lax`，未設定 `session.cookie_secure=1`。若系統部署於 HTTPS 環境，Session Cookie 仍可能透過 HTTP 傳輸，遭中間人攔截，導致 Session Hijacking。

**影響**：
攻擊者可在同一網路段（如公共 Wi-Fi）竊取 session cookie，冒用合法使用者身分存取帳本與記帳資料。

**補救建議**：
在 `config.php` 補上 `ini_set('session.cookie_secure', '1');`，確保 session cookie 僅透過 HTTPS 傳輸。部署前須先確認主機已啟用 HTTPS，否則會導致 cookie 無法傳送。

---

### FIND-011 — Medium：缺少 Content Security Policy (CSP)

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-011 |
| **嚴重度** | Medium (CVSS 5.3) |
| **CWE** | CWE-693（防護機制失效） |
| **OWASP** | A05:2021（安全設定錯誤） |
| **位置** | 所有輸出 HTML 的頁面（`login.php`、`dashboard.php`、`ledger.php`、`add_entry.php`、`edit_entry.php`、`change_password.php`、`admin/*.php` 等） |

**描述**：
全站 HTML 頁面皆未設定 `Content-Security-Policy` 響應標頭，XSS 防護過度依賴 `h()`（`htmlspecialchars`）輸出編碼。雖然審查未發現直接輸出點遺漏，但缺乏深度防禦（Defense in Depth）；若未來新增任何未經過 `h()` 的輸出，即可能導致 XSS。

**影響**：
成功的 XSS 攻擊可竊取 session cookie（雖已 httponly，但仍可執行操作）、竄改頁面內容、釣魚使用者。

**補救建議**：
在 `config.php` 或各頁面共用的頂端，加上 CSP 標頭，例如：

```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none';");
```

注意：頁面內嵌 `<script>` 與行內樣式會因 `'unsafe-inline'` 而受限，建議逐步將內聯腳本移至外部檔案並改用 nonce 或 hash。

---

### FIND-012 — Low：缺少 X-Frame-Options / frame-ancestors

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-012 |
| **嚴重度** | Low (CVSS 3.1) |
| **CWE** | CWE-1021（缺少框架防護） |
| **位置** | 所有 HTML 頁面 |

**描述**：
頁面未設定 `X-Frame-Options` 或 CSP `frame-ancestors`，理論上可被惡意網站透過 `<iframe>` 嵌入，進行點擊劫持（Clickjacking）。雖本系統為內部家庭記帳，風險較低，但仍屬缺漏。

**補救建議**：
加上 `header('X-Frame-Options: DENY');`，或在 CSP 中設定 `frame-ancestors 'none';`。

---

### FIND-013 — Medium：管理員重設密碼/變更角色後，目標使用者的現有 Session 未失效

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-013 |
| **嚴重度** | Medium (CVSS 4.9) |
| **CWE** | CWE-613（敏感的 session 資訊未於權限變更時失效） |
| **OWASP** | A07:2021 |
| **位置** | `admin/users.php:87-89`（重設密碼）、`admin/users.php:122-123`（變更角色） |

**描述**：
管理員在後台重設他人密碼或變更其角色後，目標使用者現有的 PHP session 仍然有效。攻擊者若已竊取該使用者的 session cookie，重設密碼並不會將其踢下線；角色變更後，舊 session 的權限也不會立即收回。

**影響**：
攻擊者可持續使用已竊取的 session 存取系統，密碼重設無法終止既有會話。

**補救建議**：
在 `admin/users.php` 的 `reset_password` 與 `change_role` 動作完成後，對目標使用者的所有 active session 進行失效處理（例如在 `login_sessions` 表中標記該使用者的所有 session 為無效，或在後續請求時比對 `password_changed_at` / `role_changed_at` 時間戳）。簡化做法：在資料庫加上 `users.password_changed_at` 欄位，登入時與每次敏感操作前比對，若 session 建立時間早於密碼變更時間，則強制重新登入。

---

### FIND-014 — Low：`ai_parse.php` 缺乏速率限制

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-014 |
| **嚴重度** | Low (CVSS 5.3) |
| **CWE** | CWE-400（資源耗盡） |
| **位置** | `ai_parse.php` |

**描述**：
`ai_parse.php` 接受登入使用者的文字並呼叫 Gemini API，但沒有對單一使用者或單一 IP 的呼叫頻率做限制。惡意使用者可透過自動化腳本大量呼叫，耗盡 Gemini API 配額，導致 AI 功能不可用，並可能產生額外成本。

**影響**：
服務拒絕（DoS）、API 配額耗盡、潛在成本增加。

**補救建議**：
在 `ai_parse.php` 加入簡易速率限制，例如以 `$_SESSION['user_id']` 或 IP 為鍵，記錄近一分鐘的呼叫次數，超過閾值（如 20 次/分鐘）則回傳 429。也可利用既有的 `login_attempts` 表結構，新增 `ai_parse_attempts` 表。

---

### FIND-015 — Low：HTML 頁面缺少 Referrer-Policy

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-015 |
| **嚴重度** | Low (CVSS 3.7) |
| **CWE** | CWE-598（資訊透過查詢字串在 URI 中傳遞） |
| **位置** | 所有 HTML 頁面（僅 `view_receipt.php:92` 有設定） |

**描述**：
HTML 頁面未設定 `Referrer-Policy`，從內部頁面點擊外部連結時，瀏覽器可能將完整 URL（含 `ledger_id`、`page`、`ym` 等參數）作為 Referrer 傳送至第三方網站。雖這些參數不直接包含敏感資料，仍可能洩漏使用者的操作路徑與帳本結構。

**補救建議**：
在頁面 `<head>` 加上 `<meta name="referrer" content="strict-origin-when-cross-origin">`，或在 PHP 端輸出 `header('Referrer-Policy: strict-origin-when-cross-origin');`。

---

### FIND-016 — Low：無登入閒置超時 / Session 絕對過期時間

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-016 |
| **嚴重度** | Low (CVSS 3.7) |
| **CWE** | CWE-613 |
| **位置** | `config.php:104-110` |

**描述**：
`config.php` 未設定 `session.gc_maxlifetime`、`session.cookie_lifetime` 等過期控制。Session Cookie 預設為「瀏覽器關閉即過期」，但伺服器端 session 資料可能長期殘留。在共享電腦（家庭環境常見）中，若使用者忘記登出，下一位使用者直接開啟瀏覽器即可存取系統。

**補救建議**：
在 `config.php` 加上：
```php
ini_set('session.gc_maxlifetime', 1440);      // 24 分鐘伺服器端過期
ini_set('session.cookie_lifetime', 0);        // 瀏覽器關閉即過期（已為預設，可明確寫出）
```
並考慮在 `dashboard.php` 等內頁加入「最後活動時間」比對，超過閾值自動導向登出。

---

### FIND-017 — Medium：`.env.example` 包含可預測的弱密碼範本值

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-017 |
| **嚴重度** | Medium (CVSS 7.5) |
| **CWE** | CWE-521（弱密碼需求）、CWE-798（使用硬編碼憑證） |
| **OWASP** | A07:2021 |
| **位置** | `.env.example:10` |

**描述**：
`.env.example` 包含 `ADMIN_PASSWORD=change_me_before_deploy`，這是可預測的範本密碼。若管理員直接複製 `.env.example` 為 `.env` 而未變更密碼，系統將使用此弱密碼，攻擊者可輕易猜測並登入管理員帳號。

**影響**：
完整管理員權限被接管，可建立/刪除使用者、封存帳本、變更所有密碼、查看所有帳本與記錄。

**補救建議**：
將 `.env.example` 中的 `ADMIN_PASSWORD` 改為明顯的佔位符（如 `ADMIN_PASSWORD=YOUR_STRONG_PASSWORD_HERE`），並強化 README 與 `.env.example` 內的警告語，要求部署時務必替換為強密碼（建議 12 個字元以上，含英數字與符號）。

---

### FIND-018 — Medium：變更密碼最小長度過短，無複雜度要求

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-018 |
| **嚴重度** | Medium (CVSS 6.5) |
| **CWE** | CWE-521 |
| **OWASP** | A07:2021 |
| **位置** | `change_password.php:31`、`admin/users.php:40` |

**描述**：
`change_password.php` 與 `admin/users.php` 均僅要求密碼長度 `>= 6` 字元，無大寫、小寫、數字、符號等複雜度要求。6 字元純小寫或數字密碼容易被暴力破解或字典攻擊。

**影響**：
使用者帳號遭暴力破解，導致帳本資料外洩或被竄改。

**補救建議**：
將最小長度提升至 10 字元，並加入複雜度檢查（至少包含大寫、小寫、數字、符號中的三種）。可在 `functions.php` 新增 `validatePasswordStrength(string $password): array` 函數，回傳是否通過與原因說明。

---

### FIND-019 — Informational：`export_csv.php` 金額顯示使用浮點運算

| 欄位 | 值 |
|------|-----|
| **ID** | FIND-019 |
| **嚴重度** | Informational |
| **位置** | `export_csv.php:69` |

**描述**：
`export_csv.php:69` 使用 `number_format($row['amount_cents'] / 100, 2, '.', '')`，金額顯示經過浮點除法。與系統「全程無 FLOAT」的設計原則（`entries.amount_cents` 為 INTEGER）不一致。雖為僅顯示用途，在極大金額下可能有精確度誤差。

**補救建議**：
改用整數運算格式化，例如：
```php
$dollars = intdiv($row['amount_cents'], 100);
$centsPart = $row['amount_cents'] % 100;
$amount = $dollars . '.' . str_pad((string)$centsPart, 2, '0', STR_PAD_LEFT);
```

---

## 4. 發現總覽（嚴重度統計）

| 等級 | 數量 | IDs |
|------|------|-----|
| Critical | 0 | — |
| High | 1 | FIND-017 |
| Medium | 6 | FIND-010, FIND-011, FIND-013, FIND-017, FIND-018 |
| Low | 4 | FIND-012, FIND-014, FIND-015, FIND-016 |
| Informational | 1 | FIND-019 |

> 注：FIND-010~FIND-019 為本次新發現；FIND-001~FIND-009 為先前審查發現，皆已修復。

---

## 5. 修復建議總覽（優先順序）

| 優先 | ID | 建議動作 | 預估工時 |
|------|-----|----------|----------|
| 1（立即） | FIND-017 | `.env.example` 密碼範本改為明顯佔位符，強化部署警告 | 15 分鐘 |
| 2（立即） | FIND-010 | `config.php` 補上 `session.cookie_secure=1`（需先確認 HTTPS） | 15 分鐘 |
| 3（本 Sprint） | FIND-011 | 新增 Content Security Policy 標頭 | 1 小時 |
| 4（本 Sprint） | FIND-018 | 提升密碼最小長度至 10 並加入複雜度檢查 | 30 分鐘 |
| 5（本 Sprint） | FIND-013 | 密碼重設/角色變更後使目標使用者 session 失效 | 1 小時 |
| 6（下 Sprint） | FIND-014 | `ai_parse.php` 加入單使用者速率限制 | 1 小時 |
| 7（下 Sprint） | FIND-012 | 補上 `X-Frame-Options: DENY` | 15 分鐘 |
| 8（下 Sprint） | FIND-015 | 補上 `Referrer-Policy: strict-origin-when-cross-origin` | 15 分鐘 |
| 9（下 Sprint） | FIND-016 | 加入 session 閒置過期控制 | 30 分鐘 |
| 10（回填） | FIND-019 | `export_csv.php` 金額顯示改用整數運算 | 15 分鐘 |

---

## 6. 良好安全做法（已到位，無需變更）

以下項目經審查確認已正確實作，維持現狀：

1. **SQL 注入防護**：全站僅使用 PDO 預備陳述式（具名佔位符），`db.php` 設定 `ATTR_EMULATE_PREPARES=false`，不拼字串 SQL。
2. **密碼雜湊**：所有密碼以 `password_hash($password, PASSWORD_DEFAULT)` 儲存，登入以 `password_verify()` 比對；無純文字密碼寫入資料庫或輸出。
3. **CSRF 防護**：所有狀態改寫表單皆含 `csrf_token`，POST 時以 `hash_equals()` 常數時間比較驗證。
4. **XSS 輸出編碼**：頁面輸出皆經 `h()`（`htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE, UTF-8`）。
5. **Session 基礎防護**：`session.cookie_httponly=1`、`session.use_only_cookies=1`、`session.cookie_samesite=Lax`，登入成功呼叫 `session_regenerate_id(true)` 防禦 Session Fixation。
6. **登入暴力破解防護**：`login_attempts` 表，5 次失敗後鎖定 15 分鐘；失敗訊息通用化，避免使用者列舉。
7. **檔案上傳安全**：`handleReceiptUpload()` 以 `finfo_file` 檢查 MIME Type、隨機重新命名（`bin2hex(random_bytes(16))`）、限制 5MB、上傳後再次驗證 MIME、`chmod 0640`。
8. **路徑遍歷防護**：`view_receipt.php` 以 `str_starts_with` + `realpath` 雙重驗證檔案路徑位於 `private_data/uploads/` 內。
9. **CSV 公式注入防護**：`export_csv.php` 對 `=, +, -, @, \t, \r` 開頭欄位加前置單引號。
10. **IDOR 防護**：所有資源存取（帳本、記錄、收據）皆透過 `ledger_access` 與 `user_id` 做權限檢查。
11. **錯誤處理**：`display_errors=0`，錯誤寫入 `private_data/error.log`，畫面顯示通用訊息；未捕捉例外由 `set_exception_handler` 處理。
12. **目錄防護**：根目錄 `.htaccess` 封鎖 `.env`，`private_data/.htaccess` 以 `Require all denied` 封鎖整個私密目錄（含 `.db`、`.db-wal`、`.db-shm`）。
13. **審計日誌**：`audit_log` 記錄登入/登出、記帳、密碼變更、後台管理操作；唯讀、不可從網頁刪除。
14. **最小權限**：`viewer` 角色不可新增/編輯/刪除記錄；`member` 可寫入；`owner` 可設定預算；站台 `admin` 可管理使用者與帳本。

---

## 7. 限制與後續建議

1. 本審查為靜態原始碼審查，未執行動態滲透測試（本機無 PHP 執行環境；且 `security-audit` 禁止對即時/共享端點進行探測）。
2. 建議部署至 cPanel 後，以瀏覽器直接訪問 `/.env` 與 `/private_data/` 確認回傳 403；並以管理員帳號驗證登入鎖定、CSRF 防護、後台權限隔離等行為。
3. 若需完整滲透測試，須取得書面授權與規則交接，並在沙箱環境中執行，不得針對生產主機。

---

## 參考資料

- OWASP Top 10 2021: https://owasp.org/www-project-top-ten/
- CWE Database: https://cwe.mitre.org/
- CVSS v3.1 Calculator: https://www.first.org/cvss/calculator/3.1
- `security-reviewer` skill report template
- 既有報告：`SECURITY_REVIEW.md`（FIND-001 ~ FIND-009）
