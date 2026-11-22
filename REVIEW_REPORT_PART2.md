# 第三方專家審查報告（Part 2 / 4）

## 2. docs/ledger-statistics/planning.md

### 發現 LS-1（Major）：成員記帳進度會跨帳本洩漏成員記錄，缺少 ledger_id 範圍
- 檔案位置：`docs/ledger-statistics/planning.md`
- 章節：Expected behavior（第 27 行）、資料流（第 46 行）
- 影響：planning 說明「列出帳本內每位成員的 username 與本月已記錄筆數」，但資料流章節的 SQL 僅 `GROUP BY user_id 與 entry_date`，未加入 `ledger_id` 條件。儀表板已透過 `ledger_access` 限定可存取帳本，若進度查詢不加 `ledger_id` 過濾，會跨帳本彙整該使用者在所有帳本的記錄，導致成員進度數字錯誤，且可能讓使用者看到不屬於該帳本的成員活動（資訊洩漏）。
- 嚴重度：Major
- 修正建議：SQL 必須加入 `WHERE e.ledger_id = :ledger_id` 並與 `ledger_access` join，僅統計該帳本本月未刪除記錄；dashboard 需針對每個可存取帳本分別查詢進度，或明確標示是單一帳本視圖。
- 阻塞項：否（建議，但實作時必須修正，否則產生 IDOR/資料錯誤）

### 發現 LS-2（Major）：預算超支橫幅未說明僅限當月，可能對歷史月份顯示錯誤狀態
- 檔案位置：`docs/ledger-statistics/planning.md`
- 章節：Expected behavior（第 28 行）、資料流（第 47 行）
- 影響：planning 說明「本月支出 ≥80% 顯示警告、≥100% 顯示超支」，但未限定「僅當月」。現有 ledger.php 預算邏輯已以 `$isCurrentMonth` 限制（僅當月且 budget>0 才計算），若 statistics 功能直接複用 `monthly_budget_cents` 與 `expenseCents` 而不檢查 `$ym === 本月`，會在查看歷史月份時錯誤顯示超支橫幅。
- 嚴重度：Major
- 修正建議：在 planning 明確規定預算超支橫幅僅在「當月（Asia/Taipei）」顯示，歷史月份不顯示；dashboard 與 ledger.php 皆須加入 `$isCurrentMonth` 判斷。
- 阻塞項：否

### 發現 LS-3（Minor）：列印友善月結單未說明隱藏哪些區塊與 CSS 類別對應
- 檔案位置：`docs/ledger-statistics/planning.md`
- 章節：資料流（第 48 行）
- 影響：planning 提及隱藏 `.side`、`.nav`、`.ctr-actions`，但現有 HTML 中側欄為 `.side`、導覽為 `.nav`、操作欄為 `.ctr-actions`，此對應正確；但未說明 `@media print` 如何嵌入（內嵌 `<style>` 還是外部 CSS），且三聯合計牌（`.plates`）與分錄表（`.table-wrap`）在列印時是否保留分頁斷行，缺乏細節。
- 嚴重度：Minor
- 修正建議：補充 `@media print` 實作位置（建議內嵌於 ledger.php `<head>`），並說明 `.plates` 與 `.table` 分頁行為（`page-break-inside: avoid`）。
- 阻塞項：否

### 發現 LS-4（Minor）：SVG 圖表失敗回退機制與金額格式未定義
- 檔案位置：`docs/ledger-statistics/planning.md`
- 章節：Expected behavior（第 30 行）、錯誤處理（第 31 行）
- 影響：planning 說明 SVG 產生失敗時顯示文字統計，但未定義文字統計的格式（表格？純文字？）；金額顯示是否使用 `formatCents()`（整數分除以 100、千分位），若直接輸出 `amount_cents` 會顯示錯誤數字（如 12000 元而非 120.00 元）。
- 嚴重度：Minor
- 修正建議：規定圖表與文字統計的金額皆經 `formatCents()` 格式化；失敗回退為 HTML 表格（類別＋金額＋佔比），同樣經 h() 轉義。
- 阻塞項：否

### 發現 LS-5（Minor）：成員記帳進度「不含作廢記錄」與 dashboard 現有查詢不一致
- 檔案位置：`docs/ledger-statistics/planning.md`
- 章節：Expected behavior（第 27 行）
- 影響：planning 規定進度「不含作廢記錄（deleted=0）」，但 dashboard 現有 `$entryCount` 查詢已含 `deleted = 0`，而各帳本彙總的 `entry_count` 子查詢也含 `deleted = 0`，此點一致；但若進度查詢漏加 `deleted = 0`，會將作廢記錄計入，與 Acceptance Criteria 1（與 entries 表中未刪除記錄一致）矛盾。
- 嚴重度：Minor
- 修正建議：在 SQL 中明確加上 `AND e.deleted = 0`。
- 阻塞項：否

---

## 3. docs/recurring-entries/planning.md

### 發現 RE-1（Critical / 阻塞項）：db.php migration 缺乏舊資料庫遷移邏輯，僅「CREATE TABLE IF NOT EXISTS」不足
- 檔案位置：`docs/recurring-entries/planning.md`
- 章節：資料庫結構變更（第 38 行）
- 影響：planning 說明「需在 db.php::ensureSchema() 補上 CREATE TABLE IF NOT EXISTS... 舊資料庫首次執行時自動建立，不需手動 migration」。現有 db.php ensureSchema 已有舊版 migration 範例（透過 PRAGMA table_info 檢查欄位並 ALTER TABLE）。recurring_entries 是全新資料表，`CREATE TABLE IF NOT EXISTS` 對新資料庫足夠，但對「舊資料庫首次執行」亦適用（表不存在即建立）。然而 planning 未說明 `ensureSchema` 內需插入哪段 SQL、索引建立順序，且未定義 `is_active` 欄位的 CHECK 約束（僅說「預設 1」），若後續程式碼誤寫非 0/1 值會導致邏輯錯誤。此項因直接關係 schema 是否正確建立，列為阻塞項。
- 嚴重度：Critical
- 修正建議：在 planning 資料庫結構變更章節給出完整 `CREATE TABLE` SQL（含 `CHECK (is_active IN (0,1))`、`type CHECK (type IN ('income','expense'))`、`start_date` 格式約束）與 `ensureSchema` 插入位置；索引名稱避免與現有重複（現有已有 idx_entries_ledger_id 等，recurring 索引應以 `idx_recurring_` 前綴，planning 已符合）。
- 阻塞項：是。依據：db.php 是全站單一初始化入口，schema 定義錯誤會導致 recurring_entries 所有寫入失敗，且舊資料庫無自動遷移路徑。

### 發現 RE-2（Major）：自動產生 vs 僅提醒機制與「下次預計日期」計算邏輯未定義
- 檔案位置：`docs/recurring-entries/planning.md`
- 章節：Description（第 5 行）、Expected behavior（第 23 行）、待確認（第 36 行）
- 影響：planning 決定「僅提醒，由使用者手動點擊立即記一筆」，但未定義「下次預計記錄日期」如何計算（起始日期＋1 個月？上次產生日期＋1 個月？），亦未定義「本月尚未產生」的判定標準（以 entries 表中當月是否有對應 recurring 記錄？）。這會導致提醒顯示錯誤或重複提醒。
- 嚴重度：Major
- 修正建議：補充 `next_due_date` 欄位（或以 start_date＋週期推算），並定義「本月已產生」的判定：查詢 entries 表中當月是否有來源自該 recurring_records 的記錄（需新增 `recurring_source_id` 欄位，或以名稱＋金額＋日期比對，前者較可靠）。
- 阻塞項：否（建議，但實作前必須釐清）

### 發現 RE-3（Major）：權限邊界未說明 member 編輯／停用他人建立的重複記錄
- 檔案位置：`docs/recurring-entries/planning.md`
- 章節：身分驗證與權限（第 40 行）、Expected behavior（第 20 行）
- 影響：planning 說明「帳本擁有者（owner）或管理員（admin）可建立」，「一般 member 與 viewer 僅可檢視列表，不可建立」，但未說明「編輯／停用」權限歸屬：member 能否停用自己建立的重複記錄？owner 能否編輯其他 member 建立的？缺乏此規則會導致 IDOR（透過 ledger_id＋id 直接 POST 修改他人重複記錄）。
- 嚴重度：Major
- 修正建議：明確規定：僅建立者（user_id）或帳本 owner/站台 admin 可編輯／停用；所有修改皆需透過 ledger_access 驗證該使用者對該 ledger_id 的存取權，並比對 `recurring_entries.user_id`（建立者）或 ledger_access.role。
- 阻塞項：否

### 發現 RE-4（Major）：audit_log 動作代碼與「立即記一筆」的 entries 關聯缺乏追蹤欄位
- 檔案位置：`docs/recurring-entries/planning.md`
- 章節：Acceptance Criteria（第 30 行）、資料流（第 39 行）
- 影響：planning 說明點擊「立即記一筆」後建立 entries 記錄並記錄 audit_log（entry_create），但未定義該 entries 記錄與原始 recurring_records 的關聯（無法事後知道哪幾筆 entries 是由哪個重複記錄產生），且 audit_log 動作代碼 `admin_ledger_recurring_create` 與現有 admin_* 命名慣例不符（recurring 非 admin 專用，owner/member 皆可建立）。
- 嚴重度：Major
- 修正建議：在 recurring_entries 新增 `id` 作為來源鍵，entries 表新增可選欄位 `recurring_source_id INTEGER`（FK 至 recurring_entries.id），audit_log 動作代碼改為 `recurring_create`（建立重複記錄）與 `recurring_generate`（手動產生 entries），detail 欄位帶入 recurring_entries.id 與 ledger_id。
- 阻塞項：否

### 發現 RE-5（Minor）：金額大於 0 的正整數分驗證位置未說明
- 檔案位置：`docs/recurring-entries/planning.md`
- 章節：錯誤處理（第 24 行）
- 影響：planning 規定金額必須為大於 0 的正整數分，但未說明驗證在前端（HTML5 min=0）還是後端（toCents 搭配 `> 0` 判斷）。後端務必伺服端驗證，前端僅為輔助。
- 嚴重度：Minor
- 修正建議：規定後端以 `toCents()` 解析後額外判斷 `$cents > 0`，失敗回錯誤訊息；不依賴前端。
- 阻塞項：否

### 發現 RE-6（Minor）：重複記錄長期保留無清理機制，可能累積髒資料
- 檔案位置：`docs/recurring-entries/planning.md`
- 章節：非功能性需求（第 42 行）
- 影響：planning 說明「長期保留資料，不設自動刪除，使用者可手動停用或刪除」，但未提供「刪除」入口（Acceptance Criteria 僅有建立、編輯、停用），使用者無法移除不再需要的重複記錄，長期累積。
- 嚴重度：Minor
- 修正建議：補充「刪除重複記錄」功能（同樣需 owner/建立者權限＋CSRF＋audit_log `recurring_delete`），或明確註記刪除列為後續版本。
- 阻塞項：否

---

（續於 Part 3：login-anomaly-notice 發現與「供使用者決定」範圍外建議）
