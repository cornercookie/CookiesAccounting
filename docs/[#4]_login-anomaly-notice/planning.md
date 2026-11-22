# Planning: 登入異常通知（新裝置／新 IP 登入提醒）

## Description

為登入流程新增「登入異常通知」功能，記錄每次成功登入的 IP 位址與 User-Agent，當使用者從先前未使用過的 IP 或裝置登入時，在下次登入的儀表板顯示提醒（例如「您上次於 2026-11-20 從 1.2.3.4 登入，是本機首次從此 IP 登入」），協助家庭成員及早發現帳號可能被冒用。

範圍限於登入記錄、異常判定與提醒顯示；不包含雙因素驗證、登入封鎖或密碼重設等既有安全機制的擴充。

## User Story

- 作為家庭成員，我希望從不常用的裝置或網路登入時，系統提醒我這是新登入來源，以便確認是否為本人操作。
- 作為管理員，我希望能在儀表板看到帳號近期的登入來源紀錄，及早發現異常登入。

## To Reproduce

本項目非錯誤（bug）類需求，不適用重現步驟。

## Expected behavior

- **登入來源記錄**：每次成功登入後，系統記錄該使用者的登入 IP 與 User-Agent 至新資料表 login_sessions（或類似名稱），記錄包含使用者 ID、IP、User-Agent、登入時間。
- **異常判定**：下次登入時，系統將目前 IP 與 User-Agent 與該使用者歷史紀錄比對；若 IP 或 User-Agent 任一為該使用者從未使用過的來源，視為「新登入來源」。家庭環境常見同一 Wi-Fi 下不同裝置共享相同公網 IP，納入 User-Agent 比較可降低誤報；若同一 IP 不同裝置皆不應視為異常，則僅以 IP 做為主要判定，User-Agent 僅供參考。
- **提醒顯示**：偵測到新登入來源後，於登入成功導向的儀表板頂部顯示提醒訊息（例如「偵測到新登入來源：IP 1.2.3.4，時間 2026-11-21 09:00，若非本人操作請立即變更密碼」）；「已顯示」範圍為僅本次 session，於 dashboard.php 顯示後清除 session 標記，不同裝置／瀏覽器各自顯示一次；同一新來源於同一 session 內僅顯示一次，避免重複刷新重複播報。
- **歷史檢視**：管理員可在「操作紀錄」（audit_log）頁面查看登入紀錄，或在帳戶設定頁面查看近期登入來源列表（視現有 change_password.php 頁面擴充）。
- **錯誤處理**：登入來源記錄失敗不阻擋正常登入流程；IP 或 User-Agent 為空時不視為異常，不顯示提醒；資料庫寫入失敗時改寫 error.log，不影響使用者登入。

## Acceptance Criteria

1. 成功登入後，系統記錄使用者 IP 與 User-Agent。
2. 從新 IP 登入時，儀表板顯示登入來源提醒；從已使用過的 IP 登入時不顯示。
3. 提醒訊息包含 IP 位址與登入時間，並使用繁體中文。
4. 無帳號或權限檢查繞過情形：僅登入者本人可看到自身的登入來源提醒。
5. 登入來源記錄失敗不阻擋登入，且錯誤寫入 error.log。

## Additional context

- **待確認**：無（本期已決定：同一新 IP 僅顯示一次，於 session 中標記已顯示，避免重複騷擾；是否需要讓使用者在帳戶頁面主動檢視所有歷史登入來源，本期不建立，視為後續版本；僅記錄 User-Agent，不額外記錄瀏覽器指紋，考量隱私）。
- **非本期範圍**：不新增雙因素驗證、登入封鎖（現有 login_attempts 已有 15 分鐘鎖定機制）、密碼重設通知 Email 或簡訊通知；不建立獨立的帳戶安全設定頁面。
- **資料庫結構變更**：新增 login_sessions 表，完整 SQL 如下，需在 `db.php::ensureSchema()` 的 `CREATE TABLE IF NOT EXISTS audit_log` 區塊之後、`CREATE INDEX` 區塊之前插入：

```sql
CREATE TABLE IF NOT EXISTS login_sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    ip           TEXT    NOT NULL DEFAULT '',
    user_agent   TEXT    NOT NULL DEFAULT '',
    login_at     TEXT    NOT NULL,
    is_first_seen INTEGER NOT NULL DEFAULT 1 CHECK (is_first_seen IN (0,1)),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_login_sessions_user_id  ON login_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_login_sessions_login_at ON login_sessions(login_at);
```

舊資料庫首次執行 `ensureSchema` 時若表不存在即自動建立，不需手動 migration；若未來需新增欄位，沿用現有 `PRAGMA table_info` + `ALTER TABLE` 範式（參照 db.php 第 135-171 行）。

- **IP 與 User-Agent 處理**：IP 寫入前不做遮掩（家庭場景，僅記錄完整 IP 以利使用者判斷登入來源）；User-Agent 寫入前以 PHP `mb_substr($ua, 0, 255)` 截斷，防止過長字串灌爆資料庫。
- **資料流**：使用者於 login.php 提交帳密 → password_verify 成功 → 記錄 audit_log（login，detail 為空） → 寫入 login_sessions（含 ip、user_agent、login_at、is_first_seen=1） → 比對該使用者歷史 login_sessions 的 ip 與 user_agent → 若任一為新來源，於 session 中設定 `show_login_notice=1` 並存入新來源資訊（ip、login_at） → 導回 dashboard.php → dashboard.php 檢查 session 並顯示提醒 → 顯示後清除 session 標記。
- **90 天資料保留**：在 `login.php` 登入成功後、寫入 login_sessions 之前，單獨執行 `DELETE FROM login_sessions WHERE login_at < :cutoff`（cutoff = 90 天前，Asia/Taipei），不放入 `getPdo()`，避免將資料庫鎖定開銷帶入所有頁面。長期未登入的帳號舊紀錄僅在下次登入時清理，可接受。
- **audit_log 與 login_sessions 分工**：audit_log 記錄登入動作（action = 'login'，detail 為空），login_sessions 記錄登入來源（ip、user_agent、login_at），兩表分離存放，不重複建立查詢頁面。管理員查看登入來源歷史檢視列為後續版本，本期僅保留儀表板提醒。
- **身分驗證與權限**：繼續使用現有 session 登入機制；登入來源記錄與提醒僅對應目前登入使用者，不跨使用者洩漏；audit_log 已記錄 login 事件，本項為補充來源資訊。
- **資料傳輸加密**：IP 與 User-Agent 皆為 HTTP 請求本身攜帶資訊，不額外傳輸至外部伺服器；資料僅儲存在站台自身 SQLite 資料庫。
- **非功能性需求**：login_sessions 預期資料量與登入次數成正比，家庭使用場景下資料量極小；90 天保留期限，登入時自動清理過期紀錄。

