# 第三方專家審查報告（Part 4 / 4）

## 跨文件矛盾與共通問題

### 發現 C-1（Major）：四份 planning 均未統一 db.php ensureSchema 插入位置與舊資料庫遷移範式
- 涉及檔案：`docs/recurring-entries/planning.md`（第 38 行）、`docs/login-anomaly-notice/planning.md`（第 38 行）
- 影響：兩份 planning 皆說明「需在 db.php::ensureSchema() 補上 CREATE TABLE IF NOT EXISTS」，但 db.php 現有 ensureSchema 已以 `CREATE TABLE IF NOT EXISTS` 建立所有表，並以 `PRAGMA table_info` + `ALTER TABLE` 處理舊資料庫欄位遷移。兩份 planning 都未說明新 SQL 插入於 ensureSchema 的哪一個 heredoc 區塊中，亦未說明舊資料庫若表已存在但欄位缺失（例如 recurring_entries 某日需新增欄位）如何處理。實作時兩開發者可能插入不同位置，造成 schema 順序不一致。
- 嚴重度：Major
- 修正建議：統一規範：新資料表在 `CREATE TABLE IF NOT EXISTS audit_log` 之後、`CREATE INDEX` 區塊之前插入；新欄位遷移沿用現有 `PRAGMA table_info` + `ALTER TABLE` 範式。兩份 planning 皆應參照 db.php 第 122-171 行的舊版 migration 寫法補充。
- 阻塞項：否

### 發現 C-2（Minor）：audit_log 動作代碼命名慣例不一致
- 涉及檔案：`docs/recurring-entries/planning.md`（`admin_ledger_recurring_create`）、`docs/login-anomaly-notice/planning.md`（`login`）、現有 admin/audit_log.php（第 16-34 行）
- 影響：現有 audit_log 動作代碼以 `entry_`、`ledger_`、`admin_user_`、`admin_ledger_` 為前綴，recurring-entries 的 `admin_ledger_recurring_create` 暗示僅 admin 可建立，與 planning 權限章節（owner 亦可建立）矛盾。login-anomaly-notice 使用 `login` 與現有一致，但若新增登入異常動作，應以 `login_new_source` 等命名，避免與現有 `login` 混淆。
- 嚴重度：Minor
- 修正建議：recurring 動作代碼改為 `recurring_create` / `recurring_generate` / `recurring_delete`；登入異常新動作代碼 `login_new_source`；並在 admin/audit_log.php 的 `$actionLabels` 補充對應中文標籤。
- 阻塞項：否

### 發現 C-3（Minor）：ledger_id / entry_id 參數名不統一（ledger_id 與 id 混用）
- 涉及檔案：所有 planning（參考現有 add_entry.php 第 15 行、`$_GET['ledger_id'] ?? $_GET['id']`）
- 影響：現有程式碼同時接受 `ledger_id` 與舊式 `id` 參數，planning 未說明新功能（ recurring 列表、登入來源）應採用哪一參數名，實作時可能遺漏舊式相容。
- 嚴重度：Minor
- 修正建議：新功能統一使用 `ledger_id`，不再相容舊式 `id`；或在 planning 說明參數名慣例。
- 阻塞項：否

---

## 審查結論與行動清單

總體評語：**需修正**。阻塞項共兩項（RE-1、LA-1），皆集中於 db.php migration 與資料保留期限的缺乏細節；Major 項共九項（Q-1、LS-1、LS-2、RE-2、RE-3、RE-4、LA-2、LA-3、LA-4、C-1），需在實作前釐清。

開發者應優先處理：
1. 兩份 planning（recurring-entries、login-anomaly-notice）補充完整 `CREATE TABLE` SQL 與 `ensureSchema` 插入位置。
2. login-anomaly-notice 定義 90 天清理執行時機（建議在 login.php 登入成功後單獨執行 DELETE，不放入 getPdo()）。
3. recurring-entries 定義 `next_due_date` 與「本月已產生」判定標準，並補 `recurring_source_id` 欄位以建立 entries 關聯。
4. ledger-statistics 成員記帳進度 SQL 加入 `ledger_id` 與 `deleted = 0`；預算超支橫幅加入 `$isCurrentMonth` 限制。

完成上述修正後，四份 planning 可進入實作階段。
