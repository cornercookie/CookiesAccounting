# Design & Implementation: 拍照收據 / 發票小圖夾

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 名稱 | 拍照收據 / 發票小圖夾設計 |
| 版本 | v1.0 |
| 建立日期 | 2026-11-21 |
| 更新日期 | 2026-11-21 |
| 變更摘要 | v1.0 初稿送審。依獨立審查 SEC-02（私密上傳隨機命名、WebShell 防護、MIME 檢查）與Planning 規範撰寫。 |

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

## 3. 資料流與實作路徑

1. **上傳（`add_entry.php` / `edit_entry.php`）**：
   - 表單含 `receipt` 檔案輸入。
   - 後端接收 `$_FILES['receipt']`，檢查上傳成功、大小、MIME Type 與副檔名。
   - 以 `bin2hex(random_bytes(16))` 產生隨機名稱，存入 `private_data/uploads/`。
   - 寫入 `entries.receipt_path`。
2. **顯示（`ledger.php`）**：
   - 在分錄表格的「備註」或獨立欄位顯示小圖圖示。
   - 點擊時經由 `view_receipt.php?id=<entry_id>` 取得大圖（後端驗證 `ledger_access` 權限後串流輸出）。
3. **作廢清理（`delete_entry.php`）**：
   - 作廢記錄時，若 `receipt_path` 非空，一併 `unlink()` 刪除實體圖片。

## 4. 驗證與測試計畫

- 上傳 JPEG/PNG/WebP 成功，上傳 PHP/HTML 檔被拒。
- 檔案大小超過 5MB 被拒。
- 未登入或無權限者無法存取 `view_receipt.php`。
- 作廢記錄時關聯圖片被清理。
- 上傳失敗時（格式不符、超過 5MB），表單重新顯示並提示錯誤，不靜默新增記錄。

## 5. 實作註記（範圍說明）

- 收據上傳實作於 **新增記錄（`add_entry.php`）** 與 **編輯記錄（`edit_entry.php`）**，兩者共用 `handleReceiptUpload()` 與 `view_receipt.php` 權限驗證。
- 編輯記錄時若上傳新收據，會先刪除舊收據實體檔，再儲存新圖片並更新 `entries.receipt_path`。
- 部署於 Nginx 時，`.htaccess` 不生效，需於伺服器層級另行封鎖 `private_data/uploads/` 直接存取。
