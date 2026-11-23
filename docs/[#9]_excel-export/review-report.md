# 實作階段三階段審查報告：[#9]_excel-export (一鍵匯出 Excel/CSV)

**審查日期**：2026-11-23  
**審查對象**：`export_csv.php` 實作  
**基準文件**：
- Planning: `docs/[#9]_excel-export/planning.md`
- Design: `docs/[#9]_excel-export/Design.md`
- Agents 相關文件（service-architecture.md, user-permissions.md, database-schema.md）

---

## 總結結論

| 審查階段 | 結果 | 說明 |
|----------|------|------|
| **Spec 審查** | ⚠️ **需修正** | 發現 2 項偏離規格、1 項缺漏 |
| **Code 審查** | ⚠️ **需修正** | 發現 1 項錯誤處理缺失、1 項金額轉換邏輯可優化 |
| **Security 審查** | ✅ **通過** | 無阻塞性安全風險，所有防護機制已正確實作 |

**整體判定**：**需修正** — 請先修正 Spec 與 Code 審查發現的問題，再進行複查。

---

## 1. Spec 審查（實作階段）

### 1.1 逐項比對 Planning、Design 與實際行為

| # | 規格來源 | 規格內容 | 實際行為 | 判定 | 備註 |
|---|----------|----------|----------|------|------|
| S-1 | Planning: Expected behavior | **IDOR 權限檢查**：匯出前必須檢查使用者對該帳本是否擁有讀取權限 | ✅ 已實作：`INNER JOIN ledger_access` 驗證 | 通過 | 符合 user-permissions.md 權限矩陣（owner/member/viewer 皆可匯出） |
| S-2 | Planning: Expected behavior | **CSV 公式注入防護**：對所有輸出的文字欄位，若開頭為 `=`, `+`, `-`, `@` 等危險字元，強制在其前方加上單引號 `'` | ⚠️ **部分偏離** | 需修正 | 實作僅防護 `category`、`note`、`username` 三欄；`type`（收入/支出/轉帳）與 `date` 未防護。雖然這兩欄系統固定值風險極低，但規格要求「所有輸出的文字欄位」 |
| S-3 | Planning: Expected behavior | **UTF-8 with BOM 編碼** | ✅ 已實作：`fwrite($output, "\xEF\xBB\xBF")` | 通過 | |
| S-4 | Planning: Expected behavior | **包含欄位**：日期、類型、金額（元）、分類、記錄者、備註 | ✅ 已實作 | 通過 | 欄位順序與規格一致 |
| S-5 | Planning: Acceptance Criteria #1 | 帳本頁面提供「匯出 CSV」操作按鈕 | ❌ **未驗證** | 缺漏 | 此屬前端整合（`ledger.php`），非 `export_csv.php` 本體職責，但規格列為驗收條件。需確認前端已整合 |
| S-6 | Planning: Acceptance Criteria #2 | 匯出動作具備嚴格的帳本權限驗證 | ✅ 已實作 | 通過 | |
| S-7 | Planning: Acceptance Criteria #3 | 輸出欄位經過防公式注入處理，且金額正確轉換為元 | ⚠️ **部分偏離** | 需修正 | 金額轉換邏輯存在精度風險（見 Code 審查 C-2） |
| S-8 | Planning: Acceptance Criteria #4 | 中文編碼在 Excel 中正常顯示（UTF-8 BOM） | ✅ 已實作 | 通過 | |
| S-9 | Design: 2. 處理流程 | 查詢該帳本所有非作廢 (`is_deleted = 0`) 的交易記錄 | ❌ **偏離** | 需修正 | 實作使用 `e.deleted = 0`，但資料庫結構定義欄位為 `deleted`（非 `is_deleted`）。Design 文件術語不一致，實作以實際資料庫欄位為準，**規格文件需修正** |
| S-10 | Design: 2. 處理流程 | 公式注入防護：若備註或分類欄位內容開頭為 `=`, `+`, `-`, `@`，強制在前方加上單引號 | ⚠️ **部分偏離** | 需修正 | 實作額外檢查 `\t`, `\r` 且包含 `username`，範圍較寬（較嚴格），可接受；但 Design 僅提及「備註或分類」 |
| S-11 | Design: 2. 處理流程 | 寫入審計日誌 (`csv_export`)，記錄 `ledger_id` 與筆數 | ✅ 已實作 | 通過 | `logAudit('csv_export', 'ledger_id=' . $ledgerId . ' count=' . count($entries))` |
| S-12 | Design: 3. 驗證與測試計畫 | 使用者權限驗證：非授權帳本成員訪問匯出 API 應被拒絕 (403) | ⚠️ **實作導向非 403** | 建議 | 實作使用 `redirect('./ledgers.php?error=1')` 導向帳本列表，非 HTTP 403。Design 要求 403，建議統一為 403 或說明設計考量 |

### 1.2 Spec 審查發現清單

| 編號 | 嚴重度 | 類別 | 位置 | 違反依據 | 修正建議 |
|------|--------|------|------|----------|----------|
| **SPEC-01** | **高** | 規格偏離 | `export_csv.php` 第 80-86 行 | Planning 要求「所有輸出的文字欄位」防公式注入；實作僅防護 category、note、username | 將防護範圍擴展至所有字串欄位（含 type、date），或在規格中明確列出豁免欄位 |
| **SPEC-02** | **中** | 規格術語不一致 | Design.md 第 14 行 | Design 稱 `is_deleted = 0`，實際資料庫欄位為 `deleted` | 修正 Design.md 術語為 `deleted = 0`，與 database-schema.md 一致 |
| **SPEC-03** | **中** | 驗收條件缺漏 | Planning.md 第 28 行 | Acceptance Criteria #1「帳本頁面提供按鈕」屬前端整合，未在本審查範圍驗證 | 於 Planning 或 Design 註明：前端整合由 `ledger.php` 負責，本功能僅提供 API 端點 |
| **SPEC-04** | **低** | 設計不一致 | Design.md 第 40 行 | Design 要求無權限時回傳 403；實作導向 `ledgers.php?error=1` | 統一為 HTTP 403 回應，或於 Design 記錄「採導向模式以一致專案現有錯誤處理風格」 |

---

## 2. Code 審查（實作階段）

### 2.1 程式碼品質與正確性檢查

| # | 檢查項目 | 結果 | 詳細說明 |
|---|----------|------|----------|
| C-1 | **PDO 預備陳述式** | ✅ 通過 | 所有查詢使用 `prepare()` + `execute([':param' => $val])`，具名佔位符 |
| C-2 | **整數分金額轉換** | ⚠️ **需修正** | 第 72-74 行使用 `intdiv` + `%` 手動組合字串，而專案已有 `formatCents()` 函數（functions.php 第 150-158 行）可處理負數、千分位、零補齊。手動實作：<br>① 重複造輪子<br>② 未處理負數情況（轉帳可能為負？）<br>③ 未加千分位（長輩辨識需求） |
| C-3 | **h() 輸出轉義** | ✅ 通過 | CSV 輸出使用 `fputcsv()` 自動處理雙引號逸出，不需 `h()`；HTTP Header 中檔名已 `rawurlencode()` |
| C-4 | **CSRF 防護** | ✅ 通過（不適用） | 此為 GET 下載端點，非狀態變更操作，無需 CSRF Token |
| C-5 | **logAudit 審計日誌** | ✅ 通過 | 第 93 行正確記錄 `csv_export` action |
| C-6 | **ledger_access 權限檢查** | ✅ 通過 | 第 22-34 行標準 `INNER JOIN` 模式，符合 user-permissions.md IDOR 防護機制 |
| C-7 | **相對路徑** | ✅ 通過 | `redirect('./ledgers.php...')` 使用 `./` 開頭 |
| C-8 | **Session 檢查** | ✅ 通過 | 第 8 行 `requireLogin()`，含 `requireSessionFresh()` 密碼變更失效檢查 |
| C-9 | **錯誤處理** | ⚠️ **缺失** | 第 59 行 `fopen('php://output', 'w')` 未檢查回傳值；第 62 行 `fwrite`、第 65/89 行 `fputcsv`、第 95 行 `fclose` 皆未嘗試捕獲錯誤。建議以 `try-catch` 包裹或檢查回傳值，失敗時記錄錯誤並回傳 500 |
| C-10 | **大量資料記憶體** | ✅ 通過 | 使用 `fputcsv` 串流輸出，不將全部資料載入記憶體，適合大量明細 |
| C-11 | **檔名安全** | ✅ 通過 | 第 49 行 `preg_replace` 過濾檔名危險字元；第 54-55 行 RFC 5987 編碼防 Header Injection |
| C-12 | **日期排序** | ✅ 通過 | 第 43 行 `ORDER BY e.entry_date DESC, e.id DESC` 符合 Design |
| C-13 | **刪除標記查詢** | ✅ 通過 | 第 42 行 `e.deleted = 0` 正確對應資料庫結構 |

### 2.2 Code 審查發現清單

| 編號 | 嚴重度 | 類別 | 位置 | 違反依據 | 修正建議 |
|------|--------|------|------|----------|----------|
| **CODE-01** | **中** | 程式碼重複/不一致 | `export_csv.php` 第 72-74 行 | 專案已有 `formatCents()` 統一處理金額格式化（含負數、千分位、零補齊），手動實作違反 DRY 原則 | 替換為 `$amount = formatCents((int) $row['amount_cents'])`；若不需千分位，可在 `formatCents` 加參數或另行處理 |
| **CODE-02** | **中** | 錯誤處理缺失 | `export_csv.php` 第 59-95 行 | 輸出串流操作（fopen/fwrite/fputcsv/fclose）未檢查回傳值、未捕獲例外，失敗時將產生不完整 CSV 或靜默失敗 | 用 `try { ... } catch (Throwable $e) { logError(...); http_response_code(500); exit; }` 包裹主體邏輯，或至少檢查關鍵函數回傳值 |

---

## 3. Security 審查（實作階段）

### 3.1 威脅模型核對

| 威脅類別 | 檢查項目 | 結果 | 說明 |
|----------|----------|------|------|
| **SQL Injection** | 所有查詢使用預備陳述式 | ✅ 通過 | 第 22-30、37-46 行均使用 `prepare` + 具名參數 |
| **XSS** | CSV 內容注入 HTML | ✅ 通過（不適用） | `Content-Type: text/csv` 非 HTML，瀏覽器不會執行腳本；`fputcsv` 自動雙引號逸出 |
| **CSV Formula Injection** | `=`, `+`, `-`, `@` 開頭字元防護 | ✅ 通過 | 第 80-86 行實作單引號前綴防護，涵蓋 `\t`, `\r` 額外風險向量 |
| **CSRF** | 狀態變更操作需 Token | ✅ 通過（不適用） | GET 下載非狀態變更 |
| **IDOR** | 帳本存取權限驗證 | ✅ 通過 | 第 22-34 行標準 `INNER JOIN ledger_access`，符合 user-permissions.md 核心原則 |
| **權限繞過** | viewer 角色可否匯出 | ✅ 通過 | 權限矩陣允許 viewer 匯出；查詢未限制 role，僅檢查存在性 |
| **資料外洩** | 導出他人帳本資料 | ✅ 通過 | 權限查詢含 `la.user_id = :user_id`，僅回傳授權帳本 |
| **Header Injection** | `Content-Disposition` 檔名注入 | ✅ 通過 | 第 54-55 行使用 RFC 5987 `filename*=UTF-8''` 格式，`rawurlencode` 編碼 |
| **路徑遍歷** | 檔案讀寫路徑 | ✅ 通過（不適用） | 直接輸出至 `php://output`，無檔案系統操作 |
| **Session 劫持** | Session 固定/竊取防護 | ✅ 通過 | `requireLogin()` 內含 `requireSessionFresh()` 密碼變更失效檢查 |
| **審計日誌完整性** | 關鍵操作記錄 | ✅ 通過 | 第 93 行記錄 `csv_export` action、ledger_id、筆數 |

### 3.2 Security 審查發現清單

| 編號 | 嚴重度 | 類別 | 位置 | 風險說明 | 現況 |
|------|--------|------|------|----------|------|
| **SEC-01** | **低** | 資訊洩露 | 第 33 行 | 錯誤訊息「您無權存取此帳本」透露帳本存在性（用戶枚舉風險） | 接受風險：專案既有模式（ledger.php 等）一致，且需用戶知帳本 ID 才能嘗試，實際風險極低 |
| **SEC-02** | **低** | 例外處理 | 第 93 行 | `logAudit` 失敗時僅記錄 error.log 不中斷主流程（符合設計），但 CSV 匯出本身若失敗無審計記錄 | 設計決策：審計失敗不阻塞主流程，符合 functions.php 第 286-290 行註解 |

---

## 4. 綜合修正建議優先序

| 優先序 | 編號 | 類別 | 修正內容 | 預估工時 |
|--------|------|------|----------|----------|
| **P0 (阻塞)** | SPEC-01 | Spec | 擴展公式注入防護至所有字串欄位，或修正規格文件明確列出豁免欄位 | 0.5h |
| **P0 (阻塞)** | SPEC-02 | Spec | 修正 Design.md `is_deleted` → `deleted` 術語一致性 | 0.1h |
| **P0 (阻塞)** | CODE-01 | Code | 替換手動金額格式化為 `formatCents()` | 0.2h |
| **P0 (阻塞)** | CODE-02 | Code | 輸出串流加入 try-catch 錯誤處理 | 0.3h |
| **P1 (建議)** | SPEC-03 | Spec | Planning/Design 註明前端按鈕整合職責歸屬 | 0.1h |
| **P1 (建議)** | SPEC-04 | Spec | 統一無權限回應為 HTTP 403，或記錄設計考量 | 0.2h |

---

## 5. 複查重點

複查時請重點驗證：

1. **SPEC-01/CODE-01**：所有 CSV 欄位（含 type、date）是否經公式注入防護，或規格文件已明確豁免
2. **SPEC-02**：Design.md 術語已修正為 `deleted = 0`
3. **CODE-01**：金額欄位使用 `formatCents()`，輸出格式正確（含負數、千分位、兩位小數）
4. **CODE-02**：主體邏輯包裹於 `try-catch`，失敗時回傳 HTTP 500 並記錄 error.log
5. **SPEC-04**：無權限回應行為與 Design 一致（403 或導向並記錄理由）

---

## 6. 簽署

| 角色 | 審查人 | 日期 | 結論 |
|------|--------|------|------|
| Spec 審查 | AI Agent | 2026-11-23 | 需修正 |
| Code 審查 | AI Agent | 2026-11-23 | 需修正 |
| Security 審查 | AI Agent | 2026-11-23 | 通過 |

> **備註**：本報告為實作階段審查。設計階段審查（Spec/Code/Security）已於先前通過。請開發人員依優先序修正上述問題，修正完成後請通知再行複查。