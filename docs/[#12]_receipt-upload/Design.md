# Design & Implementation: 拍照收據 / 發票小圖夾

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 名稱 | 拍照收據 / 發票小圖夾設計 |
| 版本 | v1.2 |
| 建立日期 | 2026-11-21 |
| 更新日期 | 2026-11-27 |
| 變更摘要 | v1.2 修正原圖預覽和手機收據入口，保留既有下載路由，記錄 PHP 上傳上限與實測。v1.1 建立縮圖／下載模式、共用上傳及部署說明。 |

## 1. 變更概述

允許使用者在新增或編輯記錄時上傳收據/發票照片，系統安全儲存於後端私密目錄，並在明細與詳情頁面透過權限驗證後顯示小圖，點擊可放大檢視。

## 2. 資料庫與架構變更

- **Schema Migration**：
  - 在 `entries` 資料表新增欄位 `receipt_path` (TEXT NOT NULL DEFAULT '')，用於存放私密目錄內的相對路徑（例如 `private_data/uploads/<random>.jpg`）。
- **私密目錄安全（SEC-02）**：
  - 檔案存放於 `private_data/uploads/`（Web Root 外或由 `.htaccess` 拒絕直接靜態存取）。
  - 上傳時強制以 `bin2hex(random_bytes(16))` 重新命名，禁止保留原始檔名（防止 WebShell 與檔名碰撞）。
  - 嚴格檢查 MIME Type（`finfo_file`）與副檔名（允許 jpeg/png/webp），大小上限 5MB。
  - 讀取圖片時透過後端 PHP 路由（如 `view_receipt.php`）驗證使用者對該 records 的存取權限後串流輸出。
- **私密目錄權限要求（SPEC-05）**：
  - `private_data/uploads/` 目錄：`0770`（擁有者為 Web 伺服器使用者，群組僅限同群組存取）。
  - 上傳檔案：`0640`（擁有者讀寫、群組讀取、其他無權限）。
- **上傳目錄 `.htaccess` 內容（SPEC-04）**：
  `private_data/uploads/.htaccess`：
  ```apache
  # Family Accounting System — block direct web access to uploaded receipts
  # Apache 2.4+
  <IfModule mod_authz_core.c>
      Require all denied
  </IfModule>
  # Apache 2.2 fallback
  <IfModule !mod_authz_core.c>
      Deny from all
  </IfModule>
  ```

## 3. 資料流與實作路徑

1. **上傳（`add_entry.php` / `edit_entry.php`）**：
   - 表單含 `receipt` 檔案輸入。
   - 後端接收 `$_FILES['receipt']`，檢查上傳成功、大小、MIME Type 與副檔名。
   - 以 `bin2hex(random_bytes(16))` 產生隨機名稱，存入 `private_data/uploads/`。
   - 寫入 `entries.receipt_path`。
2. **顯示（`ledger.php`）**：
   - 在分錄表格的「備註」或獨立欄位顯示小圖圖示。
   - 縮圖：`<img src="./view_receipt.php?id=<entry_id>&mode=inline">`，後端以 `Content-Disposition: inline` 回應。
   - 大圖預覽：縮圖連結指向同一受保護路由的 `mode=inline`，於新分頁開啟原圖；不需要先下載。未指定模式或明確指定 `mode=download` 的既有下載網址仍回應 `attachment`。
   - `view_receipt.php` 另支援條件快取（ETag/Last-Modified），減少重複下載頻寬。
3. **作廢清理（`delete_entry.php`）**：
   - 作廢記錄時，若 `receipt_path` 非空，一併 `unlink()` 刪除實體圖片。

### 3.1 縮圖顯示技術方案（SPEC-01 修正）

`view_receipt.php` 透過 `mode` 區分預覽與下載。兩種模式使用同一授權檢查，不開放上傳目錄。

| 模式 | 參數 | Content-Disposition | 用途 |
|------|------|---------------------|------|
| 預覽 | `?mode=inline` | `inline` | 明細頁 `<img>` 顯示 48×48 縮圖；點擊在新分頁檢視原圖 |
| 下載 | `?mode=download` 或無參數 | `attachment` | 保留既有下載連結相容性 |

此設計避免單一端點同時服務兩種相衝突的回應標頭，並保持私密目錄不被直接公開。

### 3.2 已知限制與效能考量（CODE-02）

> **已知限制**：明細頁縮圖直接載入原圖（最大 5MB），再由瀏覽器縮放至 48×48。大量資料時（如 50 筆 × 5MB = 250MB）頻寬較大，手機網路下載緩慢。
>
> **未來可考慮**：
> 1. 上傳時同步產生縮圖（如 200×200，儲存為 `private_data/uploads/thumb_<random>.jpg`），縮圖端點回傳較小檔案；
> 2. 或 `view_receipt.php` 支援 `?w=200&h=200` 參數即時縮放（需 GD/Imagick）。

## 4. 審計日誌動作代碼對照表（SPEC-02）

| 動作 | action 代碼 | detail 範例 |
|------|-------------|-------------|
| 建立記錄（含收據） | `entry_create` | `entry #123 · ledger 1 · expense · 123.45` |
| 編輯記錄（更換收據） | `entry_edit` | 同上 |
| 作廢記錄（清理收據） | `entry_delete` | `entry #123 · ledger 1` |
| 檢視收據大圖 | `entry_receipt_view` | `entry #123 · receipt private_data/uploads/abc.jpg` |

> 所有關鍵操作皆寫入 `audit_log` 表，僅新增不刪除（符合 Planning SEC-02）。

## 5. 驗證與測試計畫

- 上傳 JPEG/PNG/WebP 成功，上傳 PHP/HTML 檔被拒。
- 檔案大小超過 5MB 被拒。
- 未登入或無權限者無法存取 `view_receipt.php`。
- 作廢記錄時關聯圖片被清理。
- 上傳失敗時（格式不符、超過 5MB），表單重新顯示並提示錯誤，不靜默新增記錄。
- **縮圖正確顯示於明細表，點擊可預覽原圖**：跟隨實際連結，確認回應 `image/png`、`inline` 與原始圖檔內容；無模式網址仍回應 `attachment`。
- 編輯更換收據後舊檔被刪除。
- **審計日誌正確記錄 `entry_create`、`entry_edit`、`entry_delete`、`entry_receipt_view` 四種 action 代碼**。

### 本機驗證：原圖預覽與上傳上限

- 在隔離 Docker Apache／SQLite 中，`tests/apache_smoke.py` 跟隨帳本明細實際連結：修前回應下載，修後回應 `image/png` 與 `Content-Disposition: inline`，原圖位元組一致；原有無模式網址仍下載。設計與實作均通過獨立 Spec／Code／Security 靜態審查。
- 獨立實測：非圖片提示且不新增記錄，直接讀取私密圖片回應 403；替換收據維持原記錄 ID 並清除舊檔；作廢清除新檔，串流路由不再提供圖片。
- 同一個約 3 MB 的有效 PNG 在預設 `2M` 失敗，僅提高隔離 Apache 的 PHP 限額後成功；超過應用程式 5 MiB 上限的 PNG 仍被拒絕並顯示表單錯誤。正式 cPanel 設定、全部授權角色及圖片格式未在本次驗收。

### 手機收據入口

Chrome 在 390px、320px 重現收據欄被 `hide-m` 隱藏。修正僅移除帳本明細的收據表頭與儲存格上的 `hide-m`，保留既有橫向捲動、48×48 縮圖與授權。以本機 Chrome 在 1100px、390px、320px 實測表頭與儲存格均可見、對齊，觸控目標均為 54×54px。設計與實作靜態審查無阻塞；此次確認的是 Chrome 模擬視窗寬度，未宣稱實體手機驗收。

## 6. 實作註記（範圍說明）

- 收據上傳實作於 **新增記錄（`add_entry.php`）** 與 **編輯記錄（`edit_entry.php`）**，兩者共用 `functions.php::handleReceiptUpload()` 與 `view_receipt.php` 權限驗證。
- 編輯記錄時若上傳新收據，會先刪除舊收據實體檔，再儲存新圖片並更新 `entries.receipt_path`。
- `view_receipt.php` 支援條件快取：回傳 `ETag`（`md5_file + size`）與 `Last-Modified`，檢查 `If-None-Match` / `If-Modified-Since`，符合時回傳 304 Not Modified（CODE-03）。
- 部署於 Nginx 時，`.htaccess` 不生效，需於伺服器層級另行封鎖 `private_data/uploads/` 直接存取。
- **PHP 上傳上限是部署前提**：應用程式上限為 `5 × 1024 × 1024` 位元組。cPanel 需啟用 `fileinfo`，設定 `upload_max_filesize` 至少 `5M`、`post_max_size` 大於 `5M`（例如 `8M`，保留表單與 multipart 額外空間）。本機 Apache 映像預設 `2M` 時，約 3 MB 的合法 PNG 已在 PHP 層被擋；不應為此放寬應用程式的 5MB 限制或在專案 `.htaccess` 強制寫 PHP 指令。

### 6.1 Nginx 部署私密目錄防護範例（SPEC-03）

```nginx
# 方案 A：完全封鎖直接存取
location /private_data/uploads/ {
    deny all;
    return 403;
}

# 方案 B：僅允許內部 redirect（X-Accel-Redirect）存取（需搭配 PHP 端設定 X-Accel-Redirect）
# location /private_data/uploads/ {
#     internal;
# }
```

> 建議採用方案 A，簡單且安全；若需高效能串流可考慮方案 B 搭配 `X-Accel-Redirect`。