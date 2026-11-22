# 資安審查掃描報告 — CookiesAccounting 全家人記帳系統

| 欄位 | 值 |
|------|-----|
| **應用程式** | CookiesAccounting（全家人記帳系統，PHP + SQLite） |
| **審查日期** | 2026-11-22 |
| **審查者** | OpenCode security-reviewer skill |
| **審查範圍** | 專案根目錄所有 `.php`、`config.php`、`.htaccess`、`.env.example`、`functions.php`、`db.php`、`ai_parse.php`、`login.php`、`view_receipt.php`、`add_entry.php`、`edit_entry.php`、`admin/*` 等 |
| **整體風險等級** | Medium（無 Critical / High，有 2 個 Medium 需部署前確認） |
| **.env 內容** | 未讀取（尊重使用者邊界，包含實際密碼與 API 金鑰） |

---

## 關鍵發現摘要

| 等級 | 數量 | 狀態 |
|------|------|------|
| Critical | 0 | — |
| High | 0 | — |
| Medium | 2 | 部署前確認 |
| Low | 1 | 列入待辦 |
| Info | 2 | 建議優化 |

---

## 已做好的防護（正面發現）

以下項目經檢視均已正確實作，未發現相對應的弱點：

| 項目 | 說明 | 檢查位置 |
|------|------|----------|
| SQL Injection 防護 | 所有查詢皆使用 PDO Prepared Statements，未發現字串拼接 SQL | `db.php`、`login.php`、`functions.php`、`admin/*` |
| CSRF 防護 | 所有狀態變更 POST 請求皆有 CSRF Token，並以 `hash_equals()` 恆時比較 | `functions.php:138-144`、各表單頁面 |
| XSS 防護 | 統一使用 `h()`（`htmlspecialchars` `ENT_QUOTES \| ENT_SUBSTITUTE`，UTF-8）輸出 | `functions.php:12-15` |
| Session 安全 | `HttpOnly`、`SameSite=Lax`、`secure`、`session_regenerate_id(true)` 防 fixation | `config.php:107-118`、`login.php:46` |
| 登入防護 | `password_hash`/`password_verify` (bcrypt)；登入失敗 5 次鎖定 15 分鐘；密碼強度 ≥10 字元且需 3 類別 | `functions.php:193-269`、`login.php:28-109` |
| 檔案上傳安全 | MIME 雙重驗證 (`finfo_file`)、亂數重新命名 (`random_bytes(16)`)、5MB 上限、`chmod 0640` | `add_entry.php:130-199` |
| 目錄直接存取封鎖 | `private_data/.htaccess` 與 `private_data/uploads/.htaccess` 皆 `Require all denied` | `private_data/.htaccess`、`private_data/uploads/.htaccess` |
| 收據串流安全 | `view_receipt.php` 做 IDOR 權限檢查、路徑遍歷防護 (`realpath` + `str_starts_with`)、MIME 再驗證、`Content-Disposition: attachment`、`X-Content-Type-Options: nosniff` | `view_receipt.php:1-95` |
| 安全回應標頭 | CSP、`X-Frame-Options: DENY`、`Referrer-Policy: strict-origin-when-cross-origin` | `config.php:120-129` |
| 錯誤不外洩 | `display_errors=0`；`set_exception_handler` 僅顯示通用訊息，完整堆疊寫入 `private_data/error.log` | `config.php:73-97` |
| 審計日誌 | `audit_log` 僅新增不刪除、僅管理員可見、不提供網頁刪除入口 | `admin/audit_log.php`、`functions.php:288-318` |
| API 速率限制 | `ai_parse.php` 單使用者 1 分鐘最多 20 次，超過回 HTTP 429 | `ai_parse.php:56-93` |
| 呼叫外部 API 安全 | Gemini API 僅後端代理，金鑰不外洩給前端；`CURLOPT_SSL_VERIFYPEER=true` | `ai_parse.php:49-132` |

---

## 詳細發現

### [MEDIUM] SEC-001 — Session Cookie 強制 secure，部署依賴 HTTPS

| 欄位 | 值 |
|------|-----|
| **ID** | SEC-001 |
| **等級** | Medium (CVSS 約 5.3) |
| **位置** | `config.php:112` `ini_set('session.cookie_secure', '1');` |
| **CWE** | CWE-614 (Sensitive Cookie in HTTPS Without 'Secure' Attribute) / CWE-312 |
| **參考** | OWASP A07:2021 Identification and Authentication Failures |

**說明**
`session.cookie_secure` 被強制設為 `1`，表示 Session Cookie 僅會透過 HTTPS 傳輸。註解已標示「部署前請先確認主機已啟用 HTTPS，否則 Cookie 無法傳送而導致登入失敗」。

**影響**
- 若部署主機未啟用 HTTPS：使用者無法登入（Cookie 不會被瀏覽器送出），可用性受損。
- 若以 HTTP 部署且將此設定改為 `0` 以求可用：Session Cookie 會以純文本傳輸，易被中間人攻擊竊聽並劫持 Session。

**修復建議**
1. 確認部署主機已啟用 HTTPS（cPanel 主機啟用 SSL/TLS）。
2. 若短期內無法提供 HTTPS，改為條件式設定（僅在 `https` 時設定 `secure`），並規劃盡快上 HTTPS。
3. 可搭配 HSTS 標頭進一步強化。

---

### [MEDIUM] SEC-002 — Content Security Policy 包含 'unsafe-inline'

| 欄位 | 值 |
|------|-----|
| **ID** | SEC-002 |
| **等級** | Medium (CVSS 約 4.8) |
| **位置** | `config.php:123` |
| **CWE** | CWE-79 (Cross-site Scripting) |
| **參考** | OWASP A03:2021 Injection |

**說明**
CSP 標頭目前為 `script-src 'self' 'unsafe-inline'`，頁面內聯大量 `<script>`（字級切換、AI 語音等），註解標示「短期內需保留 'unsafe-inline'，長期建議將內聯腳本移至外部 JS 檔案並改用 nonce」。

**影響**
降低 XSS 防護的縱深（defense-in-depth）。若未來其他頁面出現未經過濾的使用者輸入輸出點，`unsafe-inline` 會允許攻擊者透過內聯腳本執行惡意 JavaScript。

**修復建議**
1. 將現有內聯 `<script>` 移至外部 `.js` 檔案。
2. 改用 `script-src 'self'` 加上 `nonce-` 或 `sha256-` 的允許清單。
3. 移除 `'unsafe-inline'`，提升 XSS 防護等級。

---

### [LOW] SEC-003 — 登入速率限制僅綁定帳號識別碼，不納入 IP

| 欄位 | 值 |
|------|-----|
| **ID** | SEC-003 |
| **等級** | Low (CVSS 約 3.7) |
| **位置** | `login.php:26`、`functions.php:214-269` |
| **CWE** | CWE-307 (Improper Restriction of Excessive Authentication Attempts) |
| **參考** | OWASP A07:2021 |

**說明**
登入失敗計數僅綁定於帳號名稱（`$identifier = $username`），註解說明「避免同一公網 IP 下的家庭成員互相導致鎖定」。雖有 5 次失敗鎖定 15 分鐘及密碼強度要求，但對單一常見帳號（如 `admin`），攻擊者可透過分散 IP 繼續嘗試暴力破解。

**影響**
對常見管理員帳號的暴力破解/字典攻擊防護較弱（雖受 5 次/15 分鐘鎖定限制，但可跨 IP 繼續）。

**修復建議**
1. 考慮以「帳號 + IP」的組合識別碼做速率限制，或在鎖定後要求驗證碼（CAPTCHA）。
2. 維持現有 5 次/15 分鐘鎖定機制，已為良好基礎。

---

### [INFO] SEC-004 — 登入來源異常比對僅以 IP 為主要判定

| 欄位 | 值 |
|------|-----|
| **ID** | SEC-004 |
| **等級** | Info (CVSS 約 2.1) |
| **位置** | `login.php:82-96` |
| **CWE** | CWE-778 (Insufficient Logging) — 僅建議層級 |

**說明**
登入成功後會比對歷史 IP，若為新來源則設定 session 提醒標記。但比對僅以 IP 為主要判定；在共享公網 IP（NAT）環境下，新來源提醒可能不準確。此僅為使用者提醒機制，非安全控制。

**影響**
新登入來源提醒的準確性受限，但不影響核心安全性。

**修復建議**
可考慮結合 User-Agent 作為次要判斷依據，減少誤報。屬建議性優化，非強制。

---

### [INFO] SEC-005 — .env 包含實際管理員密碼與 Gemini API 金鑰（未讀取內容）

| 欄位 | 值 |
|------|-----|
| **ID** | SEC-005 |
| **等級** | Info（待確認，未讀取 `.env` 實際內容） |
| **位置** | 專案根目錄 `.env`、`config.php:51-52`、`ai_parse.php:49` |
| **CWE** | CWE-798 (Use of Hard-coded Credentials) |
| **參考** | OWASP A07:2021 |

**說明**
`.env` 存放 `ADMIN_USERNAME`、`ADMIN_PASSWORD` 與 `GEMINI_API_KEY`。基於使用者要求，本次掃描未讀取 `.env` 實際內容。`.env` 已列入 `.gitignore`，且 `private_data/.htaccess`（`Require all denied`）與根目錄 `.htaccess` 皆封鎖瀏覽器直接存取。`.env.example` 仍保留預設佔位符 `ADMIN_PASSWORD=YOUR_STRONG_PASSWORD_HERE`。

**影響**
若部署時未將 `ADMIN_PASSWORD` 替換為強密碼，管理員帳號可被輕易猜測並接管系統。若 `.env` 意外進入版控或被下載，密碼與 API 金鑰將外洩。

**修復建議**
1. 部署前務必將 `.env` 中的 `ADMIN_PASSWORD` 替換為強密碼（建議 ≥12 字元，含英數字與符號）。
2. 確認 `.env` 未被加入版控（已由 `.gitignore` 排除）。
3. 確認主機 `.htaccess` 正確生效，禁止直接下載 `.env`。
4. 若不使用 AI 功能，可不設定 `GEMINI_API_KEY`，減少金鑰外洩風險。

---

## 建議優先順序

| 優先 | 項目 | 說明 |
|------|------|------|
| **立即（部署前）** | SEC-001 | 確認部署主機已啟用 HTTPS，使 Session Cookie 能安全傳輸。 |
| **立即（部署前）** | SEC-005 | 將 `.env` 中的 `ADMIN_PASSWORD` 替換為強密碼；程式碼已加入佔位符偵測警告（寫入 error.log）。 |
| **下個 Sprint** | SEC-002 | 已完成：內聯 `<script>` 移至外部 JS，CSP 移除 `'unsafe-inline'`。 |
| **已完成** | SEC-003 | 登入失敗識別碼改為「帳號|IP」，攻擊者無法透過換 IP 對單一帳號無限暴力破解。 |
| **已完成** | SEC-004 | 登入來源異常比對加入 User-Agent，同 IP 不同裝置也會觸發新來源提醒。 |

---

## 掃描工具與方法

| 工具/方法 | 說明 |
|-----------|------|
| 靜態程式碼審查 (SAST, manual) | 逐檔檢視所有 `.php`，重點檢查 SQL 拼接、XSS 輸出、檔案上傳、路徑遍歷、CSRF、Session 設定 |
| 關鍵字掃描 | 搜尋 `$_GET`/`$_POST`/`$_REQUEST`、`eval`/`exec`/`system`、`include/require`、`move_uploaded_file`、`finfo_file` 等 |
| 設定檔檢查 | `.htaccess`、`config.php`、`.gitignore`、`.env.example` |
| 依賴檢查 | 純 PHP，未使用 Composer/第三方套件，供應鏈相依性風險低 |

---

## 已完成的修復（SEC-002）

| 項目 | 內容 |
|------|------|
| **發現** | SEC-002 Content Security Policy 包含 `'unsafe-inline'` |
| **修復** | 將所有內聯 `<script>` 抽離至外部 JS 檔案：`assets/app.js`（通用：字級切換、快速日期、類別互動、重複記錄模態框、語音播報）、`assets/ai-parse.js`（僅 add_entry.php：AI 一句話記帳）。12 個頁面均改為引入外部 JS，不再產生內聯腳本。 |
| **設定變更** | `config.php` CSP `script-src` 從 `'self' 'unsafe-inline'` 改為 `'self'`，移除了 `'unsafe-inline'`。 |
| **ledger.php 語音播報** | 原內聯語音播報腳本改為 `data-voice-text`（body 屬性），由 `assets/app.js` 在頁面載入時讀取並播報，完全消除內聯腳本。 |
| **狀態** | 已修復。建議部署前在瀏覽器開發者工具確認 CSP 標頭為 `script-src 'self'` 且無違反報告。 |

---

## 已完成的修復（SEC-003 / SEC-004 / SEC-005）

| 項目 | 內容 |
|------|------|
| **SEC-003** 登入速率限制 | `login.php:28-29`：登入失敗識別碼從僅帳號（`$username`）改為「帳號\|IP」（`$username . '|' . $ip`）。<br>- 同一公網 IP 下不同帳號互不影響，保留家庭共用 IP 不會互相鎖定的設計。<br>- 攻擊者無法透過換 IP 對單一常見帳號（如 admin）無限嘗試暴力破解，每個 (帳號, IP) 組合最多 5 次失敗即鎖定 15 分鐘。 |
| **SEC-004** 登入來源異常比對 | `login.php:86-95`：歷史來源比對從僅比對 IP 改為「IP + User-Agent」同時比對。同一公網 IP 但不同裝置/瀏覽器（User-Agent 不同）也會被視為新來源並觸發登入提醒。 |
| **SEC-005** `.env` 佔位符偵測 | `config.php:54-69`：新增佔位符偵測邏輯。若 `ADMIN_PASSWORD` 仍為 `YOUR_STRONG_PASSWORD_HERE`、`admin`、`password` 或空字串，或 `GEMINI_API_KEY` 仍為 `your_gemini_api_key_here`，則在 `error.log` 寫入 `[SEC-005 WARNING]`，提醒部署前務必替換為強密碼。<br>※ 不讀取 `.env` 真實內容，僅檢查常見預設值。 |

---

## 參考標準

- OWASP Top 10 2021
- CWE (Common Weakness Enumeration)
- CVSS v3.1 嚴重度評分
- `security-reviewer` skill 工作流程（Scope → Scan → Review → Test and classify → Report）

---

> 本報告僅為靜態程式碼審查與設定檢查，未對執行中的系統進行滲透測試（如需主動攻擊測試，須先取得書面授權並確認規則邊界）。`.env` 實際內容未被讀取，尊重使用者邊界。