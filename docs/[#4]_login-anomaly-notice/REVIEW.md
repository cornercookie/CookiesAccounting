# 設計文件審查報告 — 登入異常通知

檔案：`docs/login-anomaly-notice/Design.md`  
對應 Planning：`docs/login-anomaly-notice/planning.md`  
審查視角：第三方專家（Spec → Code → Security）  
專案：純 PHP + SQLite 家庭共同帳本（cPanel，PHP 8.1+，pdo_sqlite，無 Composer/Node）

---

## 總體評語

**需修正（非受阻）**

Design 與 Planning 範圍基本一致，修改路徑（db.php / login.php / dashboard.php）與 90 天清理位置也正確，尚未破壞既有 CSRF、session 登入機制。但存在兩處需補強：(1) 異常判定邏輯（任一新來源即視為異常 vs. 僅 IP 為主要）未鎖定，驗收條件無法精確判定；(2) dashboard.php 提醒訊息中的 IP 與登入時間未明確要求 `h()` 轉義，與專案「所有輸出經 h() 轉義」硬性約束有落差。以上兩點補正後可進入實作。

---

## 發現列表

### 1. Spec — 異常判定邏輯未鎖定（阻塞，需使用者確認）

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§設計概述第 4 點、§異常判定與提醒
- **章節**：異常判定與提醒
- **影響**：Planning §Expected behavior 保留兩種可能（IP 或 UA 任一為新即視為異常 / 僅以 IP 為主要、UA 僅供參考），Design 未選定其一。導致驗收條件 AC 2「從新 IP 登入時顯示提醒；從已使用過的 IP 登入時不顯示」無法精確驗證，實作時會產生分歧。
- **嚴重度**：Major
- **修正建議**：在 Design 中明確選定一種判定邏輯，並說明 UA 截斷與空值處理；若保留兩種可能，需標示為「待使用者決定」並給出預設實作。
- **是否為阻塞項**：是。依據：驗收條件 AC 2 與 AC 3 依賴此判定邏輯，未鎖定前無法判定通過與否。

### 2. Spec — IP / User-Agent 為空時的處理未寫入 Design

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§設計概述第 4 點
- **章節**：異常判定與提醒
- **影響**：Planning 明確規定「IP 或 User-Agent 為空時不視為異常，不顯示提醒」，Design 未對應章節說明。實作易漏此分支，導致空值登入誤觸發提醒或寫入空字串紀錄。
- **嚴重度**：Minor
- **修正建議**：補充空值守衛條件（例如 `ip === '' || ip === 'unknown'` 或 UA 為空時跳過判定與寫入）。
- **是否為阻塞項**：否。屬實作細節補充，不影響整體架構。

### 3. Spec — User-Agent 截斷未寫入 Design

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§資料庫變更、§程式修改路徑
- **章節**：資料庫變更 / 登入來源記錄
- **影響**：Planning §IP 與 User-Agent 處理 要求寫入前以 `mb_substr($ua, 0, 255)` 截斷，Design 未提及。欄位定義為 `TEXT` 雖可儲存長字串，但未符合規範，長期可能被過長 UA 灌爆資料庫。
- **嚴重度**：Minor
- **修正建議**：在 Design §登入來源記錄 中補充「寫入前對 user_agent 執行 mb_substr 截斷至 255 字元」。
- **是否為阻塞項**：否。

### 4. Code — db.php 插入位置描述模糊

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§程式修改路徑、§資料庫變更；對照 `db.php` 第 111–133 行
- **章節**：資料庫變更
- **影響**：Design 說「在 audit_log 建表區塊之後、CREATE INDEX 區塊之前插入」。實際 `db.php` 中 audit_log 建表（第 111–120 行）與 CREATE INDEX（第 122–133 行）分屬兩個獨立 `$pdo->exec` 塊。若將 login_sessions 的建表與兩個索引拆開（建表放第 120 行後、索引混入第 122 行塊），可讀性較差且易誤刪。
- **嚴重度**：Minor
- **修正建議**：明確指示將 login_sessions 的 `CREATE TABLE` 與兩個 `CREATE INDEX` 作為一個完整的 `$pdo->exec` 塊，插在第 120 行（audit_log 建表結束）與第 122 行（第二個 exec 開始）之間，不與既有索引混在一起。
- **是否為阻塞項**：否。SQL 語法本身正確，僅影響可維護性。

### 5. Code — login.php 插入點與執行順序未具體化

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§程式修改路徑；對照 `login.php` 第 39–51 行
- **章節**：登入來源記錄
- **影響**：Design 僅說「password_verify 成功後」執行，未指出相對於 `logAudit('login', ...)` 與 `redirect('./dashboard.php')` 的順序。實作若放錯位置（例如 redirect 之後）會導致 session 標記未設定就導向，提醒不顯示。
- **嚴重度**：Major
- **修正建議**：明確指示在 `login.php` 第 49 行 `logAudit('login', ...)` 之後、第 50 行 `redirect('./dashboard.php')` 之前插入：90 天清理 DELETE → 寫入 login_sessions → 比對歷史 → 設定 session 標記。
- **是否為阻塞項**：否。須在實作時補正，不阻礙設計審查通過，但建議修正後再開發。

### 6. Code — dashboard.php 提醒插入位置未具體化

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§程式修改路徑；對照 `dashboard.php` 第 84–92 行
- **章節**：異常判定與提醒
- **影響**：Design 未指示在 dashboard.php 哪一處檢查 session 與顯示提醒。建議放在 `.page-head` 內、 h1 標題下方，複用 `.alert` 樣式。
- **嚴重度**：Minor
- **修正建議**：補充具體插入位置（建議：`dashboard.php` 第 90 行 `<p class="sub">` 之後、`<a class="btn primary">` 之前）。
- **是否為阻塞項**：否。

### 7. Code — is_first_seen 欄位語義未說明

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§資料庫變更；§程式修改路徑
- **章節**：資料庫變更
- **影響**：Design 規定寫入時 `is_first_seen=1`，但未說明第二次及之後從同一來源登入時應寫入 0 還是 1，以及此欄位在「異常判定」中的業務用途。欄位可能形同虛設。
- **嚴重度**：Minor
- **修正建議**：說明 is_first_seen 代表「該筆紀錄本身是否為該使用者首次見到此（ip, user_agent）組合」，寫入時依比對結果設 1/0；或若無業務用途則移除該欄位以簡化 schema。
- **是否為阻塞項**：否。

### 8. Security — dashboard 提醒訊息未明確要求 h() 轉義（XSS）

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§前端變更說明、§預期行為與驗證方法；對照專案硬性約束 4
- **章節**：前端變更說明
- **影響**：提醒內容包含 IP 與登入時間（`偵測到新登入來源：IP {ip}，時間 {login_at}...`）。Design 未說明顯示時必須經 `h()` 轉義。雖然 IP 來自 $_SERVER、login_at 來自系統寫入，但專案硬性約束要求「所有輸出經 h() 轉義」，未寫出即實作易遺漏，構成 XSS 風險（尤其若未來改由查詢結果或使用者可控欄位擴充）。
- **嚴重度**：Major
- **修正建議**：在 Design §前端變更說明 中補充「顯示 IP 與登入時間時必須經 h() 轉義」，驗證方法亦加入「確認提醒訊息經 h() 轉義後輸出」。
- **是否為阻塞項**：否。屬防禦性程式碼規範，建議修正後實作。

### 9. Security — audit_log 與 login_sessions IP 欄位重複儲存（設計可接受，非缺陷）

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§audit_log 與 login_sessions 分工；對照 `db.php` 第 117 行 audit_log.ip
- **章節**：audit_log 與 login_sessions 分工
- **影響**：audit_log 本身已有 ip 欄位，login_sessions 也記錄 ip，屬重複儲存。但兩表語義不同（audit_log 記錄單一登入事件來源，login_sessions 記錄登入來源歷史），設計可接受，不影響 IDOR。
- **嚴重度**：—（觀察項，無需修正）
- **修正建議**：無。
- **是否為阻塞項**：否。

### 10. Security — IDOR 權限檢查正確，無跨使用者洩漏

- **檔案位置**：`docs/login-anomaly-notice/Design.md`，§身分驗證與權限模型
- **章節**：身分驗證與權限模型
- **影響**：login_sessions 查詢以 `user_id = :user_id` 過濾，僅查詢當前登入使用者；dashboard 提醒僅基於當前 session。無 IDOR 問題。
- **嚴重度**：—（符合）
- **修正建議**：無。
- **是否為阻塞項**：否。

---

## 供使用者決定（範圍外建議，最多一行）

後續版本可考慮在帳戶設定頁面提供登入來源歷史檢視，或以 E-mail 發送新登入通知。

---

## 審查記錄（供 Design.md 同步）

| 階段 | 狀態 | 日期 | 備註 |
|------|------|------|------|
| Spec 審查 | 需修正 | 2026-11-21 | 判定邏輯與空值/UA 截斷需補充 |
| Code 審查 | 需修正 | 2026-11-21 | login.php / dashboard.php 插入點需具體化 |
| Security 審查 | 需修正 | 2026-11-21 | 提醒訊息需 h() 轉義；IDOR 符合 |

建議將上述發現回填至 `docs/login-anomaly-notice/Design.md` §設計審查記錄，三階段皆標註為「需修正」並說明修正內容後，再標示「通過」進入實作。
