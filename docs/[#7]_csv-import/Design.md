# Design: 匯入 CSV 明細記錄（批次匯入收支記錄）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Design: 匯入 CSV 明細記錄（批次匯入收支記錄） |
| 版本 | v1.1 |
| 建立日期 | 2026-11-22 |
| 更新日期 | 2026-11-22 |
| 變更摘要 | v1.1 修正規格差異：ledger.php 匯入按鈕已存在、補充 CSV 公式注入防護、檔案 MIME 驗證、BOM 處理、欄位數驗證、記錄者查詢驗證、錯誤原因限制筆數；依既有程式碼模式細化實作細節 |

## 來源 Planning 與需求摘要

- Planning 檔案：`docs/csv-import/planning.md`
- 對應 Feature：匯入 CSV 明細記錄（新增 `import_csv.php`）
- 需求摘要：新增「匯入 CSV」功能，讓使用者將外部 CSV 檔案中的收支記錄批次匯入至指定帳本。支援批次匯入，每筆記錄獨立驗證，錯誤記錄跳過並顯示錯誤原因，不影響其他記錄。範圍限於單一帳本匯入；不新增資料庫表，僅寫入既有 `entries` 表；CSV 檔案上傳後即時處理，不儲存在伺服器。

## 設計概述

本設計依循專案既有設計系統與硬性約束（PDO 預備陳述式、整數分金額、CSRF、h() XSS 轉義、ledger_access 權限模型、純 PHP 伺服端渲染、相對路徑 `./` 開頭）。主要變更如下：

1. **新增 `import_csv.php`**：匯入 CSV 頁面，包含 CSV 檔案上傳欄位與 CSRF 隱藏欄位；GET 顯示上傳表單，POST 接收檔案並進行驗證與匯入。
2. **帳本詳情頁按鈕**：`ledger.php` **已存在**「匯入 CSV 明細」按鈕（line 494-498），與「匯出明細 CSV」並列，僅 `$canWrite` (owner/member) 顯示；**無需修改 ledger.php**。
3. **權限檢查**：所有查詢與寫入皆透過 `ledger_access` 做 IDOR 防護；owner/member 可匯入，viewer 僅可檢視（匯入為寫入操作，僅 owner/member 可執行）。參考 `export_csv.php` line 22-34 模式。
4. **CSRF 防護**：表單需包含 `csrf_token` 隱藏欄位，伺服端以 `checkCsrf()` 驗證；失敗導向並帶錯誤訊息。
5. **CSV 解析**：採用 `fgetcsv()` 逐行讀取，第一列視為標題行並跳過；支援 UTF-8（含 BOM 或不含 BOM，BOM 自動去除：**讀取第一行時檢查 `$header[0]` 是否以 `\xEF\xBB\xBF` 開頭，若是則移除**）。
6. **逐行驗證**：
   - 日期格式（`isValidDate()`）
   - 類型（收入/支出/轉帳 → income/expense/transfer）
   - 金額格式（`toCents()`，不得為負數）
   - 分類長度（≤50 字元）
   - 記錄者：CSV 提供時查詢 `users` 表驗證存在性（username 對應 user_id），不存在或空值 → 使用目前登入使用者 `$userId`
   - **欄位數驗證**：每行必須為 6 欄位（日期、類型、金額、分類、記錄者、備註），不足補空字串，超過截斷並記錄警告
7. **CSV 公式注入防護**（同 `export_csv.php` line 77-84）：寫入資料庫前對 `category`、`note`、`recorder` 欄位檢查開頭字元是否為 `=`, `+`, `-`, `@`, `\t`, `\r`，若是則前綴單引號 `'`。
8. **檔案上傳驗證**：
   - 副檔名 `.csv`（大小寫不敏感）
   - 檔案大小 ≤ 2MB
   - **MIME type 驗證**：`finfo_file()` 確認為 `text/csv`、`text/plain`、`application/csv` 之一
   - 上傳錯誤代碼檢查（`UPLOAD_ERR_OK`）
   - 直接讀取 `$_FILES['csv']['tmp_name']`，**不儲存檔案於伺服器**
9. **錯誤處理**：驗證失敗的記錄跳過並記錄錯誤原因（行號、欄位、原因），不影響其他記錄；**失敗原因最多記錄前 100 筆**，超過顯示「其餘略過」；匯入完成後顯示結果摘要（成功筆數、失敗筆數、失敗原因）。
10. **audit_log 記錄**：匯入完成後呼叫 `logAudit('csv_import', 'ledger_id=' . $ledgerId . ' success=' . $successCount . ' failed=' . $failedCount)`。
11. **無資料庫變更**：複用既有 `entries` 表，不新增資料表，無 migration。
12. **硬性約束**：所有寫入必須用 PDO 預備陳述式、所有路徑 `./` 開頭、所有輸出 `h()` 轉義。

## 設計圖表（Mermaid）

```mermaid
flowchart TD
    A[使用者在 ledger.php 點擊「匯入 CSV 明細」] --> B[驗證 ledger_id 與 ledger_access 權限]
    B -->|owner/member| C[顯示 import_csv.php 上傳表單]
    B -->|viewer| D[顯示無權限訊息，不顯示上傳表單]
    C --> E[使用者選擇 CSV 檔案並提交]
    E --> F[驗證 CSRF token（checkCsrf）]
    F -->|失敗| G[拒絕請求，顯示 CSRF 錯誤]
    F -->|成功| H[驗證檔案：副檔名.csv、大小≤2MB、MIME type、tmp_name 存在]
    H -->|失敗| I[顯示檔案錯誤訊息]
    H -->|成功| J[fopen tmp_name，fgetcsv() 逐行讀取]
    J --> K[第一行：標題行跳過，檢查 BOM（\xEF\xBB\xBF）移除]
    K --> L{逐行處理：行號從 2 開始}
    L --> M[欄位數驗證：6 欄位，不足補空、超過截斷]
    M --> N[解析：日期、類型、金額、分類、記錄者、備註]
    N --> O{逐行驗證全部通過？}
    O -->|日期 isValidDate| O
    O -->|類型 收入/支出/轉帳→income/expense/transfer| O
    O -->|金額 toCents() >0| O
    O -->|分類 ≤50 字元 + 公式注入防護| O
    O -->|記錄者：查 users 表驗證 username 存在 → user_id，否則用登入 userId| O
    O -->|備註：公式注入防護| O
    O -->|通過| P[PDO 預備陳述式寫入 entries 表]
    O -->|不通過| Q[記錄錯誤原因（行號、欄位、原因），失敗計數+1，錯誤列表≤100 筆]
    P --> R{所有記錄處理完畢？}
    Q --> R
    R -->|是| S[顯示結果摘要：成功筆數、失敗筆數、失敗原因列表]
    S --> T[logAudit('csv_import', ledger_id=..., success=..., failed=...)]
    T --> U[匯入流程結束]
    G --> U
    I --> U
    D --> U
```

## 程式修改路徑

| 檔案 | 修改內容 |
| --- | --- |
| `import_csv.php`（新增） | 匯入 CSV 頁面：<br>1. 頁面頂端呼叫 `requireLogin()`，確認使用者已登入。<br>2. 取得 `ledger_id` 參數（整數，`filter_var(..., FILTER_VALIDATE_INT)`），透過 `ledger_access` 驗證權限（參考 `export_csv.php` line 22-34）：查詢 `ledgers` + `ledger_access` 確認帳本存在且用戶有權限；owner/member 可匯入，viewer 導向錯誤頁或顯示無權限訊息。<br>3. GET：顯示 CSV 檔案上傳表單，包含 `csrf_token` 隱藏欄位（`csrfToken()`），`enctype="multipart/form-data"`，`accept=".csv"`。<br>4. POST：<br>   a. 驗證 CSRF（`checkCsrf()`），失敗導向帶錯誤訊息。<br>   b. 驗證檔案：`$_FILES['csv']['error'] === UPLOAD_ERR_OK`、副檔名 `.csv`、大小 ≤ 2MB、**MIME type**（`finfo_file()` 檢查 `text/csv`、`text/plain`、`application/csv`）、`tmp_name` 存在。<br>   c. 用 `fopen($tmpName, 'r')` 開啟，`fgetcsv()` 逐行讀取。<br>   d. 第一行標題行：跳過，並**檢查 BOM**（`str_starts_with($header[0], "\xEF\xBB\xBF")` 則 `$header[0] = substr($header[0], 3)`）。<br>   e. 逐行處理（行號從 2 開始）：<br>      - **欄位數驗證**：`count($row) < 6` 補空字串，`> 6` 截斷並記錄警告。<br>      - 解析 6 欄位：日期、類型、金額、分類、記錄者、備註。<br>      - 日期：`isValidDate($date)`。<br>      - 類型：中文收入/支出/轉帳 → `income`/`expense`/`transfer`。<br>      - 金額：`toCents($amount)` 回傳 `false` 或 ≤0 視為錯誤。<br>      - 分類：`mb_strlen($category, 'UTF-8') <= 50`。**CSV 公式注入防護**：開頭為 `=`, `+`, `-`, `@`, `\t`, `\r` 者前綴 `'`。<br>      - 記錄者：若提供，查詢 `users` 表 `WHERE username = ? LIMIT 1` 取得 `user_id`；不存在或空值 → 使用目前登入 `$userId`。**備註同樣做公式注入防護**。<br>      - 驗證通過：PDO 預備陳述式寫入 `entries` 表（具名佔位符：`ledger_id`, `user_id`, `amount_cents`, `type`, `category`, `entry_date`, `note`, `receipt_path=''`, `deleted=0`, `recurring_source_id=NULL`）。<br>      - 驗證失敗：記錄錯誤原因（`"第 $rowNum 行：$field $reason"`），**失敗原因陣列最多 100 筆**，跳過該行。<br>   f. 關閉檔案控制代碼。<br>5. 匯入完成顯示結果摘要：成功筆數、失敗筆數、失敗原因列表（行號、錯誤訊息，皆經 `h()` 轉義）。<br>6. 呼叫 `logAudit('csv_import', 'ledger_id=' . $ledgerId . ' success=' . $successCount . ' failed=' . $failedCount)`。<br>7. 所有輸出經 `h()` 轉義，所有路徑 `./` 開頭，不儲存 CSV 檔案於伺服器。 |
| `ledger.php` | **已完成**（line 494-498）：帳本詳情頁已有「匯入 CSV 明細」按鈕，與「匯出明細 CSV」並列；連結 `./import_csv.php?ledger_id=...`；僅 `$canWrite` (owner/member) 顯示。**無需修改**。 |
| `assets/style.css` | 新增匯入 CSV 頁面表單與結果摘要樣式（`.import-form`、`.import-result`、`.import-error`），沿用既有 `.btn`、`.alert`、`.form`、`.input`、`.table` 樣式，支援手機單欄顯示（≥44px 觸控目標）。 |
| 無新增資料表 | 不涉及資料庫結構變更，無 migration。 |

## 資料庫變更

無。複用既有 `entries` 表，不新增資料表，無 migration 需要。

## 前端變更說明

### 匯入 CSV 頁面（import_csv.php）

- **上傳表單**：欄位為 CSV 檔案（`<input type="file" name="csv" required accept=".csv">`）；表單含 `csrf_token` 隱藏欄位（`value="<?php echo h(csrfToken()); ?>"`）；提交方式 POST，`enctype="multipart/form-data"`；按鈕 `<button type="submit" class="btn primary">開始匯入</button>`。
- **檔案限制提示**：表單下方顯示「僅允許 .csv 副檔名，檔案大小上限 2MB，編碼 UTF-8（含/不含 BOM 均可），欄位順序：日期、類型、金額、分類、記錄者、備註」。
- **錯誤顯示**：驗證失敗時在表單上方顯示 `.alert.error`（經 `h()` 轉義）。
- **結果摘要**：匯入完成後顯示成功筆數、失敗筆數、失敗原因列表（每筆：行號、錯誤訊息，經 `h()` 轉義）；失敗原因超過 100 筆時顯示「其餘 X 筆錯誤略過」。
- **所有輸出**：檔案名稱、錯誤訊息、結果摘要皆經 `h()` 轉義，無 XSS 風險。
- **不儲存伺服器**：CSV 檔案上傳後即時處理，直接由 `$_FILES['csv']['tmp_name']` 讀取，不寫入伺服器磁碟。
- **頁面結構參考**：`add_entry.php` 的側欄、頁首、表單排版、`.form-grid`、`.btn`、`.alert` 樣式。

### 帳本詳情頁按鈕（ledger.php）

- **位置**：`ledger.php` 帳本詳情頁，與現有「匯出明細 CSV」按鈕並列（line 494-498）。
- **顯示條件**：僅 owner/member 可見（`$canWrite = in_array($accessRole, ['owner', 'member'], true)`）；viewer 不顯示該按鈕。
- **連結**：`./import_csv.php?ledger_id=<ledger_id>`（相對路徑 `./` 開頭）。
- **狀態**：**已完成，無需修改**。

## 預期行為與驗證方法

| 驗收項 | 預期行為 | 驗證方法 |
| --- | --- | --- |
| 匯入頁面存在 | `import_csv.php` 可顯示 CSV 上傳表單，含 CSRF 隱藏欄位、檔案限制提示 | 以 owner/member 登入，開啟匯入頁面，確認表單、csrf_token 欄位、accept=".csv"、檔案限制提示文字存在 |
| 帳本詳情頁按鈕 | 帳本詳情頁顯示「匯入 CSV 明細」按鈕，與匯出按鈕並列 | 以 owner/member 登入 ledger.php，確認按鈕出現；以 viewer 登入確認不顯示（**已驗證 line 494-498 存在**） |
| 權限控管 | viewer 僅可檢視，不可匯入；owner/member 可匯入 | 以 viewer 直接訪問 import_csv.php，確認受阻（導向或顯示無權限） |
| CSRF 防護 | 提交表單需含 csrf_token，伺服端以 checkCsrf() 驗證 | 移除 csrf_token 提交，確認被拒絕並顯示友善錯誤訊息 |
| 檔案限制 | 僅允許 .csv，大小 ≤2MB，**MIME type 驗證通過** | 上傳非 .csv 檔、超過 2MB 檔案、非文字檔（如 .php 偽裝 .csv），確認顯示錯誤訊息 |
| BOM 處理 | UTF-8 BOM 正確去除，不影響資料解析 | 上傳含 BOM 的 CSV，確認第一筆資料日期欄位正確解析 |
| 欄位數驗證 | 每行 6 欄位；不足補空、超過截斷並記錄警告 | 上傳欄位數不足/超過的 CSV，確認處理正確且有警告記錄 |
| 逐行驗證 | 日期格式錯誤、金額負數/格式錯、分類超過 50 字元、類型不正確的記錄跳過 | 建立含各類錯誤記錄的 CSV，確認錯誤記錄被跳過且顯示原因（行號、欄位、原因） |
| 記錄者驗證 | CSV 提供記錄者時查詢 users 表驗證存在性；不存在或空值 → 登入使用者 | 匯入含存在/不存在/空白記錄者的 CSV，確認 user_id 正確寫入 |
| CSV 公式注入防護 | category/note/recorder 開頭為 =,+,-,@,\t,\r 者前綴單引號 | 匯入含公式注入內容的 CSV，檢查資料庫欄位已加單引號前綴 |
| 金額儲存 | 金額以整數分儲存，顯示時經 formatCents() | 檢查資料庫 entries.amount_cents 欄位為整數，顯示頁面為格式化後數值 |
| 結果摘要 | 匯入完成顯示成功筆數、失敗筆數、失敗原因（最多 100 筆，超過顯示略過） | 匯入含錯誤記錄的 CSV，確認摘要正確；錯誤 >100 筆時確認略過提示 |
| audit_log 記錄 | 匯入後寫入 audit_log，動作代碼 csv_import，含 ledger_id、success、failed | 檢查 audit_log 表，確認有對應記錄 |
| 與現有功能並存 | 不破壞既有 ledger.php、export_csv.php、add_entry.php 等功能 | 確認既有頁面仍正常運作 |

## 非本期範圍（Out of Scope）

- 不新增多帳本合併匯入（每次僅匯入單一帳本）。
- 不新增匯入預覽頁面（匯入後直接顯示結果）。
- 不新增匯入範本下載功能。
- 不新增自動定期匯入（需排程功能，列為後續版本）。
- 不檢查重複記錄（目前規劃為不檢查重複，直接匯入）。

## 身分驗證與權限模型

- 使用者必須先登入（`requireLogin()`），Session 經 `requireSessionFresh()` 檢查（密碼變更失效、閒置 30 分鐘過期）。
- 匯入為寫入操作，僅 `ledger_access.role IN ('owner', 'member')` 可執行；`viewer` 僅可檢視，不可匯入（直接訪問 import_csv.php 會被阻擋）。
- 所有查詢與寫入皆透過 `ledger_access` 做 IDOR 防護（`INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id`），使用者只能匯入其被授權帳本的記錄。
- 封存帳本（`ledgers.archived=1`）不可匯入，導向錯誤頁。
- 不變更既有登入與帳本權限模型，繼續使用 session 登入與 `ledger_access` 權限檢查。

## 資料傳輸加密標準

無新增外部 API 呼叫，全部為伺服端 SQL 與 session 驗證，資料僅儲存在站台自身 SQLite 資料庫。CSV 檔案上傳後即時處理，不儲存在伺服器。

## 非功能性需求

- **效能**：CSV 匯入涉及多筆記錄寫入，但 SQLite 與 WAL 模式可接受；單一帳本查詢，效能影響可忽略。`fgetcsv()` 逐行讀取，不將整個檔案載入記憶體。
- **併發**：僅讀取查詢與單一寫入，資料庫寫入競合可接受。
- **資料保留**：匯入的記錄與既有記錄同樣保留，軟刪除後不顯示但不刪除實體資料。
- **可用性**：匯入失敗時顯示友善錯誤訊息，詳細錯誤寫入 `error.log`（`logError()`）；錯誤記錄不中斷整體匯入。
- **記憶體控制**：失敗原因列表最多保留 100 筆，超過以「其餘 X 筆錯誤略過」提示，避免大量錯誤導致記憶體耗盡。

## 差異與待確認

與 Planning 核心需求一致，所有待確認事項於 Planning 中已鎖定。

## 實際變更（實作階段）

| 檔案 | 變更內容 |
| --- | --- |
| `import_csv.php`（新增） | 待實作（參考上方「程式修改路徑」詳細規格） |
| `ledger.php` | **已完成**（line 494-498 已有「匯入 CSV 明細」按鈕，無需修改） |
| `assets/style.css` | 新增匯入頁面樣式（`.import-form`、`.import-result`、`.import-error`），沿用既有樣式，待實作 |
| 無新增資料表 | 不涉及資料庫結構變更，無 migration |

## 驗證證據（實作階段）

| 驗收項 | 驗證結果 |
| --- | --- |
| 匯入頁面存在 | 待實作階段驗證 |
| 帳本詳情頁按鈕 | **已驗證完成**（ledger.php line 494-498 已存在） |
| 權限控管 | 待實作階段驗證 |
| CSRF 防護 | 待實作階段驗證 |
| 檔案限制（含 MIME 驗證） | 待實作階段驗證 |
| BOM 處理 | 待實作階段驗證 |
| 欄位數驗證 | 待實作階段驗證 |
| 逐行驗證 | 待實作階段驗證 |
| 記錄者驗證（查詢 users 表） | 待實作階段驗證 |
| CSV 公式注入防護 | 待實作階段驗證 |
| 金額儲存（整數分） | 待實作階段驗證 |
| 結果摘要（失敗原因 ≤100 筆） | 待實作階段驗證 |
| audit_log 記錄 | 待實作階段驗證 |
| 與現有功能並存 | 待實作階段驗證 |

## 設計審查記錄（設計階段）

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | **條件通過（v1.1 已修正）** | 2026-11-22 | 已修正：ledger.php 按鈕既存、補充 CSV 公式注入防護、MIME 驗證、BOM 處理、欄位數驗證、記錄者查詢驗證、錯誤原因限制 100 筆 |
| Code（程式）審查 | 待實作後審查 | - | 實作時須遵循 `export_csv.php`、`add_entry.php` 既有模式 |
| Security（資安）審查 | **條件通過（v1.1 已修正）** | 2026-11-22 | 已補強：檔案 MIME 驗證、CSV 公式注入防護、記錄者查詢驗證、錯誤資訊不外洩 |
