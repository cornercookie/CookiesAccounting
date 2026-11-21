# Planning: 轉帳記錄（第三種記帳類型）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Planning: 轉帳記錄（第三種記帳類型） |
| 版本 | v1.1 |
| 建立日期 | 2026-11-21 |
| 更新日期 | 2026-11-21 |
| 章節變更摘要 | v1.1 補充文件基本資訊、Data Flow、migration 冪等性守衛、dashboard 轉帳顯示位置、CSS class 明確定義、非功能性需求章節，並釐清與現有程式碼的行號對應 |

## Description

為「全家人記帳系統」新增「轉帳」記帳類型，作為「收入」與「支出」之外的第三種類型。轉帳代表資金在帳戶間的移動（例如從活期存款轉到定期存款、從信用卡帳戶還款），本身不應增加總收入或總支出，避免虛增家庭收支彙總。

範圍限於單一帳本內的轉帳記錄；不支援跨帳本轉帳（跨帳本需在兩個帳本分別記錄，列為後續版本）。不新增資料庫表，僅擴充 `entries.type` CHECK 約束與相關查詢邏輯。

所有修改維持專案硬性約束：純 PHP + PDO 預備陳述式、SQLite WAL、整數分金額、CSRF、h() 轉義、./ 相對路徑、無框架、無 Composer、無 Node.js。

## User Story

- 作為家庭成員，我希望能記錄「從活期轉到定期」這種轉帳動作，而不會讓本月總支出虛增。
- 作為家庭成員，我希望轉帳記錄在彙總中單獨顯示，不被計入收入或支出，保持收支結餘的準確性。

## To Reproduce

本項目非錯誤（bug）類需求，不適用重現步驟。

## Expected behavior

### 資料庫結構變更
- `entries.type` CHECK 約束由 `CHECK (type IN ('income','expense'))` 擴充為 `CHECK (type IN ('income','expense','transfer'))`。
- `recurring_entries.type` 本期不擴充（轉帳不支援重複記錄，列為後續版本）。
- Migration 包在 `BEGIN IMMEDIATE` transaction 內，失敗則 rollBack，確保舊資料（income/expense）完整無遺失。

### 新增記錄表單（add_entry.php / edit_entry.php）
- 類型下拉選單新增第三個選項「轉帳」（中性顯示，不使用紅/藍色）。
- 轉帳記錄金額為正數（整數分），顯示時不加 `+` 或 `-` 前綴，類別標示為「轉帳」。
- 轉帳記錄儲存成功後，語音回饋內容為「轉帳 [金額] 元已儲存」。
- 轉帳記錄儲存後不顯示「傳票 XX-XXX 已立」訊息，改用一般成功訊息。
- 型別驗證白名單由 `['income', 'expense']` 擴充為 `['income', 'expense', 'transfer']`。

### 收支彙總（ledger.php / dashboard.php）
- 月收入／月支出彙總不包含轉帳記錄（現有 SQL 已用 `e.type = 'income'` / `e.type = 'expense'` 等號條件，轉帳已自然排除；防禦性再加上 `AND e.type != 'transfer'`）。
- 新增「本月轉帳總額」查詢（`WHERE type = 'transfer'`），單獨顯示。
- `dashboard.php` 各帳本彙總新增 `month_transfer` 欄位。
- `ledger.php` 分錄表中，轉帳記錄顯示為「轉帳」標籤（中性色），金額不加 `+` 或 `-` 前綴，不套用收入紅色／支出藍色樣式。
- 轉帳記錄不計入預算超支計算（預算僅針對支出）。

### 查帳篩選（ledger.php）
- 類型篩選下拉新增「轉帳」選項，可單獨檢視轉帳記錄。
- `$typeSel` 白名單由 `['', 'income', 'expense']` 擴充為 `['', 'income', 'expense', 'transfer']`。

### 類別統計（ledger.php）
- 類別支出統計圖表僅統計 `type = 'expense'`，不包含轉帳（現有邏輯已符合，無需變更）。

### 作廢記錄（delete_entry.php）
- 轉帳記錄可作廢（軟刪除），與現有邏輯一致，無需變更。

### 匯出 CSV（export_csv.php）
- 匯出 CSV 時，轉帳記錄的類型欄位顯示為「轉帳」（而非誤顯示為「支出」）。

### ai_parse.php（AI 一句話記帳）
- AI 不得回傳 `'transfer'` 類型（轉帳屬於手動確認的記帳行為，不納入 AI 自動解析範圍）。
- 前端下拉選單若已加入 `'transfer'` 選項，AI 若誤回傳仍可選中；本期 AI 僅回傳 `'income'` / `'expense'`，列為後續版本考量。

### 權限、CSRF、XSS
- 轉帳記錄的新增/編輯/作廢繼續使用現有 `ledger_access` 權限檢查（owner/member 可寫入，viewer 僅檢視），無需變更。
- 所有表單保留現有 CSRF token 與 `h()` 轉義，無需變更。

## Acceptance Criteria

1. `entries.type` CHECK 約束包含 `'transfer'`，資料庫可接受轉帳記錄。
2. 舊資料庫 migration 執行後，既有 `income`/`expense` 資料完整無遺失，`transfer` 型別可正常寫入。
3. Migration 重複執行（第二次請求 `ensureSchema`）不會重複插入資料或毀損資料（冪等性驗證）。
4. `add_entry.php` 與 `edit_entry.php` 類型下拉選單顯示三個選項：支出聯（藍）、收入聯（紅）、轉帳（中性色）。
5. 轉帳記錄不計入月收入或月支出彙總；轉帳總額單獨顯示。
6. `ledger.php` 分錄表中，轉帳記錄顯示為「轉帳」標籤，金額不加 `+` 或 `-` 前綴，不套用紅/藍色樣式，CSS class 使用 `.amt-neutral`。
7. `ledger.php` 查帳類型篩選新增「轉帳」選項，可單獨檢視轉帳記錄。
8. 轉帳記錄儲存成功後，語音回饋內容為「轉帳 [金額] 元已儲存」；不顯示「傳票 XX-XXX 已立」。
9. 轉帳記錄可作廢（軟刪除），作廢後不再出現在彙總與列表。
10. `export_csv.php` 匯出時，轉帳記錄類型欄位顯示為「轉帳」。
11. `dashboard.php` 各帳本彙總表格顯示「本月轉帳總額」欄位，中性顯示。
12. 無權限使用者（viewer / 無帳本權限者）無法新增或編輯轉帳記錄。
13. 所有輸出經 `h()` 轉義，所有寫入表單含 CSRF token。

## Additional context

### 資料庫 migration（db.php::ensureSchema）

SQLite 不支援直接修改 CHECK 約束，需透過表重建方式進行 migration。**必須加入冪等性守衛**，因為 `ensureSchema()` 在每次 `getPdo()`（每個請求）都會執行，若無守衛，第二次請求即會重複插入資料並毀損資料。

冪等性守衛採用「嘗試插入一筆 `type='transfer'` 測試記錄，成功則說明 CHECK 約束已支援，跳過 migration；失敗則說明尚未 migration，需執行表重建」：

1. **冪等性檢查**：嘗試 `INSERT INTO entries (ledger_id, user_id, amount_cents, type, category, entry_date, note, receipt_path, deleted) VALUES (0, 0, 1, 'transfer', '', '2000-01-01', '', '', 0)`。若成功（CHECK 約束已包含 `'transfer'`），立即 `DELETE FROM entries WHERE type='transfer' AND amount_cents=1 AND entry_date='2000-01-01'` 清除測試記錄，跳過整段 migration。若失敗（CHECK 約束不含 `'transfer'`），繼續執行後續步驟。
2. `DROP TABLE IF EXISTS entries_temp`：防止殘留臨時表。
3. `BEGIN IMMEDIATE`：使用 `$pdo->exec('BEGIN IMMEDIATE')`（PDO `beginTransaction()` 在 SQLite 預設為 `BEGIN DEFERRED`，無法提供即時寫入鎖，須改用 `exec`）。
4. 建立臨時表 `entries_temp`，結構與 `entries` 相同，但 CHECK 約束包含 `'transfer'`。
5. `INSERT INTO entries_temp SELECT * FROM entries`：複製所有既有資料。
6. `DROP TABLE entries`。
7. `ALTER TABLE entries_temp RENAME TO entries`。
8. 重建相關索引：`idx_entries_ledger_id`、`idx_entries_user_id`、`idx_entries_entry_date_deleted`、`idx_entries_type`。
9. `COMMIT`。
失敗時 `ROLLBACK`，確保資料不損毀。

Migration 掛載點：`db.php::ensureSchema()`，位置與既有 migration 風格一致（在 `audit_log` 建表之後、`login_sessions` 建表之前）。

### 資料流（Data Flow）

1. 使用者在 `add_entry.php` / `edit_entry.php` 類型下拉選單選擇「轉帳」，填入金額、類別、日期，提交表單。
2. 伺服端驗證 CSRF token、`ledger_access` 權限（owner/member 可寫入）、`type` 白名單（加入 `'transfer'`）、`toCents()` 整數分金額、`isValidDate()` 日期。
3. `INSERT INTO entries ... type='transfer'` 寫入資料庫，金額存為正數整數分。
4. `logAudit('entry_create', ...)` 記錄審計 Log。
5. 導回 `ledger.php?ledger_id=...&success=1&voucher=...&vtype=transfer`。
6. `ledger.php` 收支彙總查詢時，`WHERE type = 'income'` / `WHERE type = 'expense'` 已自然排除轉帳；額外查詢 `WHERE type = 'transfer'` 取得本月轉帳總額。
7. `ledger.php` 分錄表渲染時，轉帳記錄顯示「轉帳」標籤（`.amt-neutral` 中性色），金額不加 `+`/`-` 前綴。
8. `dashboard.php` 各帳本彙總表格顯示「本月轉帳總額」欄位，中性顯示。
9. `export_csv.php` 匯出時，轉帳記錄類型顯示為「轉帳」。

### ledger.php 具體修改點
- 第 266-269 行：`$typeSel` 白名單由 `['', 'income', 'expense']` 擴充為 `['', 'income', 'expense', 'transfer']`。
- 第 275-294 行：收入／支出彙總 SQL 已用等號條件 `e.type = 'income'` / `e.type = 'expense'`，轉帳已自然排除；防禦性再加上 `AND e.type != 'transfer'`；新增轉帳總額查詢 `SELECT COALESCE(SUM(amount_cents), 0) FROM entries WHERE ledger_id = :ledger_id AND deleted = 0 AND entry_date BETWEEN :ms AND :me AND type = 'transfer'`。
- 第 667-674 行：類型篩選下拉新增「轉帳」選項。
- 第 755 行：類型標籤由 `$entry['type'] === 'income' ? '收' : '支'` 改為三元判斷（income → 收，expense → 支，transfer → 轉帳）。
- 第 759 行：金額前綴由 `$entry['type'] === 'income' ? '+' : '-'` 改為三元判斷（income → +，expense → -，transfer → 空字串）。
- 第 755 行 CSS class：由 `'amt-in' : 'amt-out'` 改為三元判斷（income → `amt-in`，expense → `amt-out`，transfer → `amt-neutral`）。

### dashboard.php 具體修改點
- 第 31-39 行：各帳本 `month_income` / `month_expense` 子查詢加上 `AND e.type != 'transfer'`（防禦性）；新增 `month_transfer` 子查詢（`WHERE type = 'transfer'`）。
- 各帳本彙總表格（第 204-220 行 `<thead>`）：在「本月」群組（收入、支出、結餘）之後新增一欄「轉帳」，顯示該帳本本月轉帳總額，中性顯示（不加 +/- 前綴，不套用紅/藍色樣式）。
- 第 213-248 行 `<tbody>`：新增對應 `<td>` 輸出 `month_transfer` 數值，中性顯示。

### add_entry.php / edit_entry.php 具體修改點
- 第 79 / 97 行：`in_array($type, ['income', 'expense'], true)` 改為 `['income', 'expense', 'transfer']`。
- 第 319-322 / 248-251 行：類型下拉選單新增「轉帳」option。
- 第 116 / 142 行：語音回饋文字由 `($type === 'income' ? '收入' : '支出')` 改為三元判斷（income → 收入，expense → 支出，transfer → 轉帳）。
- 第 408-415 行（ledger.php）：傳票訊息條件由 `in_array($voucherType, ['income', 'expense'], true)` 維持不變，轉帳（`vtype=transfer`）不滿足條件，因此不顯示「傳票 XX-XXX 已立」，改用一般成功訊息。add_entry.php 傳遞 `vtype=transfer` 即可。

### export_csv.php 具體修改點
- 匯出 CSV 時，`type` 欄位顯示由 `$type === 'income' ? '收入' : '支出'` 改為三元判斷（income → 收入，expense → 支出，transfer → 轉帳）。

### ai_parse.php
- 現有程式碼第 168 行已嚴格限定 `in_array($parsed['type'], ['income', 'expense'], true)`，AI 不回傳 `'transfer'`。前端下拉選單若已加入 `'transfer'` 選項，AI 若誤回傳仍可選中，此為後續版本考量。

### 轉帳顯示顏色
- DESIGN.md 的雙色印刷規則只定義紅（收入）／藍（支出），轉帳應為中性色（墨色），CSS class 採用 `.amt-neutral`，在 Design.md 中定義樣式。

### 非功能性需求
- **效能**：轉帳記錄為單一筆寫入，查詢時多一個 `WHERE type = 'transfer'` 的 SUM 查詢，資料量極小，效能影響可忽略。
- **併發**：SQLite WAL 模式已啟用，migration 表重建包在 `BEGIN IMMEDIATE` 內，寫入競合可接受。
- **資料保留**：轉帳記錄與收入/支出記錄同樣保留，軟刪除後不顯示但不刪除實體資料。

### 非本期範圍（Out of Scope）
- 不新增跨帳本轉帳、不新增轉帳對象欄位、不新增轉帳報表、不新增轉帳預算。
- 不新增轉帳重複記錄（recurring_entries.type 不擴充）。
- AI 一句話記帳不回傳 `'transfer'` 類型。