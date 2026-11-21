# Planning: 匯出 PDF 月結單（多月份收支彙總）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Planning: 匯出 PDF 月結單（多月份收支彙總） |
| 版本 | v1.1 |
| 建立日期 | 2026-11-22 |
| 更新日期 | 2026-11-22 |
| 章節變更摘要 | v1.1 補充日期區間參數規定、檔名 sanitize、PDF 錯誤處理策略、TCPDF 部署備援方案、中文檔名相容性、audit_log 記錄 |

## Description

為「全家人記帳系統」新增「匯出 PDF 月結單」功能，讓使用者能將指定帳本的收支明細匯出為 PDF 檔案，方便歸檔、列印或分享給家人。PDF 包含本月或自訂期間的收支明細表、總收入、總支出、總轉帳、總結餘，以及預算超支提醒（如適用）。

範圍限於單一帳本的 PDF 匯出；不新增資料庫表，僅複用既有 `entries` 表；PDF 產生使用純 PHP 庫（TCPDF），不需要 Composer，可直接下載檔案放入專案目錄；若部署環境無法下載 TCPDF，可退而求其次提供列印優化的 HTML 頁面，讓使用者透過瀏覽器「列印為 PDF」。

## User Story

- 作為家長，我希望能把本月或年度的收支明細匯出為 PDF 檔案，方便歸檔或分享給家人。
- 作為家庭成員，我希望能將特定期間的收支明細列印為 PDF，貼在家中供長輩核對。

## To Reproduce

本項目非錯誤（bug）類需求，不適用重現步驟。

## Expected behavior

### 匯出 PDF 月結單
- 使用者可在帳本詳情頁點擊「匯出 PDF 月結單」按鈕
- 系統將該帳本的所有非作廢記錄匯出為 PDF 檔案
- PDF 包含：帳本名稱、查詢期間、收入、支出、轉帳、結餘總額，以及詳細記錄列表（日期、類型、金額、分類、記錄者、備註）
- 金額皆經 `formatCents()` 格式化（整數分除以 100、千分位顯示）
- 所有輸出經 `h()` 轉義

### 權限檢查
- 所有查詢皆透過 `ledger_access` 做 IDOR 防護，使用者只能匯出其被授權帳本的報表
- viewer 僅可檢視，owner/member 可檢視（查詢為唯讀，不涉及寫入）

### 日期區間參數
- 支援 `start_date` 與 `end_date` GET 參數，格式為 `Y-m-d`（例如 `2026-01-01` ~ `2026-12-31`）
- 若未提供 `start_date` 與 `end_date`，預設為當月首日與當月最後一日（Asia/Taipei 時區）
- 日期必須透過 `isValidDate()` 驗證，格式不符時顯示錯誤訊息
- 結束日期不得早於開始日期，否則顯示錯誤訊息

### 檔案命名
- 檔名格式：`ledger_{ledger_id}_{安全化帳本名稱}_{日期時間}.pdf`
- 例如：`ledger_3_家庭共用_20261122_143000.pdf`
- 帳本名稱需 sanitize，禁止路徑遍歷字元（`../`、`..`、`/`、`\` 等），參照 `export_csv.php` 的 `preg_replace('/[^\p{L}\p{N}_-]/u', '_', $ledger['name'])` 處理
- 中文檔名在 `Content-Disposition` 標頭中，建議同時提供 `filename*` (RFC 5987) 與 `filename` 兩種格式，確保中文顯示正確

### 錯誤處理
- 查詢失敗時不中斷頁面渲染，改寫 `error.log` 並顯示友善錯誤訊息
- PDF 生成失敗時顯示友善錯誤訊息，不中斷頁面
- PDF 生成時先在記憶體中產生（`ob_start()` 或直接寫入字串變數），確認成功後再輸出 HTTP 標頭與內容，避免表頭已送出後無法顯示 HTML 錯誤頁面
- 若 TCPDF 庫不存在或載入失敗，退而求其次提供列印優化的 HTML 頁面，讓使用者透過瀏覽器「列印為 PDF」

### 身分驗證與權限模型
不變更既有登入與帳本權限模型。繼續使用 session 登入與 `ledger_access` 權限檢查（owner/member/viewer 均可檢視報表，因為僅為唯讀查詢）。

### 資料傳輸加密標準
無新增外部 API 呼叫，全部為伺服端 SQL 與 session 驗證，資料僅儲存在站台自身 SQLite 資料庫。PDF 檔案直接輸出至瀏覽器下載，不儲存在伺服器。

## Acceptance Criteria

1. 存在匯出 PDF 月結單功能（新增 `export_pdf.php` 頁面）
2. 帳本詳情頁提供「匯出 PDF 月結單」操作按鈕
3. 匯出動作具備嚴格的帳本權限驗證（透過 `ledger_access` 做 IDOR 防護）
4. 匯出的 PDF 包含：帳本名稱、查詢期間、收入、支出、轉帳、結餘總額，以及詳細記錄列表
5. 金額皆經 `formatCents()` 格式化（整數分除以 100、千分位顯示）
6. 所有輸出經 `h()` 轉義，無 XSS 風險
7. PDF 檔名格式正確：`ledger_{ledger_id}_{安全化帳本名稱}_{日期時間}.pdf`
8. 無記錄時顯示「該期間無記錄」文字，而非空白 PDF
9. 查詢失敗時不中斷頁面渲染，錯誤寫入 `error.log`
10. 與現有功能並存，不破壞既有 `ledger.php`、`export_csv.php` 等功能
11. 年份/日期參數不合法時顯示友善錯誤訊息
12. PDF 生成失敗時顯示友善錯誤訊息，不中斷頁面
13. 匯出成功後記錄 audit_log（動作代碼 `pdf_export`，detail 帶入 ledger_id、start_date、end_date）

## Additional context

### 資料庫變更
- 無新增資料庫表，僅複用既有 `entries` 表
- 無需 migration

### 資料流（Data Flow）
1. 使用者在帳本詳情頁點擊「匯出 PDF 月結單」按鈕
2. 伺服端驗證 `ledger_id`（整數）、`ledger_access` 權限（owner/member/viewer 均可檢視）、`start_date` 與 `end_date`（Y-m-d 格式，經 `isValidDate()` 驗證）
3. 查詢該帳本所有非作廢記錄（`WHERE ledger_id = :ledger_id AND deleted = 0 AND entry_date BETWEEN :startDate AND :endDate`）
4. 使用 TCPDF 產生 PDF 檔案，包含標題、彙總表、詳細記錄表
5. 設定 `Content-Disposition: attachment` 標頭，觸發瀏覽器下載
6. 匯出成功後，呼叫 `logAudit('pdf_export', 'ledger_id=' . $ledgerId . ' start_date=' . $startDate . ' end_date=' . $endDate)` 記錄至 audit_log
7. 檔名格式：`ledger_{ledger_id}_{安全化帳本名稱}_{日期時間}.pdf`

### 與現有程式碼相容性
- `export_csv.php` 已實作 CSV 匯出，使用純 PHP，可參考其權限檢查與查詢模式
- `ledger.php` 已有列印樣式（`@media print`），可參考其版面設計
- `formatCents()`、`h()`、`requireLogin()` 等現有輔助函數可直接複用，無需新增
- 不修改既有 `ledger.php`、`dashboard.php` 等頁面，僅新增 `export_pdf.php` 頁面

### PDF 產生庫選擇
- 使用 TCPDF（純 PHP 庫，不需要 Composer，可直接下載檔案放入專案目錄）
- TCPDF 官方網站：https://tcpdf.org/
- 下載後將 `tcpdf.php` 及相關檔案放入專案目錄（例如 `./lib/tcpdf/`）
- 部署時需將 TCPDF 檔案一併上傳至主機
- 載入方式：`require_once './lib/tcpdf/tcpdf.php'`；載入失敗時，退而求其次提供列印優化的 HTML 頁面，讓使用者透過瀏覽器「列印為 PDF」

### 身分驗證與權限模型
不變更既有登入與帳本權限模型。繼續使用 session 登入與 `ledger_access` 權限檢查（owner/member/viewer 均可檢視報表，因為僅為唯讀查詢）。

### 資料傳輸加密標準
無新增外部 API 呼叫，全部為伺服端 SQL 與 session 驗證，資料僅儲存在站台自身 SQLite 資料庫。PDF 檔案直接輸出至瀏覽器下載，不儲存在伺服器。

## 非功能性需求
- **效能**：PDF 匯出涉及較多記錄，但 SQLite 與 WAL 模式可接受；單一帳本查詢，效能影響可忽略
- **併發**：僅讀取查詢，無資料庫寫入競合；PDF 生成為單次作業，不會長期佔用資源
- **資料保留**：不涉及資料寫入，無資料保留問題；PDF 檔案直接下載，不儲存在伺服器
- **可用性**：查詢失敗時不中斷頁面渲染，錯誤寫入 `error.log`；PDF 生成失敗時顯示友善錯誤訊息

## 差異與待確認
與 Planning 核心需求一致，所有待確認事項於 Planning 中已鎖定。

## 待釐清事項（非阻塞）
1. **頁面位置**：PDF 匯出按鈕可放在 `ledger.php` 帳本詳情頁，與現有「匯出明細 CSV」按鈕並列
2. **PDF 頁面方向**：預設採用直向（Portrait），若欄位過多可考慮橫向（Landscape）

## 非本期範圍（Out of Scope）
- 不新增多帳本合併報表（每次僅查看單一帳本）
- 不新增圖表匯出（圖形化報表）
- 不新增預算對比（年度預算設定列為後續版本）
- 不新增自動定期匯出（需排程功能，列為後續版本）