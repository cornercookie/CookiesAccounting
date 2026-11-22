# Design: 匯入 CSV 明細記錄（批次匯入收支記錄）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Design: 匯入 CSV 明細記錄（批次匯入收支記錄） |
| 版本 | v1.0 |
| 建立日期 | 2026-11-22 |
| 更新日期 | 2026-11-22 |
| 變更摘要 | 初始設計，依 Planning 四項功能拆解為程式修改路徑與資料庫變更 |

## 來源 Planning 與需求摘要

- Planning 檔案：`docs/csv-import/planning.md`
- 對應 Feature：匯入 CSV 明細記錄（新增 `import_csv.php`）
- 需求摘要：新增「匯入 CSV」功能，讓使用者將外部 CSV 檔案中的收支記錄批次匯入至指定帳本。支援批次匯入，每筆記錄獨立驗證，錯誤記錄跳過並顯示錯誤原因，不影響其他記錄。範圍限於單一帳本匯入；不新增資料庫表，僅寫入既有 `entries` 表；CSV 檔案上傳後即時處理，不儲存在伺服器。

## 設計概述

本設計依循專案既有設計系統與硬性約束（PDO 預備陳述式、整數分金額、CSRF、h() XSS 轉義、ledger_access 權限模型、純 PHP 伺服端渲染、相對路徑 `./` 開頭）。主要變更如下：

1. **新增 `import_csv.php`**：匯入 CSV 頁面，包含 CSV 檔案上傳欄位與 CSRF 隱藏欄位；GET 顯示上傳表單，POST 接收檔案並進行驗證與匯入。
2. **帳本詳情頁按鈕**：在 `ledger.php` 帳本詳情頁新增「匯入 CSV」操作按鈕，與現有「匯出明細 CSV」按鈕並列。
3. **權限檢查**：所有查詢與寫入皆透過 `ledger_access` 做 IDOR 防護；owner/member 可匯入，viewer 僅可檢視（匯入為寫入操作，僅 owner/member 可執行）。
4. **CSRF 防護**：表單需包含 `csrf_token` 隱藏欄位，伺服端以 `checkCsrf()` 驗證。
5. **CSV 解析**：採用 `fgetcsv()` 逐行讀取，第一列視為標題行並跳過；支援 UTF-8（含 BOM 或不含 BOM，BOM 自動去除）。
6. **逐行驗證**：日期格式（`isValidDate()`）、類型（收入/支出/轉帳，轉換為 income/expense/transfer）、金額格式（`toCents()`，不得為負數）、分類長度（≤50 字元）、記錄者（若不提供則使用目前登入使用者 `$userId`）。
7. **錯誤處理**：驗證失敗的記錄跳過並記錄錯誤原因，不影響其他記錄；匯入完成後顯示結果摘要（成功筆數、失敗筆數、失敗原因）。
8. **audit_log 記錄**：匯入完成後呼叫 `logAudit('csv_import', 'ledger_id=... success=... failed=...')`。
9. **無資料庫變更**：複用既有 `entries` 表，不新增資料表，無 migration。
10. **硬性約束**：所有寫入必須用 PDO 預備陳述式、所有路徑 `./` 開頭、所有輸出 `h()` 轉義。

## 設計圖表（Mermaid）

```mermaid
flowchart TD
    A[使用者在 ledger.php 點擊「匯入 CSV」] --> B[驗證 ledger_id 與 ledger_access 權限]
    B -->|owner/member| C[顯示 import_csv.php 上傳表單]
    B -->|viewer| D[顯示無權限訊息，不顯示上傳表單]
    C --> E[使用者選擇 CSV 檔案並提交]
    E --> F[驗證 CSRF token（checkCsrf）]
    F -->|失敗| G[拒絕請求，顯示 CSRF 錯誤]
    F -->|成功| H[驗證檔案類型 .csv、大小 ≤2MB、防止路徑遍歷]
    H -->|失敗| I[顯示檔案錯誤訊息]
    H -->|成功| J[fgetcsv() 逐行讀取，跳過標題行，去除 BOM]
    J --> K{逐行驗證通過？}
    K -->|通過| L[以 PDO 預備陳述式寫入 entries 表]
    K -->|不通過| M[記錄錯誤原因，跳過該筆]
    L --> N{所有記錄處理完畢？}
    M --> N
    N -->|是| O[顯示結果摘要：成功筆數、失敗筆數、失敗原因]
    O --> P[logAudit('csv_import', ...)]
    P --> Q[匯入流程結束]
    G --> Q
    I --> Q
    D --> Q
```

## 程式修改路徑

| 檔案 | 修改內容 |
| --- | --- |
| `import_csv.php`（新增） | 匯入 CSV 頁面：<br>1. 頁面頂端呼叫 `requireLogin()`，確認使用者已登入。<br>2. 取得 `ledger_id` 參數（整數），透過 `ledger_access` 驗證權限（owner/member 可匯入，viewer 僅可檢視，匯入為寫入操作僅 owner/member 可執行）。<br>3. GET：顯示 CSV 檔案上傳表單，包含 `csrf_token` 隱藏欄位（`csrfToken()`）。<br>4. POST：驗證 CSRF（`checkCsrf()`）、驗證檔案類型（.csv）與大小（≤2MB）、防止路徑遍歷。<br>5. 用 `fgetcsv()` 逐行讀取，第一列標題行跳過，BOM 自動去除。<br>6. 逐行驗證：日期格式、`isValidDate()`；類型收入/支出/轉帳轉換為 income/expense/transfer；金額 `toCents()` 不得為負數；分類長度 ≤50 字元；記錄者若不提供則使用目前登入使用者 `$userId`。<br>7. 驗證通過以 PDO 預備陳述式（具名佔位符）寫入 `entries` 表；失敗記錄錯誤原因但不寫入。<br>8. 匯入完成顯示結果摘要：成功筆數、失敗筆數、失敗原因。<br>9. 呼叫 `logAudit('csv_import', 'ledger_id=' . $ledgerId . ' success=' . $successCount . ' failed=' . $failedCount)`。<br>10. 所有輸出經 `h()` 轉義，所有路徑 `./` 開頭，不儲存 CSV 檔案於伺服器（直接由 `$_FILES['tmp_name']` 讀取）。 |
| `ledger.php` | 在帳本詳情頁新增「匯入 CSV」操作按鈕，與現有「匯出明細 CSV」按鈕並列；按鈕連結至 `./import_csv.php?ledger_id=...`；僅 owner/member 可見（viewer 不顯示）。 |
| `assets/style.css` | 新增匯入 CSV 頁面表單與結果摘要樣式（`.import-form`、`.import-result`、`.import-error`），沿用既有 `.btn`、`.alert` 樣式，支援手機單欄顯示。 |
| 無新增資料表 | 不涉及資料庫結構變更，無 migration。 |

## 資料庫變更

無。複用既有 `entries` 表，不新增資料表，無 migration 需要。

## 前端變更說明

### 匯入 CSV 頁面（import_csv.php）

- **上傳表單**：欄位為 CSV 檔案（file，required，accept=".csv"）；表單含 `csrf_token` 隱藏欄位；提交方式 POST，enctype="multipart/form-data"。
- **檔案限制**：僅允許 .csv 副檔名，檔案大小上限 2MB；超過限制顯示友善錯誤訊息。
- **結果摘要**：匯入完成後顯示成功筆數、失敗筆數、失敗原因列表（每筆失敗記錄的行號與錯誤原因）。
- **所有輸出**：檔案名稱、錯誤訊息、結果摘要皆經 `h()` 轉義，無 XSS 風險。
- **不儲存伺服器**：CSV 檔案上傳後即時處理，直接由 `$_FILES['tmp_name']` 讀取，不寫入伺服器磁碟。

### 帳本詳情頁按鈕（ledger.php）

- **位置**：`ledger.php` 帳本詳情頁，與現有「匯出明細 CSV」按鈕並列。
- **顯示條件**：僅 owner/member 可見；viewer 不顯示該按鈕。
- **連結**：`./import_csv.php?ledger_id=<ledger_id>`（相對路徑 `./` 開頭）。

## 預期行為與驗證方法

| 驗收項 | 預期行為 | 驗證方法 |
| --- | --- | --- |
| 匯入頁面存在 | `import_csv.php` 可顯示 CSV 上傳表單，含 CSRF 隱藏欄位 | 以 owner/member 登入，開啟匯入頁面，確認表單與 csrf_token 欄位存在 |
| 帳本詳情頁按鈕 | 帳本詳情頁顯示「匯入 CSV」按鈕，與匯出按鈕並列 | 以 owner/member 登入 ledger.php，確認按鈕出現；以 viewer 登入確認不顯示 |
| 權限控管 | viewer 僅可檢視，不可匯入；owner/member 可匯入 | 以 viewer 直接訪問 import_csv.php，確認受阻 |
| CSRF 防護 | 提交表單需含 csrf_token，伺服端以 checkCsrf() 驗證 | 移除 csrf_token 提交，確認被拒絕 |
| 檔案限制 | 僅允許 .csv，大小 ≤2MB | 上傳非 .csv 檔或超過 2MB 檔案，確認顯示錯誤訊息 |
| 逐行驗證 | 日期格式錯誤、金額負數、分類超過 50 字元的記錄跳過 | 建立含錯誤記錄的 CSV，確認錯誤記錄被跳過且顯示原因 |
| 記錄者預設 | 記錄者欄位不提供時使用目前登入使用者 $userId | 匯入不含記錄者欄位的 CSV，確認記錄者為登入使用者 |
| 金額儲存 | 金額以整數分儲存，顯示時經 formatCents() | 檢查資料庫 entries.amount 欄位為整數，顯示頁面為格式化後數值 |
| 結果摘要 | 匯入完成顯示成功筆數、失敗筆數、失敗原因 | 匯入含錯誤記錄的 CSV，確認摘要正確 |
| audit_log 記錄 | 匯入後寫入 audit_log，動作代碼 csv_import | 檢查 audit_log 表，確認有對應記錄 |
| 與現有功能並存 | 不破壞既有 ledger.php、export_csv.php 等功能 | 確認既有頁面仍正常運作 |

## 非本期範圍（Out of Scope）

- 不新增多帳本合併匯入（每次僅匯入單一帳本）。
- 不新增匯入預覽頁面（匯入後直接顯示結果）。
- 不新增匯入範本下載功能。
- 不新增自動定期匯入（需排程功能，列為後續版本）。
- 不檢查重複記錄（目前規劃為不檢查重複，直接匯入）。

## 身分驗證與權限模型

- 使用者必須先登入（`requireLogin()`）。
- 匯入為寫入操作，僅 owner/member 可執行；viewer 僅可檢視，不可匯入。
- 所有查詢與寫入皆透過 `ledger_access` 做 IDOR 防護，使用者只能匯入其被授權帳本的記錄。
- 不變更既有登入與帳本權限模型，繼續使用 session 登入與 `ledger_access` 權限檢查。

## 資料傳輸加密標準

無新增外部 API 呼叫，全部為伺服端 SQL 與 session 驗證，資料僅儲存在站台自身 SQLite 資料庫。CSV 檔案上傳後即時處理，不儲存在伺服器。

## 非功能性需求

- **效能**：CSV 匯入涉及多筆記錄寫入，但 SQLite 與 WAL 模式可接受；單一帳本查詢，效能影響可忽略。
- **併發**：僅讀取查詢與單一寫入，資料庫寫入競合可接受。
- **資料保留**：匯入的記錄與既有記錄同樣保留，軟刪除後不顯示但不刪除實體資料。
- **可用性**：匯入失敗時顯示友善錯誤訊息，錯誤寫入 `error.log`；錯誤記錄不中斷整體匯入。

## 差異與待確認

與 Planning 核心需求一致，所有待確認事項於 Planning 中已鎖定。

## 實際變更（實作階段）

| 檔案 | 變更內容 |
| --- | --- |
| `import_csv.php`（新增） | 待實作 |
| `ledger.php` | 新增「匯入 CSV」按鈕，待實作 |
| `assets/style.css` | 新增匯入頁面樣式，待實作 |
| 無新增資料表 | 不涉及資料庫結構變更，無 migration |

## 驗證證據（實作階段）

| 驗收項 | 驗證結果 |
| --- | --- |
| 匯入頁面存在 | 待實作階段驗證 |
| 帳本詳情頁按鈕 | 待實作階段驗證 |
| 權限控管 | 待實作階段驗證 |
| CSRF 防護 | 待實作階段驗證 |
| 檔案限制 | 待實作階段驗證 |
| 逐行驗證 | 待實作階段驗證 |
| 記錄者預設 | 待實作階段驗證 |
| 金額儲存 | 待實作階段驗證 |
| 結果摘要 | 待實作階段驗證 |
| audit_log 記錄 | 待實作階段驗證 |
| 與現有功能並存 | 待實作階段驗證 |

## 設計審查記錄（設計階段）

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 已通過 | 2026-11-22 | 與 Planning 與現有程式碼一致 |
| Code（程式）審查 | 待實作後審查 | - | - |
| Security（資安）審查 | 待實作後審查 | - | - |
