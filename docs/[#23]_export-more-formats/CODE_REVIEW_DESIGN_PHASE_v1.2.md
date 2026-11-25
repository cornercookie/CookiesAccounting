# Code 審查報告（設計階段重審）— [#23] 匯出更多格式

**審查對象**：`docs/[#23]_export-more-formats/Design.md` v1.2  
**審查日期**：2026-11-25  
**審查員**：獨立第三方審查員  
**審查依據**：Planning.md v1.0、專案硬性約束（AGENTS.md）、既有程式碼（`export_csv.php`、`export_pdf.php`、`functions.php`、`ledger.php`）  
**上一輪審查關鍵發現**：C-01、C-02、C-03、H-02、H-05

---

## 一、上一輪 Critical/High 項目修正驗證

| 編號 | 原始問題 | Design v1.2 修正內容 | 驗證結果 | 備註 |
|------|----------|---------------------|----------|------|
| **C-01** | `export_csv.php` 缺少 `ym` 參數解析與查詢條件 | §程式修改路徑·export_csv.php 項目 2：新增 `ym` GET 參數，使用 `parseYm()` 解析為起迄日期，格式錯誤回退本月並 HTTP 400 提示。Mermaid 流程圖含「解析 ym 參數 parseYm」節點。 | ✅ **已修正** | 設計完整涵蓋參數接收、驗證、回退、查詢整合。 |
| **C-02** | 錯誤引用 `fetchEntriesWithStats()`，未提供串流查詢函數 | §程式修改路徑·export_csv.php 項目 6：改用新增共用函數 `fetchEntriesForExport()`，**回傳 PDOStatement 供串流 fetch**。§functions.php 新增 5 函數第 3 項定義簽名與 SQL。 | ✅ **已修正** | 回傳 `PDOStatement` 而非陣列，支援 CSV/JSON 串流，避免 `fetchAll()` 記憶體壓力。 |
| **C-03** | `export_pdf.php` 雙模式分支優先順序不明確 | §程式修改路徑·export_pdf.php 項目 2：**分支優先順序明確**——優先檢查 `entry` 參數 → 單筆傳票模式；無 `entry` → 月結單模式。Mermaid 流程圖第一決策點即為「檢查 entry 參數是否存在」。 | ✅ **已修正** | 分支邏輯清晰，避免參數衝突。 |
| **H-02** | CSRF 防護策略未決議（Planning 要求加、專案約束為 POST） | §身分驗證與權限模型：**GET 匯出端點豁免 CSRF Token 驗證**，列出 4 項理由（唯讀、ledger_access 主防線、OWASP 原則、分享連結需求）。§差異與待確認表記錄與 Planning 差異。 | ✅ **已修正** | 決議文件化、理由充分、實作細節明確（不驗證、不附加 token）。 |
| **H-05** | 缺少 `parseYm()` 共用函數 | §functions.php 新增 5 函數第 1 項：`parseYm(string $ymRaw): array` 回傳 `[$startDate, $endDate, $error]`。§年月解析邏輯提供完整實作碼（含時區、月首/末日、年份範圍檢查）。 | ✅ **已修正** | 介面與既有 `parseDateRange()` 風格一致，可複用。 |

**結論**：上一輪所有 Critical/High 項目在設計層面均已完整修正。

---

## 二、新增設計項目檢查

### 1. 串流輸出策略可行性

| 格式 | 設計方案 | 可行性評估 | 風險與建議 |
|------|----------|------------|------------|
| **CSV** | `fopen('php://output')` → 寫入 BOM → `fputcsv($output, $header)` → `while ($row=$stmt->fetch()) { fputcsv(...); }` | ✅ **可行** | 成熟模式，記憶體恆定。注意：`fputcsv` 預設用雙引號逸出，含雙引號欄位會正確處理。 |
| **JSON** | 手動串流：`echo '['; $first=true; while($row=$stmt->fetch()){ if(!$first) echo ','; echo json_encode($row, JSON_UNESCAPED_UNICODE\|JSON_UNESCAPED_SLASHES); $first=false; } echo ']';` | ⚠️ **有條件可行** | **關鍵風險**：若中途 `json_encode` 失敗或資料異常，HTTP 標頭與 `[` 已送出，無法改回 HTTP 500。建議：<br>1. 在迴圈前預先驗證資料結構（如金額轉換）<br>2. 或接受「部分輸出後失敗」只能中斷連線，無法送錯誤頁<br>3. 文件化此限制於設計備註 |

### 2. 共用函數設計合理性

| 函數 | 簽名 | 設計評估 | 備註 |
|------|------|----------|------|
| `parseYm` | `parseYm(string $ymRaw): array` → `[$start, $end, $error]` | ✅ 合理 | 與 `parseDateRange` 回傳慣例一致；含時區、月界、年份範圍檢查。 |
| `sanitizeFilename` | `sanitizeFilename(string $name): string` | ✅ 合理 | Unicode 字母數字 + `_-`，移除控制字元，限長 100。建議順序：先移除控制字元再做 Unicode 過濾。 |
| `fetchEntriesForExport` | `fetchEntriesForExport(PDO, int, int, string, string): PDOStatement` | ✅ 合理 | **關鍵設計**：回傳已 `execute()` 的 `PDOStatement`，呼叫端負責 `fetch()` 迴圈。需文件化「呼叫端不得在迴圈中關閉 statement」。 |
| `getEntryForVoucher` | `getEntryForVoucher(PDO, int, int, int): array\|false` | ✅ 合理 | 不含 `archived` 條件，與既有查詢一致；回傳關聯陣列含 `ledger_name` 供檔名用。 |
| `buildContentDisposition` | `buildContentDisposition(string $filename): string` | ✅ 合理 | 雙重標頭：`filename` (ASCII 安全化) + `filename*` (RFC 5987 `rawurlencode`)。防標頭注入（移除 `\r\n` 隱含於 regex）。 |

### 3. 錯誤處理完整性

| 層面 | 設計規範 | 評估 |
|------|----------|------|
| **統一 try/catch** | 兩支端點皆以 `try/catch (Throwable $e) { logError($e); http_response_code(500); include './error.php'; exit; }` 包裹核心邏輯 | ✅ 完整 |
| **查詢失敗** | `logError` → HTTP 500 友善錯誤頁 | ✅ 完整 |
| **輸出失敗** | CSV/JSON 寫入失敗、PDF 生成異常 → `logError` → HTTP 500 | ⚠️ 見串流風險備註 |
| **TCPDF 缺失** | 降級提供列印優化 HTML（HTTP 200） | ✅ 完整 |
| **參數錯誤** | `ym`/`format`/`entry` 格式錯 → HTTP 400 提示（含回退行為） | ✅ 完整 |
| **空資料** | CSV：BOM+標題列；JSON：`[]`；PDF：404/友善頁；皆 HTTP 200/404 非 500 | ✅ 完整 |
| **審計日誌時機** | `register_shutdown_function()` 檢查 HTTP 200 才寫入，參數 JSON 結構化 | ⚠️ **需細化**：`http_response_code()` 在 shutdown 時若標頭已送出仍可讀取，但若腳本 `exit()` 早結束，shutdown 仍會跑。建議改用旗標變數（如 `$exportSuccess = true`）於正常結束前設定，shutdown 只檢查旗標。 |

### 4. 空資料處理分支

- CSV：僅輸出 BOM + 中文標題列 → HTTP 200
- JSON：輸出 `[]` → HTTP 200
- PDF 傳票：查無記錄 → HTTP 404 友善錯誤頁
- PDF 月結單：既有邏輯導向空白頁

✅ **分支明確，符合 Planning 驗收條件**。

### 5. 無資料庫變更確認

- §資料庫變更明確聲明：「無。功能僅讀取既有表，不新增表，無 migration」。
- 所有查詢針對既有 `entries`、`users`、`ledgers`、`ledger_access`。
- ✅ **確認無資料庫變更**。

---

## 三、設計階段 Code 審查發現的新議題

### 設計層級議題（Design-Level Findings）

| 編號 | 類別 | 說明 | 嚴重度 | 建議修正 |
|------|------|------|--------|----------|
| **D-01** | 串流輸出錯誤處理 | JSON/CSV 串流過程中若發生錯誤（如 `json_encode` 失敗、磁碟滿、連線中斷），HTTP 標頭與部分本文已送出，無法改送 HTTP 500 錯誤頁。設計文件未處理此情境。 | **High** | 在設計備註中明確：<br>1. CSV：`fputcsv` 失敗極罕見，可接受中斷連線。<br>2. JSON：建議在 `fetch` 迴圈前對每筆資料做 `json_encode` 預檢（或用 `json_last_error`），失敗則中止並記錄錯誤（雖無法送 500 頁）。<br>3. 文件化「串流輸出一旦開始，錯誤只能中斷連線，無法顯示友善錯誤頁」。 |
| **D-02** | `register_shutdown_function` 審計日誌可靠性 | 設計依賴 shutdown 檢查 `http_response_code()` 判斷是否 200。但若腳本中途 `exit()`（如參數錯誤 redirect）、或 `ob_start()` 緩衝區未清理，狀態碼可能不準。 | **Medium** | 改用明確旗標：<br>`$auditParams = [...]`;`$auditAction = 'csv_export'`;`register_shutdown_function(fn() => $GLOBALS['export_ok'] && logAudit($auditAction, json_encode($auditParams)));`<br>正常流程結束前設 `$GLOBALS['export_ok'] = true;`。 |
| **D-03** | `format` 參數錯誤時的 HTTP 400 與「回退 CSV」衝突 | 設計說「其他值 HTTP 400 回退 CSV」。HTTP 400 代表錯誤回應（通常輸出 HTML 錯誤頁），但「回退 CSV」意指仍輸出 CSV 檔案（HTTP 200）。兩者互斥。 | **Medium** | 澄清策略：<br>**方案 A**（建議）：無效 `format` → 顯示錯誤頁 HTTP 400，**不**輸出檔案。<br>**方案 B**：無效 `format` → 記錄警告、改用預設 CSV、HTTP 200 輸出檔案（但違反「參數錯誤回應 400」契約）。<br>請在設計文件二擇一並修正 Mermaid 流程圖。 |
| **D-04** | `ym` 參數錯誤時的 HTTP 400 與下載端點體驗 | 同 D-03，若 `ym` 格式錯誤回 HTTP 400 錯誤頁，使用者點擊下載連結會看到 HTML 錯誤頁而非檔案。下載端點常見做法：redirect 回列表頁帶錯誤訊息，或輸出本月資料並在檔名/標頭附帶警告。 | **Medium** | 建議：`ym` 錯誤 → 記錄警告、改用本月、HTTP 200 正常輸出檔案；前端可在檔名含 `_invalid_ym` 或透過 `Warning` 標頭提示。若堅持 400，需改為 redirect 非直接下載。 |
| **D-05** | `fetchEntriesForExport` 回傳 `PDOStatement` 的生命週期管理 | 設計回傳已 `execute()` 的 statement，呼叫端 `while($row=$stmt->fetch())`。若呼叫端提前 `break` 或拋出例外，statement 未關閉（PHP 會在請求結束自動清理，但連線池環境下較佳顯式關閉）。 | **Low** | 在函數註解說明：「回傳的 PDOStatement 由呼叫端負責迭代完畢；若提前中止，建議 `$stmt->closeCursor()`」。 |
| **D-06** | `sanitizeFilename` 正則順序 | 設計碼先 `preg_replace('/[^\p{L}\p{N} _-]/u', '', $name)` 再移除控制字元。控制字元（如 `\x00`）在 Unicode 類別外，第一步就會被移除，順序無害但建議先移除控制字元語意較清晰。 | **Low** | 調整順序：先 `preg_replace('/[\x00-\x1F\x7F]/', '', $name)` 再 Unicode 過濾。 |
| **D-07** | `parseYm` 年份範圍 2000–2100 硬編碼 | 設計碼寫死 `$y < 2000 || $y > 2100`。若系統長期運行超出範圍會誤判。 | **Low** | 改用相對範圍：`$y < (int)date('Y') - 50 || $y > (int)date('Y') + 50`，或定義常數 `YM_MIN_YEAR`/`YM_MAX_YEAR`。 |
| **D-08** | `ledger.php` 既有 `ym` 參數與新增匯出年月選擇器的關係 | 現有 `ledger.php` 第 260-265 行已解析 `ym` 參數供列表篩選。設計文件 §前端變更說明提到「新增年月選擇器供 CSV/JSON 匯出指定月份」，未明確是否複用既有 `ym` 或另增參數。 | **Low** | 明確：匯出連結使用既有 `ym` 參數值（`$_GET['ym']`），前端選擇器同步更新該參數，避免雙重參數。 |
| **D-09** | PDF 傳票模式 TCPDF 缺失時的降級輸出 | 設計說「TCPDF 不存在 → 列印優化 HTML（HTTP 200）」，但現有 `pdf_export_html_fallback.php` 可能僅支援月結單模式。需確認或新增傳票模式的 HTML 降級樣板。 | **Medium** | 設計文件補充：單筆傳票模式同樣需 HTML 降級輸出，包含所有欄位、列印樣式、`h()` 轉義。 |
| **D-10** | 審計日誌 `count` 欄位在串流模式下的取得 | 設計參數含 `'count'=>$rowCount`，但串流輸出時直到迴圈結束才知總筆數。若在迴圈中累計，shutdown 時需取得最終值。 | **Medium** | 設計補充：`$rowCount = 0;` 迴圈中遞增，迴圈結束後 `$auditParams['count'] = $rowCount;` 再註冊 shutdown（或 shutdown 內讀取全域變數）。 |

---

## 四、與 Planning.md 驗收條件對照

| Planning 驗收條件 | Design v1.2 覆蓋情況 | 缺口 |
|-------------------|---------------------|------|
| `export_csv.php` 支援 `?format=json` 回傳 `application/json` | ✅ 完整設計（參數、標頭、串流、金額格式） | — |
| CSV 匯出預設加入 UTF-8 BOM | ✅ 設計明確 | — |
| CSV 欄位名稱中文、順序正確 | ✅ 設計表格列出 | — |
| `export_pdf.php` 支援 `?entry=X` 單筆傳票 PDF | ✅ 完整設計（分支、查詢、內容、檔名） | — |
| JSON `Content-Type: application/json; charset=utf-8` | ✅ 設計明確 | — |
| 金額以 `formatCents()` 轉換為元 | ✅ 三格式皆指定 | — |
| 文字輸出經 `h()` 轉義（PDF 文字層同） | ✅ 設計明確 | — |
| 權限不足 → 403/redirect | ✅ 設計流程含權限節點 | — |
| 資料庫失敗 → 錯誤頁 + error.log | ✅ try/catch + logError | — |
| 空資料 → 空陣列/僅標題列，非錯誤 | ✅ 設計分支明確 | — |
| `ym` 格式錯 → 回退本月 + HTTP 400 提示 | ⚠️ 見 D-04 | 需澄清 HTTP 400 與下載端點體驗 |
| `format` 非支援值 → 回退 CSV + HTTP 400 提示 | ⚠️ 見 D-03 | 需澄清策略衝突 |
| 匯出失敗 → HTTP 500 友善錯誤頁 | ✅ 設計完整 | 串流輸出例外見 D-01 |

---

## 五、最終判定

### 審查結果：**需修正後重審**

**理由**：設計文件 v1.2 已完整修正上一輪所有 Critical/High 項目，架構清晰、函數設計合理、流程圖完整。但存在 **3 項 Medium+ 級設計缺口**（D-01 串流錯誤處理、D-03/D-04 參數錯誤 HTTP 碼與下載端點體驗衝突、D-10 審計日誌 count 取得時機），以及 **1 項 Medium 級降級輸出缺口**（D-09 PDF 傳票模式 HTML 降級），需在設計文件中明確決策並更新後，方可進入實作階段。

### 必須修正項目（阻擋實作）

1. **D-01**：補充串流輸出錯誤處理策略文件化（接受中斷連線或預檢方案）。
2. **D-03**：決策 `format` 參數錯誤時的具體行為（HTTP 400 錯誤頁 vs. 靜默回退 CSV + 200），更新流程圖與契約表。
3. **D-04**：決策 `ym` 參數錯誤時的具體行為（同 D-03），更新流程圖。
4. **D-09**：補充 PDF 單筆傳票模式 TCPDF 缺失時的 HTML 降級輸出規格。
5. **D-10**：明確 `rowCount` 在串流模式下的累計與審計日誌寫入時機。

### 建議改進項目（不阻擋實作，建議併同修正）

- D-02：改用旗標變數替代 `http_response_code()` 檢查。
- D-05：函數註解補充 statement 生命週期說明。
- D-06：調整 `sanitizeFilename` 正則順序。
- D-07：`parseYm` 年份範圍改用相對或常數。
- D-08：明確前端年月選擇器複用既有 `ym` 參數。

---

## 六、後續流程

1. 設計者依本報告修正 Design.md → v1.3
2. 重新提交 **設計階段三階段審查**（Spec / Code / Security）
3. 三階段審查全部通過後，方可進入實作階段

---

**審查員簽署**：獨立第三方審查員  
**日期**：2026-11-25