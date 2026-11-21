# Planning: 資安審查修復（FIND-010 ~ FIND-019）

## Description

依據第二輪資安審查報告（`docs/SECURITY_REVIEW_20261121.md`），修復系統中新發現的 10 個資安問題，範圍涵蓋 Session 安全、內容安全政策、密碼複雜度、管理員操作後 Session 失效、API 速率限制、安全標頭與程式細節。第一輪發現的 FIND-001 ~ FIND-009 已全數修復，本期不重複處理。

修復目標：將整體風險從 Medium 降低，消除 High 等級問題（FIND-017），並完成所有 Medium 與 Low 等級的修復；Informational 等級視為選擇性回填。

範圍限於 `config.php`、`change_password.php`、`admin/users.php`、`ai_parse.php`、`export_csv.php` 與所有 HTML 輸出頁面；不包含動態滲透測試、不包含新增資料庫表（除非為 Session 失效機制所需），不包含 cPanel 部署層級的基礎設施 hardening（如防火牆、WAF）。

## User Story

- 作為系統管理員，我希望 `.env.example` 不含可預測的弱密碼範本，避免部署後誤沿用導致管理員帳號被暴力破解。
- 作為使用者，我希望 Session Cookie 僅透過 HTTPS 傳輸，避免在公共 Wi-Fi 被竊取。
- 作為管理員，我希望重設他人密碼或變更其角色後，該使用者既有 Session 立即失效，防止已竊取的 Session 繼續存取系統。
- 作為使用者，我希望變更密碼時需符合較強的複雜度要求，降低被字典攻擊的風險。
- 作為開發者，我希望所有 HTML 頁面設定 CSP、X-Frame-Options 與 Referrer-Policy，作為 XSS 與點擊劫持的深度防禦。
- 作為管理員，我希望 AI 一句話記帳 API 有速率限制，避免單一使用者濫用耗盡 Gemini API 配額。

## To Reproduce

本項目非錯誤（bug）類需求，不適用重現步驟；所有發現皆為靜態原始碼審查確認的安全缺漏。

## Expected behavior

- `.env.example` 的 `ADMIN_PASSWORD` 改為明顯佔位符（非可預測值），README 與 `.env.example` 皆強化部署警告，要求部署時務必替換為強密碼。
- `config.php` 設定 `session.cookie_secure=1`；部署前須先確認主機已啟用 HTTPS，否則 Session Cookie 無法傳送而導致登入失敗。
- 所有 HTML 頁面輸出 `Content-Security-Policy` 響應標頭，預設僅允許同源資源；內聯腳本逐步移至外部檔案或以 nonce/hash 白名單。
- 所有 HTML 頁面輸出 `X-Frame-Options: DENY`（或 CSP `frame-ancestors 'none'`），防止點擊劫持。
- 所有 HTML 頁面設定 `Referrer-Policy: strict-origin-when-cross-origin`，避免參數洩漏至第三方網站。
- `admin/users.php` 的 `reset_password` 與 `change_role` 動作完成後，目標使用者的現有 Session 失效（透過 `users.password_changed_at` 欄位比對，或標記 `login_sessions` 為無效）。
- `change_password.php` 與 `admin/users.php` 的密碼最小長度提升至 10 字元，並加入複雜度檢查（至少包含大寫、小寫、數字、符號中的三種）。
- `ai_parse.php` 加入單使用者（以 `$_SESSION['user_id']` 或 IP 為鍵）的呼叫速率限制，超過閾值回傳 HTTP 429。
- `export_csv.php` 金額顯示改用整數運算（`intdiv` + 取餘數），不再經過浮點除法。
- Session 加入閒置過期控制（`session.gc_maxlifetime` 與最後活動時間比對），超過閾值自動導向登出。

## Acceptance Criteria

| ID | 嚴重度 | 驗收標準 |
|----|--------|----------|
| FIND-017 | Medium | `.env.example` 中 `ADMIN_PASSWORD` 為明顯佔位符（如 `YOUR_STRONG_PASSWORD_HERE`）；README 與 `.env.example` 皆有「部署時務必替換為強密碼」警告；複製 `.env.example` 為 `.env` 但未變更密碼時，系統不應以預設弱密碼允許管理員登入（此項依賴部署檢查）。 |
| FIND-010 | Medium | `config.php` 包含 `ini_set('session.cookie_secure', '1');`；部署於 HTTPS 主機後，瀏覽器開發者工具確認 Session Cookie 具 Secure 旗標。 |
| FIND-011 | Medium | 所有 HTML 頁面回應標頭包含 `Content-Security-Policy`；頁面內聯 `<script>` 與行內樣式不會因 CSP 而中斷功能（或已移至外部檔案）。 |
| FIND-012 | Low | 所有 HTML 頁面回應標頭包含 `X-Frame-Options: DENY`（或 CSP `frame-ancestors 'none'`）。 |
| FIND-013 | Medium | 管理員在後台重設他人密碼後，該使用者以舊密碼登入的現有 Session 無法再執行敏感操作（導向重新登入）；變更角色後，舊 Session 權限不再立即生效。 |
| FIND-014 | Low | `ai_parse.php` 對單一使用者在一分鐘內超過設定閾值（例如 20 次）的呼叫，回傳 HTTP 429 與錯誤訊息；正常呼叫不受影響。 |
| FIND-015 | Low | 所有 HTML 頁面回應標頭或 `<meta>` 包含 `Referrer-Policy: strict-origin-when-cross-origin`。 |
| FIND-016 | Low | `config.php` 設定 `session.gc_maxlifetime`；內頁（如 `dashboard.php`）記錄最後活動時間，超過閒置閾值自動導向登出。 |
| FIND-018 | Medium | `change_password.php` 與 `admin/users.php` 新增密碼強度檢查函數；長度小於 10 或未達複雜度門檻時拒絕並顯示具體原因；現有 6 字元弱密碼不自動失效，但新設與重設皆適用新標準。 |
| FIND-019 | Informational | `export_csv.php` 金額欄位改用整數運算格式化，不再使用 `/ 100` 浮點除法；匯出 CSV 的金額顯示與前端 `formatCents()` 結果一致。 |

## Additional context

### 階段劃分（建議執行順序）

| 階段 | 範圍 | 預估工時 | 備註 |
|------|------|----------|------|
| Phase 1（立即，本週內） | FIND-017、FIND-010 | 約 30 分鐘 | **已完成**：FIND-010 需先確認主機已啟用 HTTPS（用戶確認已啟用），故可安全開啟 `session.cookie_secure=1`。 |
| Phase 2（本 Sprint） | FIND-011、FIND-012、FIND-013、FIND-015、FIND-018 | 約 2.5 小時 | **已完成**：CSP、X-Frame-Options、Referrer-Policy 皆於 config.php 統一設定；users 表新增 password_changed_at 欄位（含舊資料庫 migration）；密碼強度驗證已加入 change_password 與 admin/users。 |
| Phase 3（下 Sprint） | FIND-014、FIND-016、FIND-019 | 約 1.5 小時 | **已完成**：ai_parse.php 新增單使用者速率限制（20 次/分鐘）；config.php 新增 session.gc_maxlifetime 與閒置過期檢查（30 分鐘）；export_csv.php 金額顯示改用整數運算。 |

### 待確認（開發者需決定）

1. **FIND-010**：生產主機是否已啟用 HTTPS？若未啟用，`session.cookie_secure=1` 會導致 Session Cookie 無法傳送，需先部署 SSL 憑證。
2. **FIND-011**：CSP 的 `script-src` 是否允許 `'unsafe-inline'`？現有頁面內聯大量 `<script>`（字級切換、AI 語音），短期內可能需要 `'unsafe-inline'`，長期建議移至外部 JS 檔案並改用 nonce。
3. **FIND-013**：Session 失效機制採用「新增 `users.password_changed_at` 欄位比對」還是「標記 `login_sessions` 為無效」？前者改動較小，後者能即時踢下線。
4. **FIND-014**：速率限制的儲存位置（記憶體 vs 資料庫）與閾值（建議 20 次/分鐘）？cPanel 共享主機可能無 Redis，建議以 SQLite 資料庫表儲存。
5. **FIND-018**：現有使用者的弱密碼是否需要強制提示變更？建議僅對新設與重設適用，不強制現有密碼失效，避免家庭成員使用困擾。

### 非本期範圍（Out of Scope）

- 動態滲透滲透測試（需書面授權、沙箱環境）。
- 新增資料庫表（除 FIND-013 可能需要 `login_sessions` 擴充，其餘皆沿用既有表）。
- cPanel 基礎設施 hardening（防火牆、WAF、ModSecurity、PHP `open_basedir` 等）。
- `.env` 檔案權限的程式化強制（屬部署運維，建議部署時設為 0600/0640）。

### 資料流（修復後）

- **Session 建立**：`login.php` 登入成功 → `session_regenerate_id(true)` → 寫入 `$_SESSION['authed_at'] = time()` 與 `users.password_changed_at` 比對（FIND-013）→ 輸出 `Set-Cookie` 帶 Secure + HttpOnly + SameSite=Lax（FIND-010）。
- **敏感操作前檢查**：`dashboard.php` / 內頁請求時 → 比對 `$_SESSION['authed_at']` 與 `users.password_changed_at`，若 session 建立早於密碼變更 → 銷毀 session 並導向登入（FIND-013）。
- **密碼變更**：`change_password.php` / `admin/users.php` → 新密碼經 `validatePasswordStrength()` 檢查（FIND-018）→ `password_hash()` 儲存 → 更新 `users.password_changed_at = datetime('now','localtime')`（FIND-013）→ 寫入 `audit_log`。
- **API 呼叫**：`ai_parse.php` → 檢查單使用者速率限制（FIND-014）→ 通過後呼叫 Gemini API。
- **頁面輸出**：所有 PHP 頁面 → `config.php` 或共用 header 輸出 CSP、X-Frame-Options、Referrer-Policy（FIND-011/012/015）。

### 身分驗證與權限

- 所有修復均不變更既有權限模型（站台 admin / 帳本 owner / member / viewer）。
- FIND-013 僅影響「已登入使用者的 Session 有效性」，不授予新權限。
- 速率限制（FIND-014）以已登入 `user_id` 為鍵，未登入者無法呼叫 `ai_parse.php`（已有 `requireLogin()`）。

### 資料傳輸加密

- 僅 FIND-010 涉及傳輸層（Secure Cookie），依賴主機 HTTPS；應用層本身不額外加密（全站已假設 HTTPS 部署）。

### 非功能性需求

- **效能**：CSP、標頭新增對回應時間影響極小；速率限制每次呼叫一次資料庫查詢，可接受。
- **併發**：SQLite WAL 模式已啟用，速率限制寫入不會阻斷主流程（失敗時僅記錄 error.log，不中斷 AI 解析）。
- **資料保留**：`users.password_changed_at` 為時間戳，不設自動刪除；速率限制表建議每日清理超過 24 小時的紀錄（可沿用 `login_attempts` 現有邏輯，或於登入時順帶清理）。
- **可用性**：FIND-010 錯誤開啟會導致登入失敗，部署前必須確認 HTTPS；其餘修復皆向下相容，不影響既有功能。

---

## 已完成修改（Phase 1）

| ID | 嚴重度 | 修改摘要 | 檔案 |
|----|--------|----------|------|
| FIND-017 | Medium | `.env.example` 的 `ADMIN_PASSWORD` 由 `change_me_before_deploy` 改為明顯佔位符 `YOUR_STRONG_PASSWORD_HERE`；強化註解警告，切勿直接沿用佔位符部署。 | `.env.example` |
| FIND-017 | Medium | `README.md` 部署步驟第 4 點強化警告：嚴禁沿用範本佔位符值，務必替換為自行設定的強密碼。 | `README.md` |
| FIND-010 | Medium | `config.php` 新增 `ini_set('session.cookie_secure', '1');`，Session Cookie 僅透過 HTTPS 傳輸；註解說明部署前需先確認主機已啟用 HTTPS。 | `config.php` |

> 生產主機已確認啟用 HTTPS（用戶確認），故 `session.cookie_secure=1` 可安全開啟，不會導致登入失敗。

| ID | 嚴重度 | 修改摘要 | 檔案 |
|----|--------|----------|------|
| FIND-011 | Medium | 新增 Content Security Policy 標頭（`default-src 'self'; script-src 'self' 'unsafe-inline'; ...`），短期保留 `unsafe-inline` 因頁面有內聯腳本。 | `config.php` |
| FIND-012 | Medium | 新增 `X-Frame-Options: DENY`，防止點擊劫持。 | `config.php` |
| FIND-013 | Medium | `users` 表新增 `password_changed_at` 欄位（含舊資料庫 `ALTER TABLE` migration）；`seedAdmin` 於建立時寫入；`admin/users.php` 重設密碼與變更角色後更新該欄位；`functions.php` 新增 `requireSessionFresh()`，密碼變更後使既有 Session 失效並導向登入。 | `db.php`, `functions.php`, `admin/users.php` |
| FIND-015 | Low | 新增 `Referrer-Policy: strict-origin-when-cross-origin`，避免參數洩漏至第三方網站。 | `config.php` |
| FIND-018 | Medium | 新增 `validatePasswordStrength()`（長度 ≥ 10，需含大寫/小寫/數字/符號中至少三類）；`change_password.php` 與 `admin/users.php`（建立使用者、重設密碼）皆改用新函數，舊 6 字元門檻已廢除。 | `functions.php`, `change_password.php`, `admin/users.php` |
| FIND-014 | Low | `ai_parse.php` 新增單使用者速率限制（以 `user_id` 為鍵，最近 1 分鐘內最多 20 次呼叫，超過則回傳 HTTP 429）；新增 `api_rate_limits` 資料表並每日清理超過 24 小時的舊紀錄。 | `ai_parse.php` |
| FIND-016 | Low | `config.php` 設定 `session.gc_maxlifetime=1440`（24 分鐘）與 `session.cookie_lifetime=0`（瀏覽器關閉即過期）；`functions.php` `requireSessionFresh()` 新增 30 分鐘閒置過期檢查，超過則銷毀 Session 並導向登入；`login.php` 登入成功時寫入 `$_SESSION['last_activity']`。 | `config.php`, `functions.php`, `login.php` |
| FIND-019 | Informational | `export_csv.php` 金額顯示改用整數運算（`intdiv` + 取餘數），不再使用浮點除法 `/ 100`，與系統「全程無 FLOAT」設計原則一致。 | `export_csv.php` |

