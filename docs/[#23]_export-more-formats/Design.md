# Design: [#23] 匯出 CSV／JSON 與單筆傳票列印

> **範圍調整**：CSV／JSON 下載不變；單筆傳票僅回傳可列印的 HTML，使用原有 `export_pdf.php?ledger_id=…&entry=…`。先驗證登入、存取權、帳本未封存及傳票未作廢且屬於該帳本；缺少傳票的請求屬於月結模式，傳票編號無效回 404。傳票保留原幣別、原幣金額、匯率及台幣金額。「預覽時間」與實際「列印」分開；稽核只記 `voucher_print_view`。前端列印透過同源腳本，不修改 CSP。月結詳見 [#8 設計](../%5B%238%5D_pdf-export/Design.md)。

驗證：隔離 HTTP 實測傳票 HTML、原幣資訊、找不到傳票時 HTTP 404、封存拒絕及預覽稽核；Chrome 驗證按鈕呼叫列印 API、列印樣式與 CSV／JSON 月份切換。獨立審查結論及可重跑測試見 [#8 設計](../%5B%238%5D_pdf-export/Design.md)。

下方為歷史設計：其中單筆 PDF 下載部分已由上方規格取代。

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Design: 匯出更多格式（JSON、優化 CSV、單筆傳票 PDF） |
| 版本 | v1.3 |
| 建立日期 | 2026-11-25 |
| 更新日期 | 2026-11-25 |
| 變更摘要 | v1.3 修正設計階段 Code 重審發現問題：串流輸出錯誤處理策略文件化、format/ym 參數錯誤 HTTP 碼決策（HTTP 400 不輸出檔案）、PDF 傳票模式 HTML 降級輸出規格、審計日誌 rowCount 串流累計機制、register_shutdown_function 改用旗標變數、sanitizeFilename 正則順序調整、parseYm 年份範圍改用相對常數、前端年月選擇器複用既有 ym 參數 |

## 來源 Planning 與需求摘要

- Planning 檔案：`docs/[#23]_export-more-formats/planning.md`
- 對應 Feature：匯出更多格式（任務編號 [#23]）
- 需求摘要：在既有 CSV（`export_csv.php`）與 PDF 月結單（`export_pdf.php`）基礎上，新增：
  1. **JSON 匯出** - `export_csv.php` 支援 `?format=json` 參數，回傳 `application/json`，資料為該帳本所有未刪除記錄的陣列
  2. **優化 CSV 匯出** - 確認 CSV 匯出預設加入 UTF-8 BOM（`\xEF\xBB\xBF`），欄位名稱使用中文，欄位順序：日期、類型、金額 (元)、分類、記錄者、備註
  3. **單筆傳票 PDF 列印** - `export_pdf.php` 支援 `?entry=X` 參數，輸出單頁 PDF，包含傳票編號、日期、類型、金額 (元)、分類、記錄者、備註欄位，所有文字經 `h()` 轉義
  4. **日期篩選** - 支援 `ym` 參數（YYYY-MM 格式，預設本月），匯出指定年月資料；格式錯誤回退本月並 HTTP 400 提示（不輸出檔案）

## 設計概述

本設計依循專案既有設計系統與硬性約束（純 PHP + PDO 預備陳述式、整數分金額、CSRF、h() XSS 轉義、ledger_access 權限模型、相對路徑、無框架、無 Composer、無 Node.js）。主要變更如下：

1. **修改 `export_csv.php`**：
   - 新增 `format` 參數支援（`csv`、`json`，預設 `csv`）— **僅接受 `csv|json`，`pdf` 為獨立端點，其他值 HTTP 400 錯誤頁（不輸出檔案）**（與 Planning 第 87 行差異見「差異與待確認」）
   - 新增 `ym` 參數支援（YYYY-MM 格式，預設本月），使用 `parseYm()` 解析為起迄日期，格式錯誤回退本月並 HTTP 400 錯誤頁（不輸出檔案）
   - **CSRF 決議**：GET 匯出端點**豁免 CSRF Token 驗證**——理由：唯讀操作、有 `ledger_access` IDOR 防護、符合 OWASP「GET 不應改變狀態」原則；直接連結、書籤、分享均可正常使用。前端連結不附加 csrf_token。
   - `format=json` 時：設定 `Content-Type: application/json; charset=utf-8`，設定 `Content-Disposition: buildContentDisposition('ledger_{$ledgerId}_{$safeName}_{$dt}.json')`，**手動 JSON 串流輸出**（避免大量資料記憶體爆炸），金額經 `formatCents()`，**不使用 `h()`**，JSON 金額格式無千分位（如 `"1200.00"`）
   - `format=csv` 時：設定 `Content-Type: text/csv; charset=utf-8`，設定 `Content-Disposition: buildContentDisposition(...)`，**開啟 `php://output` 串流輸出**，寫入 UTF-8 BOM → 中文標題列 → `while ($row = $stmt->fetch()) { fputcsv($output, ...); }` 逐行寫入，CSV 公式注入防護（開頭 `=`, `+`, `-`, `@`, `\t`, `\r` 加單引號），所有文字經 `h()`
   - **串流輸出錯誤處理策略**：
     - CSV：若寫入過程中發生錯誤（磁碟滿、連線中斷），**無法改送 HTTP 500**（標頭與部分本文已送出），僅能 `logError` 記錄並中斷連線（`exit`），前端將收到不完整檔案
     - JSON：同理，手動串流過程中發生錯誤僅能中斷連線
     - **預防措施**：迴圈前先驗證金額轉換、字串編碼等非資料庫操作；資料庫查詢錯誤在 `fetch()` 前已被 `try/catch` 捕獲
     - **文件化限制**：串流一旦開始，錯誤只能中斷連線，無法顯示友善錯誤頁
   - 查詢使用新增共用函數 `fetchEntriesForExport($pdo, $userId, $ledgerId, $startDate, $endDate)`，**回傳 PDOStatement 供串流**
   - 空資料處理：CSV 僅輸出 BOM + 標題列；JSON 輸出 `[]`；皆 HTTP 200
   - **檔名安全**：使用 `sanitizeFilename()` 處理帳本名稱，**先移除控制字元再 Unicode 過濾**，限制長度 100 字；`Content-Disposition` 同時輸出 `filename` (ASCII 安全化) 與 `filename*` (RFC 5987)，防標頭注入（移除 `\r\n`)
   - **審計日誌 rowCount 累計**：串流迴圈中 `$rowCount++`，迴圈正常結束後更新 `$auditParams['count'] = $rowCount` 再註冊 `register_shutdown_function()` 檢查旗標 `$exportSuccess` 為 true 才寫入，參數格式標準化 JSON：`json_encode(['ledger_id'=>$ledgerId, 'format'=>'csv|json', 'ym'=>$ym, 'count'=>$rowCount])`
   - 匯出成功後採用**旗標變數**機制：`$exportSuccess = false` → 正常流程結束前 `$exportSuccess = true` → `register_shutdown_function()` 檢查 `$exportSuccess` 且 HTTP 200 才 `logAudit()`：`csv_export` 或 `json_export`

2. **修改 `export_pdf.php`**：
   - **分支優先順序**：優先檢查 `entry` 參數 → 單筆傳票模式；無 `entry` 時 → 月結單模式（`start_date`、`end_date`）
   - **CSRF 決議**：同 `export_csv.php`，GET 端點豁免 CSRF，不驗證 csrf_token
   - 新增 `entry` 參數處理（單筆傳票編號），驗證為整數
   - 有 `entry` 時進入單筆傳票模式：
     - 使用共用函數 `getEntryForVoucher($pdo, $userId, $ledgerId, $entryId)` 查詢單筆記錄（**不加 `l.archived=0` 條件**，與既有月結單/列表查詢一致）
     - 若無記錄：HTTP 404 友善錯誤頁
     - PDF 內容：傳票編號、日期、類型、金額 (元)、分類、記錄者、備註，所有經 `h()` 轉義
     - **TCPDF 缺失降級輸出**：若 `./lib/tcpdf/tcpdf.php` 不存在，**提供單筆傳票專用列印優化 HTML 頁面**（HTTP 200），內容含所有欄位、列印樣式（`@media print`）、所有文字經 `h()` 轉義，不產生空白 PDF
     - 檔名處理：使用 `sanitizeFilename()`，**`Content-Disposition` 同時輸出 `filename` 與 `filename*`**
     - 成功後採用旗標變數機制檢查 HTTP 200 才 `logAudit('pdf_voucher_export', json_encode(['ledger_id'=>$ledgerId, 'entry_id'=>$entryId]))`
   - 無 `entry` 時維持既有月結單模式（`start_date`、`end_date`），複用既有 `parseDateRange()`、`fetchEntriesWithStats()`、`renderPdfExportPage()`，**TCPDF 缺失時使用既有 `pdf_export_html_fallback.php`**
   - **錯誤處理完整化**：
     - 查詢失敗：`logError` → HTTP 500 友善錯誤頁
     - TCPDF 載入失敗：降級提供列印優化 HTML 頁面（HTTP 200）
     - PDF 生成失敗（`TCPDF` 拋出異常）：`logError` → HTTP 500 友善錯誤頁
   - 統一 `try/catch (Throwable $e) { logError($e); http_response_code(500); include './error.php'; exit; }` 包裹核心邏輯

3. **`functions.php` 新增 5 個共用函數**：
   - `parseYm(string $ymRaw): array` — 解析 `YYYY-MM`，回傳 `[$startDate, $endDate, $error]`（起始日 = 月首日 00:00:00，結束日 = 月末日 23:59:59，Asia/Taipei 時區），格式錯誤回傳空字串與錯誤訊息。**年份範圍使用相對常數**：`YM_MIN_YEAR = (int)date('Y') - 50`、`YM_MAX_YEAR = (int)date('Y') + 10`
   - `sanitizeFilename(string $name): string` — 檔名安全化，**先移除控制字元 `preg_replace('/[\x00-\x1F\x7F]/', '', $name)` 再 Unicode 過濾 `preg_replace('/[^\p{L}\p{N} _-]/u', '', $name)`**，限制長度 100 字
   - `fetchEntriesForExport(PDO $pdo, int $userId, int $ledgerId, string $startDate, string $endDate): PDOStatement` — 匯出專用查詢，**回傳已 execute() 的 PDOStatement 供串流 fetch**，不含彙總，供 CSV/JSON 共用。**呼叫端負責迭代完畢，提前中止需 `$stmt->closeCursor()`**
   - `getEntryForVoucher(PDO $pdo, int $userId, int $ledgerId, int $entryId): array|false` — 單筆傳票查詢，回傳記錄陣列或 false
   - `buildContentDisposition(string $filename): string` — 組裝雙重 Content-Disposition 標頭值：`attachment; filename="..."; filename*=UTF-8''...`（`filename` 經 ASCII 安全化，`filename*` 經 `rawurlencode`）

4. **無資料庫變更**：不新增資料庫表，無 migration 需要

## 設計圖表（Mermaid）

### CSV/JSON 匯出流程（含 ym 參數、CSRF 決議、串流輸出、檔名安全、審計日誌時機、空資料、錯誤分支、參數錯誤 HTTP 400 不輸出檔案）

```mermaid
flowchart TD
    A[使用者點擊匯出按鈕] --> B[export_csv.php 接收 ledger_id、format、ym]
    B --> C[驗證登入 requireLogin]
    C --> D[驗證 ledger_id 為整數]
    D --> E[檢查 ledger_access 權限]
    E --> F{權限足夠？}
    F -->|否| G[HTTP 403 / redirect ledgers.php]
    F -->|是| H[解析 ym 參數 parseYm]
    H --> I{ym 格式有效？}
    I -->|否| J[回退本月 顯示提示 HTTP 400 錯誤頁<br/>不輸出檔案]
    I -->|是| K[查詢 entries fetchEntriesForExport<br/>deleted=0 且 entry_date BETWEEN startDate AND endDate<br/>回傳 PDOStatement]
    K --> L{format 參數？}
    L -->|json| M[設定 Content-Type: application/json; charset=utf-8<br/>設定 Content-Disposition buildContentDisposition<br/>$exportSuccess=false; echo '[';<br/>while fetch() { json_encode... $rowCount++; }<br/>echo ']'; $exportSuccess=true;]
    L -->|csv| N[設定 Content-Type: text/csv; charset=utf-8<br/>設定 Content-Disposition buildContentDisposition<br/>$exportSuccess=false; 開啟 php://output<br/>寫入 BOM → 標題列<br/>while fetch() { fputcsv... $rowCount++; }<br/>$exportSuccess=true;]
    L -->|其他| O[HTTP 400 錯誤頁提示 不輸出檔案]
    M --> P{輸出成功？}
    N --> P
    O --> P
    P -->|否| Q[logError HTTP 500 友善錯誤頁]
    P -->|是| R[register_shutdown_function<br/>檢查 $exportSuccess && HTTP 200 才 logAudit<br/>json_encode 參數含 count=$rowCount]
    K --> S{查無資料？}
    S -->|是| T[CSV: 僅 BOM+標題列<br/>JSON: 輸出 []<br/>HTTP 200 記錄 audit_log]
```

### 單筆傳票 PDF 列印流程（明確分支優先順序、CSRF 決議、檔名安全、審計日誌時機、錯誤分支、TCPDF 缺失降級 HTML）

```mermaid
flowchart TD
    A[使用者點擊單筆傳票列印] --> B[export_pdf.php 接收 ledger_id、entry]
    B --> C[驗證登入 requireLogin]
    C --> D[檢查 entry 參數是否存在]
    D -->|是| E[單筆傳票模式]
    E --> F[驗證 ledger_id、entry 為整數]
    F --> G[檢查 ledger_access 權限]
    G --> H{權限足夠？}
    H -->|否| I[HTTP 403 / redirect ledgers.php]
    H -->|是| J[查詢單筆 getEntryForVoucher<br/>id=entry AND ledger_id=ledger_id AND deleted=0]
    J --> K{查詢成功且有記錄？}
    K -->|否| L[HTTP 404 / 友善錯誤頁]
    K -->|是| M[檢查 TCPDF 是否存在]
    M -->|否| N[單筆傳票專用 HTML 降級頁面<br/>所有欄位 h() 轉義 列印樣式<br/>HTTP 200]
    M -->|是| O[try { $exportSuccess=false;<br/>產生單頁 PDF（TCPDF）<br/>所有文字 h() 轉義<br/>設定 Content-Disposition buildContentDisposition<br/>輸出 PDF<br/>$exportSuccess=true; } catch (Throwable $e) { logError; HTTP 500 友善錯誤頁 }]
    O --> P{PDF 輸出成功？}
    P -->|是| Q[register_shutdown_function<br/>檢查 $exportSuccess && HTTP 200 才 logAudit<br/>pdf_voucher_export json_encode 參數標準化]
    D -->|否| R[月結單模式]
    R --> S[既有邏輯 parseDateRange fetchEntriesWithStats renderPdfExportPage<br/>CSRF 豁免 同上<br/>TCPDF 缺失用 pdf_export_html_fallback.php<br/>錯誤分支完整：查詢失敗/PDF 失敗 → HTTP 500]
```

## 程式修改路徑

| 檔案 | 修改內容 |
| --- | --- |
| `export_csv.php`（修改） | 1. 新增 `format` GET 參數處理（支援 `csv`、`json`，預設 `csv`）— **僅接受 `csv|json`，其他值 HTTP 400 錯誤頁（不輸出檔案）**<br>2. 新增 `ym` GET 參數處理（YYYY-MM，預設本月），使用 `parseYm()` 解析為起迄日期，格式錯誤回退本月並 HTTP 400 錯誤頁（不輸出檔案）<br>3. **CSRF 決議**：GET 匯出端點豁免 CSRF Token 驗證（理由見「身分驗證與權限模型」），前端連結不附加 csrf_token<br>4. `format=json` 分支：設定 `Content-Type: application/json; charset=utf-8`，設定 `Content-Disposition: buildContentDisposition('ledger_{$ledgerId}_{$safeName}_{$dt}.json')`，**手動 JSON 串流輸出**（避免大量資料記憶體爆炸），金額經 `formatCents()`，**不使用 `h()`**，JSON 金額格式無千分位（如 `"1200.00"`）<br>5. `format=csv` 分支：設定 `Content-Type: text/csv; charset=utf-8`，設定 `Content-Disposition: buildContentDisposition(...)`，**開啟 `php://output` 串流輸出**，寫入 UTF-8 BOM → 中文標題列 → `while ($row = $stmt->fetch()) { fputcsv($output, ...); }` 逐行寫入，CSV 公式注入防護（開頭 `=`, `+`, `-`, `@`, `\t`, `\r` 加單引號），所有文字經 `h()`<br>6. **串流錯誤處理策略**：迴圈前預檢金額/編碼；迴圈中錯誤僅能 `logError` 中斷連線（無法送 HTTP 500），文件化此限制<br>7. 查詢使用新增共用函數 `fetchEntriesForExport($pdo, $userId, $ledgerId, $startDate, $endDate)`，**回傳 PDOStatement 供串流**<br>8. 空資料處理：CSV 僅輸出 BOM + 標題列；JSON 輸出 `[]`；皆 HTTP 200<br>9. 檔名處理：`$safeName = sanitizeFilename($ledger['name'])`，使用 `buildContentDisposition()` 組裝雙重標頭（`filename` ASCII + `filename*` RFC 5987）<br>10. **審計日誌 rowCount 累計**：`$rowCount = 0` 迴圈中遞增，迴圈正常結束後 `$auditParams = ['ledger_id'=>$ledgerId, 'format'=>$format, 'ym'=>$ym, 'count'=>$rowCount]`，`$exportSuccess = false` → 正常結束前 `$exportSuccess = true` → `register_shutdown_function()` 檢查 `$exportSuccess` 才 `logAudit('csv_export|json_export', json_encode($auditParams))`<br>11. 統一 `try/catch (Throwable $e) { logError($e); http_response_code(500); include './error.php'; exit; }` 包裹核心邏輯（查詢階段） |
| `export_pdf.php`（修改） | 1. **CSRF 決議**：同 `export_csv.php`，GET 端點豁免 CSRF Token 驗證<br>2. **分支優先順序**：優先檢查 `entry` 參數是否存在 → 單筆傳票模式；無 `entry` 時 → 月結單模式<br>3. 單筆傳票模式（有 `entry` 時）：<br>&nbsp;&nbsp;- 驗證 `entry` 為整數<br>&nbsp;&nbsp;- 使用共用函數 `getEntryForVoucher($pdo, $userId, $ledgerId, $entryId)` 查詢單筆記錄（**不加 `l.archived=0` 條件**，與既有查詢一致）<br>&nbsp;&nbsp;- 若無記錄：HTTP 404 友善錯誤頁<br>&nbsp;&nbsp;- **TCPDF 存在**：`try { $exportSuccess=false; 產生 PDF... $exportSuccess=true; } catch (Throwable $e) { logError; HTTP 500 }`<br>&nbsp;&nbsp;- **TCPDF 不存在**：單筆傳票專用 HTML 降級頁面（所有欄位、`h()`、列印樣式）、HTTP 200<br>&nbsp;&nbsp;- PDF 內容：傳票編號、日期、類型、金額 (元)、分類、記錄者、備註，所有經 `h()`<br>&nbsp;&nbsp;- 檔名處理：`$safeName = sanitizeFilename($ledger['name'])`，使用 `buildContentDisposition('voucher_{$entryId}_{$dt}.pdf')` 組裝雙重標頭<br>&nbsp;&nbsp;- 成功後採用旗標變數機制檢查 HTTP 200 才 `logAudit('pdf_voucher_export', json_encode(['ledger_id'=>$ledgerId, 'entry_id'=>$entryId]))`<br>&nbsp;&nbsp;- 統一 `try/catch (Throwable $e) { logError($e); http_response_code(500); include './error.php'; exit; }`<br>4. 月結單模式（無 `entry` 時）：維持既有邏輯，複用 `parseDateRange()`、`fetchEntriesWithStats()`、`renderPdfExportPage()`<br>5. 統一錯誤處理：查詢失敗/PDF 生成失敗 → `logError` → HTTP 500 友善錯誤頁；TCPDF 不存在 → 列印優化 HTML（HTTP 200） |
| `functions.php`（新增 5 個共用函數） | 1. `parseYm(string $ymRaw): array` — 解析 `YYYY-MM`，回傳 `[$startDate, $endDate, $error]`（起始日 = 月首日 00:00:00，結束日 = 月末日 23:59:59，Asia/Taipei 時區），格式錯誤回傳空字串與錯誤訊息。**年份範圍常數**：`YM_MIN_YEAR = (int)date('Y') - 50`、`YM_MAX_YEAR = (int)date('Y') + 10`<br>2. `sanitizeFilename(string $name): string` — 檔名安全化，**先移除控制字元 `preg_replace('/[\x00-\x1F\x7F]/', '', $name)` 再 Unicode 過濾 `preg_replace('/[^\p{L}\p{N} _-]/u', '', $name)`**，限制長度 100 字<br>3. `fetchEntriesForExport(PDO $pdo, int $userId, int $ledgerId, string $startDate, string $endDate): PDOStatement` — 匯出專用查詢，**回傳已 execute() 的 PDOStatement 供串流 fetch**，不含彙總，供 CSV/JSON 共用。**呼叫端負責迭代完畢，提前中止需 `$stmt->closeCursor()`**<br>4. `getEntryForVoucher(PDO $pdo, int $userId, int $ledgerId, int $entryId): array|false` — 單筆傳票查詢，回傳記錄陣列或 false<br>5. `buildContentDisposition(string $filename): string` — 組裝雙重 Content-Disposition 標頭值：`attachment; filename="..."; filename*=UTF-8''...`（`filename` 經 ASCII 安全化，`filename*` 經 `rawurlencode`） |
| `ledger.php`（修改） | 1. **移除匯出連結的 csrf_token 參數**（依 CSRF 決議）<br>2. 「匯出明細 CSV」連結：`export_csv.php?ledger_id={id}&format=csv&ym={既有 $_GET['ym'] 或本月}`<br>3. 新增「匯出 JSON」連結/按鈕：`export_csv.php?ledger_id={id}&format=json&ym={既有 $_GET['ym'] 或本月}`<br>4. 年月選擇器（`<select name="ym">`）**複用既有 `$_GET['ym']` 參數**，選擇器同步更新該參數，供 CSV/JSON 匯出指定月份<br>5. 記錄列表每筆資料旁新增「列印傳票」連結/按鈕：`export_pdf.php?ledger_id={id}&entry={entry_id}`<br>6. 樣式與既有按鈕一致（`.btn` 或 `.btn-sm`），`viewer` 角色也可見 |
| `assets/style.css`（必要時） | 新增單筆傳票 PDF/列印頁面專用樣式（如 `.voucher-print`、`.voucher-field` 等），沿用既有設計系統變數。PDF 降級 HTML 頁面亦套用相同樣式。 |

## 資料庫變更

無。功能僅讀取既有 `entries`、`users`、`ledgers`、`ledger_access` 表，不新增資料庫表，無 migration 需要。

## 前端變更說明

### 匯出 CSV/JSON 頁面（export_csv.php）

- **頁面標題**：「匯出明細」— 此為直接下載端點，無獨立頁面 UI
- **篩選參數**：`ledger_id`（必填）、`format`（選填，`csv`/`json`，預設 `csv`）、`ym`（選填，`YYYY-MM`，預設本月）
- **結果**：直接觸發瀏覽器下載對應格式檔案
- **錯誤狀態**：權限不足 HTTP 403/redirect、參數錯誤 HTTP 400 錯誤頁（不輸出檔案）、查詢/輸出失敗 HTTP 500 友善錯誤頁

### 匯出 PDF 頁面（export_pdf.php）

- **月結單模式**（既有）：`start_date`、`end_date` 參數，PDF 直接下載
- **單筆傳票模式**（新增）：`entry` 參數，單頁 PDF 直接下載
- **TCPDF 缺失降級**：月結單模式用既有 `pdf_export_html_fallback.php`；單筆傳票模式提供專用 HTML 降級頁面
- **錯誤狀態**：權限不足 HTTP 403、記錄不存在 HTTP 404、查詢/PDF 生成失敗 HTTP 500 友善錯誤頁、TCPDF 不存在時列印優化 HTML（HTTP 200）

### 帳本詳情頁（ledger.php）

- 既有：「匯出明細 CSV」按鈕（`export_csv.php?ledger_id={id}`）、匯出 PDF 月結單按鈕（`export_pdf.php?ledger_id={id}`）
- **新增**：
  - 「匯出 JSON」連結/按鈕（`export_csv.php?ledger_id={id}&format=json&ym={既有 ym}`）
  - 年月選擇器（`<select name="ym">`）**複用既有 `$_GET['ym']` 參數**，供 CSV/JSON 匯出指定月份
  - 記錄列表每筆資料旁「列印傳票」連結/按鈕（`export_pdf.php?ledger_id={id}&entry={entry_id}`），樣式與既有按鈕一致（`.btn` 或 `.btn-sm`），`viewer` 角色也可見
  - **所有連結不附加 csrf_token**（依 CSRF 決議）

### JSON 輸出格式範例

```json
[
  {
    "日期": "2026-11-20",
    "類型": "支出",
    "金額 (元)": "1200.00",
    "分類": "飲食",
    "記錄者": "mom",
    "備註": "全家便當"
  },
  {
    "日期": "2026-11-19",
    "類型": "收入",
    "金額 (元)": "30000.00",
    "分類": "薪資",
    "記錄者": "dad",
    "備註": "11 月薪資"
  }
]
```

- 編碼：UTF-8，`json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`
- **不經 `h()` 轉義**（由 `json_encode` 處理特殊字元編碼），避免雙重編碼導致輸出 `"` 等實體字元
- 金額格式：無千分位逗號（純數值字串），保留兩位小數

### CSV 輸出格式（既有，確認規格）

| 日期 | 類型 | 金額 (元) | 分類 | 記錄者 | 備註 |
| --- | --- | --- | --- | --- | --- |
| 2026-11-20 | 支出 | 1,200.00 | 飲食 | mom | 全家便當 |
| 2026-11-19 | 收入 | 30,000.00 | 薪資 | dad | 11 月薪資 |

- 編碼：UTF-8 with BOM（`\xEF\xBB\xBF`）
- 公式注入防護：欄位開頭為 `=`, `+`, `-`, `@`, `\t`, `\r` 時前加單引號 `'`
- 所有文字經 `h()` 轉義
- 金額格式：含千分位逗號（`formatCents()` 既有行為）

### 單筆傳票 PDF 內容規格

| 欄位 | 說明 |
| --- | --- |
| 傳票編號 | 記錄 `id` |
| 日期 | `entry_date` (Y-m-d) |
| 類型 | 收入/支出/轉帳 |
| 金額 (元) | `formatCents(amount_cents)` |
| 分類 | `category` |
| 記錄者 | `users.username` |
| 備註 | `note`（可為空） |

- 頁面：A4 直向、邊距 15mm
- 字型：沿用既有 TCPDF 字體設定（DejaVu Sans / CID 中文字體）
- 所有文字經 `h()` 轉義

## SQL 查詢範例

### CSV/JSON 匯出查詢（新增 `fetchEntriesForExport()` 共用函數，回傳 PDOStatement）

```sql
SELECT
    e.entry_date,
    e.type,
    e.amount_cents,
    e.category,
    e.note,
    u.username
FROM entries e
INNER JOIN users u ON u.id = e.user_id
INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
WHERE e.ledger_id = :ledger_id
  AND e.deleted = 0
  AND e.entry_date BETWEEN :startDate AND :endDate
ORDER BY e.entry_date DESC, e.id DESC
```

### 單筆傳票查詢（新增 `getEntryForVoucher()` 共用函數，**不含 archived 條件**）

```sql
SELECT
    e.id,
    e.entry_date,
    e.type,
    e.amount_cents,
    e.category,
    e.note,
    u.username,
    l.name AS ledger_name
FROM entries e
INNER JOIN users u ON u.id = e.user_id
INNER JOIN ledgers l ON l.id = e.ledger_id
INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
WHERE e.id = :entry_id
  AND e.ledger_id = :ledger_id
  AND e.deleted = 0
LIMIT 1
```

### 年月解析邏輯（`parseYm()` 共用函數）

```php
// 常數定義（檔案頂部或 functions.php 開頭）
define('YM_MIN_YEAR', (int)date('Y') - 50);
define('YM_MAX_YEAR', (int)date('Y') + 10);

function parseYm(string $ymRaw): array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ymRaw, $m)) {
        return ['', '', '年月格式錯誤，應為 YYYY-MM'];
    }
    $y = (int)$m[1];
    $mo = (int)$m[2];
    if ($mo < 1 || $mo > 12 || $y < YM_MIN_YEAR || $y > YM_MAX_YEAR) {
        return ['', '', '年月數值超出合理範圍'];
    }
    $tz = new DateTimeZone('Asia/Taipei');
    $start = new DateTimeImmutable("{$y}-{$mo}-01", $tz);
    $end = $start->modify('last day of this month')->setTime(23, 59, 59);
    return [$start->format('Y-m-d'), $end->format('Y-m-d'), ''];
}
```

### 檔名安全化與 Content-Disposition 組裝（`sanitizeFilename()`、`buildContentDisposition()` 共用函數）

```php
function sanitizeFilename(string $name): string {
    // 先移除控制字元（含 \r\n 防標頭注入）
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    // 再 Unicode 過濾：保留字母、數字、空格、底線、連字號
    $name = preg_replace('/[^\p{L}\p{N} _-]/u', '', $name);
    return mb_substr($name, 0, 100, 'UTF-8');
}

function buildContentDisposition(string $filename): string {
    $asciiName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    $encodedName = rawurlencode($filename);
    return 'attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . $encodedName;
}
```

## 預期行為與驗證方法

| 驗收項 | 預期行為 | 驗證方法 |
| --- | --- | --- |
| CSV 匯出正常 | `export_csv.php?ledger_id=1&format=csv&ym=2026-11` 下載 CSV，含 BOM、中文標題、公式注入防護、串流輸出 | 開啟連結，用 Excel 開啟確認中文、金額、公式防護；大量資料確認記憶體穩定 |
| JSON 匯出正常 | `export_csv.php?ledger_id=1&format=json&ym=2026-11` 下載 JSON 陣列，欄位正確、金額經 formatCents、無雙重編碼、無千分位、串流輸出 | 開啟連結，瀏覽器顯示 JSON，確認結構與內容、特殊字元正確、大量資料記憶體穩定 |
| format 參數錯誤處理 | `format=xml` 等非支援值 → HTTP 400 錯誤頁提示（不輸出檔案） | 傳入無效 format，確認行為 |
| ym 參數錯誤處理 | `ym=2026-13` 等非法值回退本月 → HTTP 400 錯誤頁提示（不輸出檔案） | 傳入無效 ym，確認行為 |
| CSRF 豁免確認 | 直接連結、書籤、分享連結均可正常下載 | 複製連結在新瀏覽器/無痕模式開啟，確認可下載 |
| 權限驗證 | 無權限使用者存取 CSV/JSON/PDF 受阻 | 以 viewer 或無權限使用者存取，確認 HTTP 403/redirect |
| 單筆傳票 PDF 正常 | `export_pdf.php?ledger_id=1&entry=5` 下載單頁 PDF，內容完整 | 開啟連結，確認 PDF 內容含傳票編號、日期、類型、金額、分類、記錄者、備註 |
| 單筆傳票 TCPDF 缺失降級 | TCPDF 不存在時，`export_pdf.php?ledger_id=1&entry=5` 顯示 HTML 降級頁面，含所有欄位、列印樣式 | 重命名 TCPDF 目錄測試，確認 HTML 降級輸出 |
| 單筆傳票不存在 | entry 不存在或無權限時 HTTP 404/友善錯誤 | 傳入無效 entry，確認錯誤處理 |
| 月結單模式不受影響 | 無 entry 參數時仍為月結單模式 | 確認既有 `export_pdf.php?ledger_id=1&start_date=...` 正常 |
| 金額格式正確 | CSV 含千分位、JSON 無千分位、PDF 含千分位 | 檢查三種格式中的金額顯示 |
| CSV/PDF 輸出經 h() 轉義 | 無 XSS 風險 | 檢查特殊字元（`<`, `>`, `&`, `"` 等）在輸出中正確轉義 |
| JSON 不經 h()、由 json_encode 編碼 | 特殊字元正確輸出（無 `"` 等實體） | 檢查 JSON 中含 `"`、`\`、`<` 等字元的備註欄位 |
| 檔名格式正確 | CSV/JSON：`ledger_{ledger_id}_{安全化名稱}_{日期時間}.csv/.json`<br/>PDF 月結單：既有格式<br/>PDF 傳票：`voucher_{entry_id}_{日期時間}.pdf` | 檢查下載檔名 |
| 中文檔名相容 | Content-Disposition 同時提供 `filename` (ASCII) 與 `filename*` (RFC 5987) | 檢查中文檔名在不同瀏覽器顯示 |
| 檔名安全化 | 含 `../`、`\`、`:`、`\r\n` 等危險字元的帳本名稱被移除/轉換 | 以含危險字元帳本名稱測試，確認檔名安全、無標頭注入 |
| 空資料處理 | CSV 僅 BOM+標題列、JSON 輸出 `[]`、HTTP 200、記錄 audit_log | 查詢無記錄期間，確認行為 |
| 匯出成功記錄 audit_log | `csv_export`、`json_export`、`pdf_voucher_export` 僅在 HTTP 200 成功下載後記錄，參數 JSON 結構化含 count | 查詢 `audit_log` 表確認；中斷下載時不記錄 |
| 查詢/輸出/PDF 生成失敗 | HTTP 500 友善錯誤頁、logError 記錄 | 模擬資料庫錯誤、TCPDF 異常，確認行為 |
| 串流輸出中途錯誤 | 僅能中斷連線、logError 記錄、前端收到不完整檔案（文件化限制） | 模擬磁碟滿/連線中斷，確認行為與日誌 |
| 大資料量串流輸出 | CSV/JSON 匯出 10 萬筆記錄時記憶體穩定（< 50MB） | 模擬大量資料匯出，確認記憶體穩定 |

## 非本期範圍（Out of Scope）

- Excel (.xlsx) 格式：本期僅支援 CSV 與 JSON
- 自動電子郵件匯出：本期不支援將匯出檔案自動發送電子郵件
- 定時匯出：本期不支援排程定時匯出
- 多帳本合併匯出：本期僅支援單一帳本
- 圖表匯出：本期不支援圖形化報表匯出

## 身分驗證與權限模型

不變更既有登入與帳本權限模型。繼續使用 session 登入與 `ledger_access` 權限檢查（owner/member/viewer 均可匯出，因為僅為唯讀查詢）。

- **CSRF 防護決議**：**GET 匯出端點豁免 CSRF Token 驗證**。
  - 理由：
    1. 匯出為唯讀操作，不改變伺服器狀態
    2. 已有 `ledger_access` IDOR 防護作為主防線
    3. 符合 OWASP「GET 不應改變狀態」原則與 CSRF 保護重點在狀態變更請求
    4. 直接連結、書籤、分享連結需正常運作（家庭成員分享報表常見場景）
  - 實作：`export_csv.php`、`export_pdf.php` **不驗證** `csrf_token`；`ledger.php` 產生連結時**不附加** `csrf_token` 參數
  - 註：專案硬性約束「所有狀態變更 POST 請求皆需 CSRF Token」不適用於此唯讀 GET 端點

## 資料傳輸加密標準

無新增外部 API 呼叫，全部為伺服端 SQL 與 session 驗證，資料僅儲存在站台自身 SQLite 資料庫。匯出檔案直接輸出至瀏覽器下載，不儲存在伺服器。

## 非功能性需求

- **效能**：匯出頁面載入時間不超過 5 秒（大資料量時串流輸出避免記憶體過載：CSV 使用 `fputcsv($output, ...)` 逐行寫入 `php://output`，JSON 手動串流輸出）
- **併發處理量**：匯出為唯讀查詢，SQLite WAL 模式支援多併發讀取
- **資料保留策略**：匯出不產生額外資料，不涉及資料保留
- **系統可用性**：匯出失敗時顯示通用錯誤頁，不影響其他功能

## 差異與待確認

| 項目 | 說明 |
| --- | --- |
| `format` 參數值差異 | Planning 第 87 行列出 `format` 合法值含 `pdf`，但 API 契約 `export_csv.php` 僅支援 `csv|json`，`pdf` 為獨立端點 `export_pdf.php`。本設計依 API 契約實作，`format` 僅接受 `csv|json`，其他值 HTTP 400 錯誤頁（不輸出檔案）。 |
| CSRF 豁免決議 | Planning 第 103 行要求「仍需加入 CSRF Token 防護」，經三階段審查決議：GET 唯讀匯出端點豁免 CSRF，以 `ledger_access` 為主防線。若未來專案政策變更，需同步修正。 |
| `archived` 條件 | 單筆傳票查詢**不加** `l.archived=0`，與既有月結單/列表查詢保持一致。若既有查詢有此條件，需同步補充；目前既有程式碼無此條件。 |
| 串流輸出錯誤限制 | CSV/JSON 串流過程中發生錯誤時，HTTP 標頭與部分本文已送出，**無法改送 HTTP 500 錯誤頁**，僅能中斷連線。此為串流輸出固有限制，已文件化於設計概述與流程圖。 |
| 參數錯誤 HTTP 400 不輸出檔案 | `format`/`ym` 參數錯誤時回 HTTP 400 錯誤頁（不輸出檔案），而非靜默回退 CSV/本月輸出檔案。此為為避免下載端點回傳 HTML 錯誤頁導致前端困惑。 |

## 設計審查記錄（設計階段）

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 通過 | 2026-11-25 | 所有規格問題已修正 |
| Code（程式）審查 | 通過 | 2026-11-25 | v1.3 修正 D-01~D-10，全部通過 |
| Security（資安）審查 | 通過 | 2026-11-25 | 所有資安問題已修正 |

## 實作記錄

| 項目 | 內容 |
| --- | --- |
| 實作完成日期 | 2026-11-25 |
| 實作版本 | v1.3 設計版本對應 |

### 新增檔案
1. `voucher_print_fallback.php` - 單筆傳票 TCPDF 缺失時的 HTML 降級頁面
2. `error.php` - 通用錯誤頁面（HTTP 400/403/404/500）

### 修改檔案
1. `export_csv.php` - 重構支援 `format=csv|json`、`ym` 參數、串流輸出、檔名安全化、審計日誌旗標機制
2. `export_pdf.php` - 新增單筆傳票模式（`entry` 參數）、分支優先順序、TCPDF 缺失降級、審計日誌旗標機制
3. `functions.php` - 新增 5 個共用函數：`parseYm()`、`sanitizeFilename()`、`fetchEntriesForExport()`、`getEntryForVoucher()`、`buildContentDisposition()`
4. `ledger.php` - 新增「匯出 JSON」按鈕、年月選擇器（複用 `ym` 參數）、每筆記錄「列印傳票」連結
5. `assets/style.css` - 新增 `.voucher-print`、`.voucher-table`、`.voucher-label`、`.voucher-value`、`.voucher-actions` 等列印專用樣式

### 實際變更記錄

#### export_csv.php
- 新增 `format` 參數驗證（僅接受 `csv|json`，其他 HTTP 400）
- 新增 `ym` 參數驗證（使用 `parseYm()`，格式錯誤 HTTP 400 不輸出檔案）
- 移除 CSRF Token 驗證（GET 唯讀端點豁免，以 `ledger_access` 為主防線）
- JSON 模式：手動串流輸出、無千分位、不經 `h()`、`json_encode(JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`
- CSV 模式：`php://output` 串流、UTF-8 BOM、公式注入防護、經 `h()`
- 空資料：CSV 僅 BOM+標題列、JSON 輸出 `[]`、HTTP 200
- 檔名：`sanitizeFilename()` + `buildContentDisposition()` 雙重標頭
- 審計日誌：`$exportSuccess` 旗標、`register_shutdown_function()` 檢查 HTTP 200 才寫入、參數 JSON 結構化
- 錯誤處理：`try/catch (Throwable)`、查詢/輸出失敗 → `logError` + HTTP 500

#### export_pdf.php
- 分支優先順序：優先檢查 `entry` 參數 → 單筆傳票模式；無 `entry` → 月結單模式
- 移除 CSRF Token 驗證（同 `export_csv.php`）
- 單筆傳票模式：`getEntryForVoucher()` 查詢、無 `archived` 條件、HTTP 404 不存在
- TCPDF 存在：產生 PDF、所有文字 `h()`、雙重 Content-Disposition
- TCPDF 不存在：`voucher_print_fallback.php` 專用 HTML 降級頁面（HTTP 200）
- 月結單模式：複用既有 `parseDateRange()`、`fetchEntriesWithStats()`、`renderPdfExportPage()`、`pdf_export_html_fallback.php`
- 審計日誌：旗標機制、voucher 模式記錄 `entry_id`、月結單模式記錄 `start_date`/`end_date`
- 錯誤處理：查詢失敗/PDF 生成失敗 → HTTP 500、TCPDF 缺失 → 降級 HTML

#### functions.php 新增函數
- `parseYm()`：YYYY-MM 解析、相對年份常數、Asia/Taipei 時區、月首/末日
- `sanitizeFilename()`：先移除控制字元再 Unicode 過濾、限長 100
- `fetchEntriesForExport()`：回傳已 `execute()` 的 `PDOStatement` 供串流
- `getEntryForVoucher()`：單筆傳票查詢、無 `archived` 條件
- `buildContentDisposition()`：`filename` (ASCII) + `filename*` (RFC 5987) 雙重標頭

#### ledger.php
- 匯出按鈕區塊：新增「匯出 JSON」連結、年月選擇器（複用 `$_GET['ym']`）
- 記錄列表操作欄：新增「列印傳票」連結 `export_pdf.php?ledger_id={id}&entry={entry_id}`
- 前端 JS 同步更新 CSV/JSON 匯出連結的 `ym` 參數

#### assets/style.css
- `.voucher-print` 容器、`.voucher-header`、`.voucher-table`、`.voucher-label`、`.voucher-value`、`.voucher-actions`
- `@media print` 隱藏操作按鈕、適配列印樣式
- 響應式調整

## 實作審查記錄（實作階段）

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 通過 | 2026-11-25 | 所有規格問題已修正（JSON 金額無千分位、CSV 全欄位 h()） |
| Code（程式）審查 | 通過 | 2026-11-25 | 修正 C-08：register_shutdown_function 閉包改用參考捕獲（&$exportSuccess 等），確保 audit_log 正確寫入；parseYm() 常數定義改用 defined() 守衛 |
| Security（資安）審查 | 通過 | 2026-11-25 | 所有資安問題已修正，符合專案硬性約束與安全基線 |

---

> 本設計文件完成後，將進入 **設計階段三階段審查**（Spec/Code/Security），審查通過後方可進入實作階段。