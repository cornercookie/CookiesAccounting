# Planning: 重複記錄（固定每月開銷）

## Description

為家庭帳本新增「重複記錄」功能，讓固定每月開銷（水費、電費、房租、薪水等）不需每月重新手動輸入。使用者可設定重複項目的金額、類別、類型與起始日期，系統在使用者登入時檢查並提醒補記，由使用者手動點擊「立即記一筆」產生一般 entries 記錄，減少重複操作與遺漏記錄。

範圍限於重複記錄的設定、列表、啟用／停用與手動產生記錄；不包含完全自動化的背景排程（cPanel 共享主機 cron 設定由使用者自行決定，本期僅依賴登入時檢查並提醒）。不新增重複記錄刪除入口，刪除列為後續版本。

## User Story

- 作為家庭成員，我希望設定水費電費等固定開銷為「每月重複記錄」，系統提醒我每月補記，避免遺漏。
- 作為帳本擁有者，我希望在重複記錄列表中可啟用或停用固定開銷，不需每次都重新輸入相同金額與類別。

## To Reproduce

本項目非錯誤（bug）類需求，不適用重現步驟。

## Expected behavior

- **建立重複記錄**：帳本擁有者（owner）或管理員（admin）可在帳本詳情頁建立重複記錄，填入名稱、金額、類別、類型（收入／支出）、起始日期（start_date）、下次預計記錄日期（next_due_date，預設為起始日期＋1 個月）；系統儲存至 recurring_entries 資料表。
- **重複記錄列表**：帳本詳情頁顯示「固定開銷」列表，列出所有啟用中的重複項目，包含下次預計記錄日期（next_due_date）、金額、類別；使用者可點擊「立即記一筆」將該筆重複記錄轉為一般 entries 記錄。
- **啟用／停用**：僅重複記錄建立者（recurring_entries.user_id）或帳本 owner／站台 admin 可將重複記錄設為停用（is_active = 0），停用後不再出現在「立即記一筆」列表，也不產生提醒；一般 member 與 viewer 僅可檢視列表，不可建立、編輯、停用；既有已產生的 entries 記錄不受影響。
- **登入提醒**：使用者登入後，系統查詢該帳本啟用中的重複記錄，若 entries 表中本月（Asia/Taipei）尚無來源自該重複記錄的記錄（entries.recurring_source_id 與 recurring_entries.id 對應），則顯示提醒「有 X 筆固定開銷尚未記帳」，點擊後前往重複記錄列表。
- **錯誤處理**：金額必須為大於 0 的正整數分（後端以 toCents() 解析後額外判斷 $cents > 0）；起始日期與下次預計日期必須為有效日期；重複記錄屬於特定帳本，使用者無該帳本 ledger_access 權限時無法建立或檢視；資料庫寫入失敗時顯示錯誤訊息，不靜默忽略。

## Acceptance Criteria

1. 帳本擁有者可建立重複記錄，並儲存至 recurring_entries 資料表；建立者（user_id）或帳本 owner／站台 admin 可編輯、停用；一般 member 與 viewer 僅可檢視。
2. 帳本詳情頁顯示啟用中的重複記錄列表，包含下次預計日期、金額、類別。
3. 點擊「立即記一筆」後，系統以該重複記錄的金額、類別、類型建立一筆 entries 記錄，entries.recurring_source_id 寫入 recurring_entries.id，並記錄 audit_log（動作代碼 recurring_generate，detail 帶入 recurring_entries.id 與 ledger_id）。
4. 登入後若本月有未記錄的重複項目，儀表板顯示提醒並連結至列表。
5. 無帳本權限者無法建立或檢視該帳本的重複記錄；金額不得為負數或零。

## Additional context

- **待確認**：無（本期已決定：僅顯示提醒，由使用者手動點擊「立即記一筆」產生 entries 記錄；不預設 cron job，僅依賴登入時檢查；本期僅支援每月週期，不支援每週或每季）。
- **非本期範圍**：不新增完全自動化的背景排程、不支援重複記錄的自動扣款或與銀行串接、不新增重複記錄的匯出功能。
- **資料庫結構變更**：新增 recurring_entries 表，完整 SQL 如下，需在 `db.php::ensureSchema()` 的 `CREATE TABLE IF NOT EXISTS audit_log` 區塊之後、`CREATE INDEX` 區塊之前插入：

```sql
CREATE TABLE IF NOT EXISTS recurring_entries (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    ledger_id      INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    name           TEXT    NOT NULL DEFAULT '',
    amount_cents   INTEGER NOT NULL CHECK (amount_cents > 0),
    type           TEXT    NOT NULL CHECK (type IN ('income','expense')),
    category       TEXT    NOT NULL DEFAULT '',
    start_date     TEXT    NOT NULL,
    next_due_date  TEXT    NOT NULL,
    is_active      INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
    FOREIGN KEY (user_id)   REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_recurring_entries_ledger_id ON recurring_entries(ledger_id);
CREATE INDEX IF NOT EXISTS idx_recurring_entries_user_id   ON recurring_entries(user_id);
CREATE INDEX IF NOT EXISTS idx_recurring_entries_next_due  ON recurring_entries(next_due_date);
```

entries 表新增可選欄位 `recurring_source_id INTEGER`（FK 至 recurring_entries.id），用於關聯「立即記一筆」產生的 entries 與原始重複記錄。舊資料庫首次執行 `ensureSchema` 時若表不存在即自動建立，不需手動 migration；若未來需新增欄位，沿用現有 `PRAGMA table_info` + `ALTER TABLE` 範式（參照 db.php 第 135-171 行）。

- **資料流**：使用者在帳本詳情頁建立重複記錄 → POST → 驗證 CSRF 與 ledger_access 權限（owner 或 admin 才可建立） → 寫入 recurring_entries → 寫入 audit_log（動作代碼 `recurring_create`，detail 帶入 recurring_entries.id 與 ledger_id）→ 回傳列表頁；使用者點擊「立即記一筆」→ 驗證 CSRF 與權限 → 建立 entries 記錄（recurring_source_id = recurring_entries.id） → 寫入 audit_log（動作代碼 `recurring_generate`）→ 導回 ledger.php。
- **身分驗證與權限**：僅重複記錄建立者（recurring_entries.user_id）或帳本 owner／站台 admin 可編輯／停用；所有修改皆需透過 ledger_access 驗證該使用者對該 ledger_id 的存取權，並比對 recurring_entries.user_id；一般 member 與 viewer 僅可檢視列表，不可建立、編輯、停用。
- **資料傳輸加密**：無外部 API 呼叫，全部為伺服端 SQL 與 session 驗證。
- **非功能性需求**：recurring_entries 表預期資料量極小（每個帳本數十筆以內），查詢效能不成問題；重複記錄為長期保留資料，不設自動刪除，刪除功能列為後續版本。

