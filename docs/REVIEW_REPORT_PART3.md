# 第三方專家審查報告（Part 3 / 4）

## 4. docs/login-anomaly-notice/planning.md

### 發現 LA-1（Critical / 阻塞項）：db.php migration 缺乏舊資料庫遷移邏輯與資料保留期限實作細節
- 檔案位置：`docs/login-anomaly-notice/planning.md`
- 章節：資料庫結構變更（第 38 行）、非功能性需求（第 42 行）、前次審查 4-1 / 4-2
- 影響：planning 說明「需在 db.php::ensureSchema() 補上 CREATE TABLE IF NOT EXISTS login_sessions... 舊資料庫首次執行時自動建立，不需手動 migration」，但未給出完整 SQL（欄位型別、長度、預設值、索引語句），且「90 天保留期限：登入時自動刪除超過 90 天的舊紀錄」未定義刪除 SQL 與執行位置（在 login.php 登入成功後？仍在 getPdo()？）。若在每次 getPdo() 執行 DELETE，會將資料庫鎖定開銷帶入所有頁面；若只在登入時執行，舊紀錄僅在登入時清理，長期未登入的帳號舊紀錄不會被清。此外 planning 未說明 `is_first_seen` 如何維護（標記為 0 後誰負責？）、User-Agent 截斷至 255 字元的截斷位置（PHP mb_substr 還是 SQL），這些屬前次審查 4-1 / 4-2 尚未修正的阻塞項。
- 嚴重度：Critical
- 修正建議：在 planning 補充完整 `CREATE TABLE login_sessions` SQL（含 `user_agent TEXT NOT NULL DEFAULT ''`、`ip TEXT NOT NULL DEFAULT ''`、`is_first_seen INTEGER NOT NULL DEFAULT 1 CHECK (is_first_seen IN (0,1))`、`login_at TEXT NOT NULL`），索引 `idx_login_sessions_user_id`、`idx_login_sessions_login_at`；資料保留：在 `login.php` 登入成功後單獨執行 `DELETE FROM login_sessions WHERE login_at < :cutoff`（cutoff = 90 天前），不放入 getPdo()；補充管理員手動清理入口（規劃中已排除，建議至少在後台 audit_log 頁旁提供 SQL 說明，不必建立頁面）。
- 阻塞項：是。依據：db.php 是全站初始化入口，schema 語句缺失會導致 login_sessions 寫入失敗；90 天清理未定義執行時機，會造成資料無限累積，違反資料保留約束。

### 發現 LA-2（Major）：敏感資訊（IP、User-Agent）記錄缺乏最小化與存取控制
- 檔案位置：`docs/login-anomaly-notice/planning.md`
- 章節：資料庫結構變更（第 38 行）、資料流（第 39 行）、身分驗證與權限（第 40 行）
- 影響：planning 記錄完整 IP 與完整 User-Agent，但未說明 IP 是否需要遮掩（例如 IPv4 遮掩最後一段、IPv6 遮掩至 /64），以減少若資料庫外洩時的使用者定位風險；User-Agent 可能包含裝置型號與系統版本，屬敏感裝置指紋。此外「歷史檢視」規劃說管理員可在 audit_log 查看登入紀錄，但 audit_log.action 僅有 'login'，不包含 IP 來源變更資訊（login_sessions 才有），管理員實際上無法在 audit_log 看到「從新 IP 登入」的來源細節，只能看到登入動作本身。
- 嚴重度：Major
- 修正建議：IP 寫入前遮掩（建議 IPv4 儲存前兩段，如 `1.2.3.4` → `1.2.0.0`；IPv6 取前 4 組），或在 planning 說明為何不遮掩（家庭場景低風險）。User-Agent 維持截斷 255 字元。audit_log 與 login_sessions 分離存放是合理的，但 planning 應說明管理員查看登入來源應透過 login_sessions 表（需建立查詢頁面或在後台新增區塊），而非期望 audit_log 提供此資訊。
- 阻塞項：否

### 發現 LA-3（Major）：異常判定僅比對 IP，忽略 User-Agent 變更；同一 IP 不同裝置不視為異常
- 檔案位置：`docs/login-anomaly-notice/planning.md`
- 章節：Expected behavior（第 21 行）、待確認（第 36 行）
- 影響：planning 規定「若 IP 為該使用者從未使用過的來源，視為新登入來源」，僅比對 IP，不比對 User-Agent。家庭環境常見同一 Wi-Fi 下不同裝置（手機／筆電）共享相同公網 IP，此情況不會觸發提醒，降低異常偵測效能；反之，同一裝置切換網路（行動數據 vs Wi-Fi）IP 變更會觸發提醒，可能誤報。planning 待確認已決定「僅記錄 User-Agent，不額外記錄瀏覽器指紋」，但未決定判定是否納入 User-Agent。
- 嚴重度：Major
- 修正建議：在 planning 明確判定規則（建議：IP 或 User-Agent 任一為新來源即視為異常，或僅 IP 做為主要判定並說明誤報可接受）；若納入 User-Agent，資料表需加欄位或以 ip＋user_agent 組合判斷。
- 阻塞項：否

### 發現 LA-4（Major）：提醒「僅顯示一次」的 session 標記在跨裝置/多開瀏覽器下行為未定義
- 檔案位置：`docs/login-anomaly-notice/planning.md`
- 章節：待確認（第 36 行）、資料流（第 39 行）
- 影響：planning 說明「於 session 中標記已顯示，避免重複騷擾」。session 是依瀏覽器／裝置隔離的，使用者在裝置 A 登入看到提醒後，再於裝置 B 登入同一新 IP，裝置 B 仍會顯示提醒（因為是不同 session），此行為是否符合預期未說明。此外「次次登入時不再重複顯示同一來源」與「僅顯示一次」略有出入（是永久不再顯示，還是該 session 內不再顯示？）。
- 嚴重度：Major
- 修正建議：釐清「已顯示」的範圍（僅本次 session，還是寫入資料庫標記該使用者已確認過該來源）；建議採「本次 session 內顯示一次，不同裝置各自顯示」，並說明於 dashboard.php 顯示後清除 session 標記。
- 阻塞項：否

### 發現 LA-5（Minor）：登入來源記錄失敗不阻擋登入，但 audit_log login 事件已存在，login_sessions 與 audit_log 重複記錄 IP
- 檔案位置：`docs/login-anomaly-notice/planning.md`
- 章節：錯誤處理（第 24 行）、資料流（第 39 行）
- 影響：現有 login.php 已在 `logAudit('login', ...)` 中記錄 `ip = $_SERVER['REMOTE_ADDR']`，login_sessions 又記錄完整 IP 與 User-Agent，兩表重複儲存 IP。planning 說明「本項為補充來源資訊」，但未說明兩表關聯（login_sessions 無法回溯至 audit_log.id），事前審計（audit_log）與事後溯源（login_sessions）資訊不一致。
- 嚴重度：Minor
- 修正建議：保留兩表分離（audit_log 記錄動作，login_sessions 記錄來源），但登入時 `logAudit('login')` 可在 detail 欄位簡要帶來源（如 `new_ip=1`），或 planning 說明兩表各自用途以避免重複建立查詢頁面。
- 阻塞項：否

### 發現 LA-6（Minor）：歷史檢視頁面未規劃，管理員無法查看登入來源列表
- 檔案位置：`docs/login-anomaly-notice/planning.md`
- 章節：Expected behavior（第 23 行）、非本期範圍（第 37 行）
- 影響：planning 說明管理員可在 audit_log 查看登入紀錄，或在帳戶設定頁面查看近期登入來源（視 change_password.php 擴充），但非本期範圍明確排除「不建立獨立的帳戶安全設定頁面」，導致登入來源歷史檢視沒有實作落點，功能形同虛設。
- 嚴重度：Minor
- 修正建議：至少在後台 `admin/audit_log.php` 新增登入來源區塊（查詢 login_sessions，依 user_id 過濾），或在 planning 認定此為後續版本，並將 Acceptance Criteria 2 的「儀表板顯示提醒」與「歷史檢視」分離，避免 Acceptance Criteria 無法驗證。
- 阻塞項：否

---

## 供使用者決定——範圍外建議（最多一行）

建議將「登入來源歷史檢視」與「重複記錄刪除」列為下一期版本，本期僅保留儀表板提醒與列表檢視，避免範圍蔓延。

（續於 Part 4：跨文件矛盾、總結與行動清單）
