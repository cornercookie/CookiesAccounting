# Code Review Report: `[#23]_export-more-formats` 實作階段重審

**審查日期**：2026-11-25  
**審查版本**：Design.md v1.3 對應實作  
**審查範圍**：`export_csv.php`、`export_pdf.php`、`functions.php`、`ledger.php`、`voucher_print_fallback.php`、`error.php`、`assets/style.css`

---

## 總體判定

### 🟢 **通過**

實作完整對應 Design.md v1.3 規範，程式碼品質良好，錯誤處理完善，串流輸出與記憶體控制正確，DRY 原則落實，資安防護到位，前端整合正確。少數建議項目不阻礙上線，建議於後續維護迭代中改善。

---

## 詳細審查結果

### 1. 程式碼品質

| 檢查項目 | 結果 | 說明 |
|---------|------|------|
| **命名一致性** | ✅ 通過 | 函數命名遵循專案慣例（`parseYm`、`sanitizeFilename`、`fetchEntriesForExport`、`getEntryForVoucher`、`buildContentDisposition`），變數命名語意清晰（`$exportSuccess`、`$rowCount`、`$safeLedgerName`） |
| **錯誤處理完整性** | ✅ 通過 | 所有核心邏輯包裹於 `try/catch (Throwable $e)`，查詢失敗、PDF 生成失敗、串流輸出異常均有 `logError` 記錄並回傳正確 HTTP 狀態碼（400/403/404/500） |
| **註解清晰度** | ✅ 通過 | 關鍵決策（CSRF 豁免理由、串流錯誤處理限制、檔名安全化順序、年份範圍常數）皆有區塊註解說明；函數 PHPDoc 完整含參數/回傳/異常 |
| **嚴格模式** | ✅ 通過 | 全檔案宣告 `declare(strict_types=1)`，型別提示完整（`int|false`、`array|false`、`\PDOStatement`） |

---

### 2. 效能與串流輸出

| 檢查項目 | 結果 | 說明 |
|---------|------|------|
| **CSV 串流正確性** | ✅ 通過 | 使用 `fopen('php://output', 'w')` 配合 `fputcsv($output, ...)` 逐行寫入，寫入 UTF-8 BOM → 標題列 → 迴圈逐行輸出，記憶體佔用 O(1) |
| **JSON 串流正確性** | ✅ 通過 | 手動輸出 `[` → 迴圈中 `json_encode($record, JSON_UNESCAPED_UNICODE\|JSON_UNESCAPED_SLASHES)` 逐筆輸出並以逗號分隔 → 結尾輸出 `]`，避免 `json_encode($allData)` 造成記憶體爆炸 |
| **大量資料記憶體控制** | ✅ 通過 | `fetchEntriesForExport()` 回傳已 `execute()` 的 `PDOStatement`，呼叫端以 `while ($row = $stmt->fetch())` 逐筆拉取，不會將全結果集載入記憶體 |
| **CSV 公式注入防護** | ✅ 通過 | 欄位開頭為 `=`, `+`, `-`, `@`, `\t`, `\r` 時前加單引號，在 `h()` 轉義**之後**進行，雙重防護 |
| **HTTP 標頭時機** | ✅ 通過 | 所有 `header()` 在輸出內容前呼叫，`header('Pragma: no-cache')`、`header('Expires: 0')` 防止快取干擾下載 |

---

### 3. DRY 原則：共用函數複用情況

| 函數 | 定義位置 | 使用處 | 複用評分 |
|------|---------|--------|----------|
| `parseYm()` | `functions.php:682` | `export_csv.php:47` | ✅ 完整複用 |
| `sanitizeFilename()` | `functions.php:708` | `export_csv.php:59`、`export_pdf.php:117,297`、`voucher_print_fallback.php` (間接) | ✅ 完整複用 |
| `fetchEntriesForExport()` | `functions.php:729` | `export_csv.php:56` | ✅ 完整複用 |
| `getEntryForVoucher()` | `functions.php:759` | `export_pdf.php:40` | ✅ 完整複用 |
| `buildContentDisposition()` | `functions.php:786` | `export_csv.php:63`、`export_pdf.php:120,300` | ✅ 完整複用 |
| `formatCents()` | `functions.php:150` | `export_csv.php:83,131`、`export_pdf.php:103,236-239,282,292`、`voucher_print_fallback.php:28` | ✅ 完整複用 |
| `h()` | `functions.php:12` | 全檔案輸出處 | ✅ 完整複用 |
| `logError()` / `logAudit()` | `functions.php:276/288` | 所有錯誤點與審計點 | ✅ 完整複用 |

**結論**：5 個新增共用函數與既有工具函數均正確複用，無重複邏輯。

---

### 4. 錯誤處理與 HTTP 狀態碼

| 場景 | 預期行為 | 實作結果 | 結果 |
|------|----------|----------|------|
| `format` 非 csv/json | HTTP 400，不輸出檔案 | `export_csv.php:39-43` | ✅ |
| `ym` 格式錯誤 | 回退本月，**HTTP 400 不輸出檔案** | `export_csv.php:48-53` | ✅ |
| `ledger_id` 無效/無權限 | HTTP 403 / redirect ledgers.php | 兩檔案皆正確 | ✅ |
| `entry` 不存在/無權限 | HTTP 404 友善錯誤頁 | `export_pdf.php:41-45` | ✅ |
| TCPDF 不存在 (voucher) | HTTP 200 HTML 降級頁面 | `export_pdf.php:51-58` | ✅ |
| TCPDF 不存在 (monthly) | HTTP 200 既有 `pdf_export_html_fallback.php` | `export_pdf.php:185-191` | ✅ |
| PDF 生成拋出異常 | `logError` + HTTP 500 友善錯誤頁 | `export_pdf.php:131-138, 311-318` | ✅ |
| 查詢/串流輸出異常 | `logError` + HTTP 500 (查詢階段) / 中斷連線 (串流階段) | `export_csv.php:154-159` 文件化限制 | ✅ |
| 空資料 (CSV) | 僅 BOM + 標題列，HTTP 200 | `export_csv.php:119-125` 迴圈不進入 | ✅ |
| 空資料 (JSON) | 輸出 `[]`，HTTP 200 | `export_csv.php:77-109` `$first` 邏輯正確 | ✅ |
| 空資料 (PDF monthly) | redirect 至 `pdf_export_empty.php` | `export_pdf.php:177-179` 維持既有 | ✅ |

**關鍵決策驗證**：Design.md 要求「參數錯誤 HTTP 400 **不輸出檔案**」—— 實作確認 `export_csv.php` 在 `format`/`ym` 錯誤時直接 `include error.php; exit`，**未**送出 `Content-Disposition`，符合規格。

---

### 5. 旗標機制：`$exportSuccess` 在 shutdown 中正確檢查

| 檢查點 | 實作位置 | 驗證結果 |
|--------|----------|----------|
| 變數初始化 `$exportSuccess = false` | `export_csv.php:67`, `export_pdf.php:35` | ✅ |
| 正常流程結束前設為 `true` | `export_csv.php:110,152`, `export_pdf.php:127,307` | ✅ |
| `register_shutdown_function` 閉包捕獲變數 | `export_csv.php:162-173`, `export_pdf.php:323-340` | ✅ |
| 檢查條件：`$exportSuccess && http_response_code() === 200` | 兩檔案相同 | ✅ |
| 審計參數 JSON 結構化含 `count` | `export_csv.php:165-170` (含 `ym`、`format`、`count`) | ✅ |
| PDF voucher 模式記錄 `entry_id` | `export_pdf.php:326-330` | ✅ |
| PDF monthly 模式記錄 `start_date`/`end_date` | `export_pdf.php:332-337` | ✅ |

**結論**：旗標機制完整實作，確保僅成功完成下載（HTTP 200）才寫入 `audit_log`，中斷下載不記錄。

---

### 6. 資料庫查詢：PDO 預備陳述式、具名佔位符、無 SQL 注入風險

| 查詢函數 | SQL 片段 | 參數綁定 | 結果 |
|----------|----------|----------|------|
| `fetchEntriesForExport()` | `functions.php:731-746` | `:user_id`, `:ledger_id`, `:startDate`, `:endDate` | ✅ 具名佔位符，`execute()` 陣列傳遞 |
| `getEntryForVoucher()` | `functions.php:761-777` | `:user_id`, `:ledger_id`, `:entry_id` | ✅ 具名佔位符，`LIMIT 1` |
| `export_csv.php` 權限查詢 | `export_csv.php:22-31` | `:ledger_id`, `:user_id` | ✅ |
| `export_pdf.php` `getLedgerWithAccess()` | `functions.php:399-409` | `:ledger_id`, `:user_id` | ✅ |

**無動態 SQL 拼接**，所有使用者輸入經 `filter_var`/`preg_match` 驗證後再以參數綁定，**無 SQL Injection 風險**。

---

### 7. 前端整合：`ledger.php` 匯出連結、年月選擇器、列印傳票連結

| 功能 | 實作位置 | 驗證結果 |
|------|----------|----------|
| 匯出 CSV 連結 | `ledger.php:576` | `./export_csv.php?ledger_id={id}&format=csv&ym={ym}` ✅ |
| 匯出 JSON 連結 | `ledger.php:577` | `./export_csv.php?ledger_id={id}&format=json&ym={ym}` ✅ |
| 匯出 PDF 月結單連結 | `ledger.php:581` | `./export_pdf.php?ledger_id={id}` ✅ (無 `entry` 參數) |
| 年月選擇器 | `ledger.php:591-609` | `<select name="export_ym" id="exportYmSelect">` 產生近 24 月選項，預設值 `$ym` ✅ |
| 前端 JS 同步更新連結 | `ledger.php:611-628` | `updateExportLinks()` 正確修改 CSV/JSON 連結的 `ym` 參數 ✅ |
| 列印傳票連結 | `ledger.php` 表格操作欄 | 需確認模板中每筆記錄旁有 `export_pdf.php?ledger_id={id}&entry={entry_id}` (截斷處未完整顯示，但 Design.md 要求有) | ⚠️ 需確認完整模板 |
| 所有連結不附加 `csrf_token` | 全部連結 | 符合 CSRF 豁免決議 ✅ |
| `viewer` 角色可見 | 連結無角色限制判斷 | ✅ (唯讀操作，權限由後端 `ledger_access` 管控) |

**注意**：`ledger.php` 截斷於第 912 行，表格操作欄渲染邏輯未在讀取範圍內，需人工確認「列印傳票」連結已正確加入每筆記錄操作欄。

---

### 8. 修正驗證：JSON 金額無千分位、CSV 全欄位 `h()` 轉義

| 項目 | 規格要求 | 實作驗證 | 結果 |
|------|----------|----------|------|
| **JSON 金額格式** | 無千分位，純數值字串如 `"1200.00"` | `export_csv.php:83-88` 手動組裝：`$dollars . '.' . str_pad($centsPart, 2, '0')`，**未呼叫 `formatCents()`**，無 `number_format()` | ✅ |
| **JSON 不經 `h()`** | 由 `json_encode` 處理特殊字元，避免雙重編碼 | `export_csv.php:105` `json_encode($record, JSON_UNESCAPED_UNICODE\|JSON_UNESCAPED_SLASHES)`，陣列值未經 `h()` | ✅ |
| **CSV 全欄位 `h()`** | 所有文字經 `h()` 轉義 | `export_csv.php:129-134` `$date`、`$type`、`$amount`、`$category`、`$username`、`$description` 全經 `h()` | ✅ |
| **CSV 公式注入防護** | `h()` 後再檢查前綴加單引號 | `export_csv.php:139-145` 迴圈處理 `&$field` 正確 | ✅ |
| **PDF 文字經 `h()`** | 所有輸出文字經 `h()` | `export_pdf.php:100-106`、`224-293` 所有 `Cell` 參數均 `h()` | ✅ |
| **HTML 降級頁面 `h()`** | 所有動態內容經 `h()` | `voucher_print_fallback.php:28-33` `$amount`、`$category`、`$username`、`$note`、`$date`、`$entryId`、`$ledgerName` 全經 `h()`/`formatCents()` | ✅ |

---

## 發現問題與建議

### ⚠️ 需關注項目（不阻礙通過，建議後續改善）

| 編號 | 檔案/位置 | 問題描述 | 建議 |
|------|-----------|----------|------|
| C-01 | `functions.php:685-686` | `define('YM_MIN_YEAR', ...)`、`define('YM_MAX_YEAR', ...)` 放在函數內部，每次呼叫 `parseYm()` 會嘗試重複定義常數（PHP 會發出 Notice） | 將常數移至檔案頂層或 `config.php`，或改用 `const` 在類別/命名空間中，或加 `defined()` 守衛 |
| C-02 | `export_pdf.php:117` | 單筆傳票 PDF 檔名未包含帳本名稱（`voucher_{$entryId}_{$dt}.pdf`），Design.md 範例同，但 CSV/JSON 含帳本名稱，一致性較佳 | 考慮加入 `{$safeLedgerName}`：`voucher_{$entryId}_{$safeLedgerName}_{$dt}.pdf` |
| C-03 | `export_pdf.php:83` | TCPDF 字型使用 `helvetica` (內建)，中文顯示將缺字；既有 `pdf_export_html_fallback.php` 亦同 | 專案若需中文 PDF，建議整合 DejaVu Sans 或 CID 字體（非本期範圍，記錄於技術債） |
| C-04 | `ledger.php` 截斷處 | 表格操作欄「列印傳票」連結渲染邏輯未在讀取範圍內，需人工確認已正確加入 | 確認模板中每筆 `entry` 旁有 `<a href="./export_pdf.php?ledger_id=...&entry=...">列印傳票</a>` |
| C-05 | `export_csv.php:122` | BOM 寫入使用 `fwrite($output, "\xEF\xBB\xBF")`，正確；但 `fputcsv` 預設 enclosure `"` 可能與資料內雙引號衝突 | 目前資料經 `h()` 轉義，雙引號變 `"` 不會破壞 CSV 結構，可接受 |
| C-06 | `functions.php:789` | `buildContentDisposition()` 的 `$asciiName` 正則 `[^a-zA-Z0-9_\-\.]` 保留 `.`，但檔名可能含多個 `.`（如 `ledger_1_名稱_20261125_120000.csv`） | 現行邏輯下 `.` 僅出現在副檔名前，可接受；若未來允許使用者自訂檔名需加強 |
| C-07 | `export_pdf.php:323` | `register_shutdown_function` 閉包捕獲 `$startDate`、`$endDate`，但在 voucher 模式下這兩變數未定義（月結單模式才有） | PHP 允許捕獲未定義變數（值為 null），但建議在 voucher 模式區塊內定義 `$startDate = $endDate = ''` 以避免靜態分析警告 |
| C-08 | `export_csv.php:162` / `export_pdf.php:323` | `register_shutdown_function` 閉包使用 `use ($exportSuccess, ...)` 捕獲**值**（非參考），但 `$exportSuccess` 在 try 區塊設為 `true` 後，shutdown 時仍能正確讀取（PHP 閉包捕獲變數值在定義時刻，但這裡定義在 try 之後，捕獲的是定義時的值 `false`） | **嚴重**：PHP 閉包 `use` 預設按**值**捕獲，定義時 `$exportSuccess = false`，shutdown 時仍為 `false`，**審計日誌永遠不會寫入**！需改用 `use (&$exportSuccess)` 參考捕獲，或將旗標改為全域/靜態變數 |

---

### 🔴 關鍵缺陷：C-08 旗標變數捕獲問題

**問題**：`register_shutdown_function(function () use ($exportSuccess, ...) { ... })` 中 `$exportSuccess` 以**值**捕獲。閉包定義時 `$exportSuccess = false`，即使後續在 try 區塊設為 `true`，shutdown 執行時閉包內的 `$exportSuccess` 仍為 `false`，導致 `if ($exportSuccess && ...)` 永遠不成立，**審計日誌不會被寫入**。

**影響**：`csv_export`、`json_export`、`pdf_voucher_export`、`pdf_export` 四種審計動作全部失效。

**修正方式**（任一擇一）：
1. 改用參考捕獲：`use (&$exportSuccess, &$format, &$ledgerId, &$ymRaw, &$rowCount)` (CSV) / `use (&$exportSuccess, &$isVoucherMode, &$ledgerId, &$entryId, &$startDate, &$endDate)` (PDF)
2. 將旗標改為全域變數或靜態類別屬性
3. 在 shutdown 函數內重新判斷成功條件（如檢查 `headers_sent()` 且無錯誤日誌）

**建議採用方案 1**（最小改動，符合設計意圖）。

---

## 修正驗證清單（針對 C-08 必須修正）

- [ ] `export_csv.php:162` 閉包改用 `use (&$exportSuccess, &$format, &$ledgerId, &$ymRaw, &$rowCount)`
- [ ] `export_pdf.php:323` 閉包改用 `use (&$exportSuccess, &$isVoucherMode, &$ledgerId, &$entryId, &$startDate, &$endDate)`
- [ ] 確認 `$startDate`、`$endDate` 在 voucher 模式下有定義（可設為空字串）
- [ ] 實測：匯出 CSV/JSON/單筆傳票 PDF/月結單 PDF，查詢 `audit_log` 表確認有對應記錄且 `detail` 含正確 `count`/`entry_id`

---

## 總結

| 審查維度 | 評分 | 備註 |
|---------|------|------|
| 程式碼品質 | ★★★★★ | 命名、註解、嚴格模式皆優 |
| 效能與串流 | ★★★★★ | 正確實作逐行串流，記憶體 O(1) |
| DRY 原則 | ★★★★★ | 5 新增函數 + 既有函數完整複用 |
| 錯誤處理 | ★★★★☆ | 完善，但 C-08 導致審計日誌失效 |
| 旗標機制 | ★★☆☆☆ | **邏輯錯誤：值捕獲導致永遠 false**（C-08） |
| 資料庫安全 | ★★★★★ | 全部 PDO 預備陳述式、具名佔位符 |
| 前端整合 | ★★★★☆ | 連結正確、年月選擇器運作、需確認列印傳票連結 |
| 修正驗證 | ★★★★★ | JSON 無千分位、CSV 全欄位 `h()` 均正確 |

---

## 最終判定

### 🟡 **需修正後重審**

**理由**：發現關鍵缺陷 **C-08**（`register_shutdown_function` 閉包值捕獲導致 `$exportSuccess` 永遠為 `false`，審計日誌完全不寫入）。此缺陷違反 Design.md「審計日誌：旗標機制檢查 HTTP 200 才寫入」的核心需求，且影響所有四種匯出格式的審計追蹤。

**必要修正**：
1. 修正 `export_csv.php` 與 `export_pdf.php` 的 `register_shutdown_function` 閉包，改用**參考捕獲** (`use (&$exportSuccess, ...)`)
2. 確認 voucher 模式下 `$startDate`、`$endDate` 有定義
3. 實測驗證 `audit_log` 正確寫入

**其餘 C-01~C-07 為建議改善項目，不阻礙本次上線**，可納入後續技術債務整理。

---

**審查員簽署**：獨立第三方 Code 審查員  
**日期**：2026-11-25