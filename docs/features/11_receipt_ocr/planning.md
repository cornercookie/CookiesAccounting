# 收據 OCR / AI 視覺解析功能規劃 — planning.md

## 文件基本資訊

- 文件名稱：收據 OCR / AI 視覺解析功能規劃（planning.md）
- 版本：v1.0
- 建立日期：2026-11-22
- 更新日期：2026-11-22
- 章節變更摘要：
  - 功能描述：初版，說明收據圖片上傳與多模態 AI 解析規則
  - 使用者故事：初版，描述長輩拍照記帳需求
  - 預期行為：初版，列出圖片驗證與 JSON 填入表單規則
  - 業務驗收條件：初版，定義可驗證的完成條件
  - 資料流：新增，說明圖片上傳至表單填入的完整流向
  - 錯誤處理：新增，列出 API 逾時、格式錯誤、速率限制等行為
  - 身分驗證與權限：新增，說明已登入使用者限制與 CSRF 綁定
  - 非本期範圍：新增，獨立列出本期不實作項目
  - 非功能性需求：新增，說明效能與可用性設計

## 專案函數與術語說明

- 光學字元辨識（OCR）：從圖片識別文字內容的技術。
- 人工智慧（AI）：模擬人類智能行為的技術系統。
- Base64 編碼（Base64）：將二進位資料轉換為 ASCII 字串的編碼方式。
- 資料交換格式（JSON）：用於匯出結構化資料。
- PHP 資料物件（PDO）：用於資料庫查詢的預備陳述式介面，選用理由為符合專案「純 PHP + PDO 預備陳述式」技術約束，防止 SQL 注入。
- 多用途網際郵件擴充類型（MIME Type）：用於標示檔案內容類型。
- 輸出轉義函數（h()）：用於防護跨站腳本（XSS）。
- 跨站請求偽造（CSRF）：防護網頁請求被偽造的機制。
- 輕量級關聯式資料庫（SQLite）：專案採用的資料庫系統，選用理由為無需伺服程式，單一檔案即可運作，適合 cPanel 共享主機環境。
- 金額轉整數分函式（toCents()）：將「元」字串解析為整數分。
- 日期格式驗證函式 isValidDate()：確認輸入為合法 Y-m-d 曆日。
- 收據圖片上傳處理函式（handleReceiptUpload）：驗證 MIME Type 與大小後儲存檔案。
- 跨站請求偽造權杖（csrf_token）：表單隱藏欄位，用於防禦跨站請求偽造攻擊，以 hash_equals() 驗證。
- AI 服務金鑰（GEMINI_API_KEY）：後端環境變數，僅於伺服端讀取，不外洩給前端。
- 速率限制資料表（api_rate_limits）：記錄單使用者 API 呼載，用於限制單使用者每分鐘呼叫次數，防止濫用。
- 分類支出明細表（entries）：儲存所有記帳分類支出記錄的資料表，包含類別、金額、日期等欄位。
- 稽查記錄表（audit_log）：記錄誰在何時做了什麼操作，僅新增不刪除。
- 登入檢查函數（requireLogin()）：檢查 $_SESSION['user_id'] 是否存在，未登入時導向 login.php。
- 多部分表單資料（multipart/form-data）：HTTP 表單提交編碼格式，用於上傳檔案。
- 新增記錄頁面（add_entry.php）：專案現有的記帳記錄新增頁面，收據 OCR 功能整合於此頁面，表單以 multipart/form-data 以 POST 提交至 receipt_ocr.php。
- AI 解析頁面（ai_parse.php）：專案現有的 AI 一句話記帳後端代理頁面，負責文字解析，與收據 OCR 功能相互獨立。
- 私密資料庫目錄（private_data/）：專案資料庫與錯誤日誌儲存位置，由 .htaccess 封鎖直接瀏覽器存取，相關資料庫檔案（entries、api_rate_limits、audit_log）置於此目錄。
- 相對路徑（./）：專案所有內部路徑以 ./ 開頭，確保可部署於任意子目錄。
- 系統介面語言（zh-TW）：專案介面語言為台灣繁體中文。
- 多模態 AI（Multimodal AI）：能同時處理圖片與文字的 AI 模型，用於收據圖片解析為記帳欄位。

## 功能描述

上傳收據照片後，後端代理呼叫多模態 AI API 解析為記帳欄位（金額、類別、日期、類型），前端填入表單，使用者確認後儲存。不直接寫入資料庫，保留人工核對步驟。

## 使用者故事

作為不擅長使用科技工具的使用者，我想拍張收據照片，AI 自動填好金額與分類，我只需確認後儲存。

## 預期行為

1. add_entry.php 收據上傳區塊加入「AI 解析收據」按鈕。
2. 圖片上傳後，PHP 後端以 base64 編碼，呼叫後端多模態 AI API 解析。
3. API 金鑰僅於後端讀取（GEMINI_API_KEY），不外洩給前端。
4. AI 回傳結構化 JSON（金額、類別、日期、類型、描述），經嚴格驗證（金額轉整數分函式 toCents、日期格式驗證函式 isValidDate、類別長度≤50）。
5. 前端將解析結果填入 add_entry.php 表單欄位，使用者可修改後儲存。
6. 單使用者速率限制（參照專案現有 AI 一句話記帳後端代理頁面 ai_parse.php 的速率限制邏輯，最近 1 分鐘內最多 20 次呼叫）。
7. 所有輸出經 h() 轉義。
8. SQL 查詢使用 PDO 預備陳述式，具名佔位符（僅於最終儲存記錄時）。
9. 跨站請求偽造（CSRF）權杖防護，AI 解析請求含 csrf_token，以 hash_equals() 驗證。

## 資料流（Data Flow）

使用者於 add_entry.php 上傳收據圖片，以 multipart/form-data 提交；PHP 後端驗證圖片 MIME Type（JPEG/PNG/WebP，≤5MB，與收據上傳處理函式 handleReceiptUpload 一致），驗證失敗則顯示錯誤訊息不呼叫 AI API；以 base64 編碼呼叫多模態 AI API（API 金鑰僅後端讀取，不外洩），AI 逾時或回傳 JSON 格式錯誤時回退手動填寫模式；AI 回傳結構化 JSON，經嚴格欄位驗證，驗證失敗則回退手動填寫模式；前端將解析結果填入 add_entry.php 表單欄位；使用者確認後以 PDO 預備陳述式 INSERT 入 entries 表；audit_log 記錄建立記帳紀錄並經 h() 轉義；所有輸出經 h() 轉義；單使用者速率限制寫入 api_rate_limits 表，超過則回應 HTTP 429。

```mermaid
flowchart TD
    A[使用者上傳收據圖片] --> B[驗證 MIME Type 與大小 ≤5MB]
    B -->|驗證失敗| B1[顯示錯誤訊息，不呼叫 AI API]
    B -->|驗證通過| C[base64 編碼呼叫多模態 AI API]
    C -->|AI 逾時或 JSON 格式錯誤| C1[回退手動填寫模式]
    C -->|成功| D[AI 回傳結構化 JSON]
    D --> E[嚴格欄位驗證 toCents/isValidDate]
    E -->|驗證失敗| E1[回退手動填寫模式]
    E -->|驗證通過| F[前端填入 add_entry.php 表單]
    F --> G[使用者確認後 PDO INSERT 入 entries]
    G --> H[audit_log 記錄建立記帳紀錄 h() 轉義]
    H --> I[寫入 api_rate_limits 表]
    I -->|速率限制觸及| I1[回應 HTTP 429]
```

## 業務驗收條件

- 收據 OCR 處理端點可供已登入使用者存取（端點檔名為 receipt_ocr.php）。
- 後端驗證圖片 MIME Type（JPEG/PNG/WebP，≤5MB，與收據上傳處理函式 handleReceiptUpload 一致）。
- 後端多模態 AI API 呼叫，API 金鑰不外洩。
- AI 回傳 JSON 經嚴格欄位驗證，失敗時回退手動填寫。
- 速率限制：單使用者最近 1 分鐘內最多 20 次呼叫，超過則回應 HTTP 429。
- 跨站請求偽造（CSRF）權杖以 hash_equals() 驗證，驗證失敗拒絕請求。
- 金額以金額轉整數分函式（toCents()）轉為整數分後儲存於資料庫。
- AI 結果須經使用者確認後才新增記帳記錄，保留人工核對步驟。
- 儲存記錄時 SQL 須使用 PDO 預備陳述式具名佔位符。
- audit_log 須寫入並經 h() 轉義。
- 資料庫與錯誤日誌須置於 private_data/，內部路徑採 ./ 相對路徑。
- 所有輸出經 h() 轉義。

## 錯誤處理與邊界條件

- AI API 呼叫逾時：顯示「AI 服務暫時無法使用，請手動填寫」，回退手動填寫模式。
- AI 回傳 JSON 格式錯誤：顯示「AI 回傳格式錯誤，請手動填寫」，回退手動填寫模式。
- 圖片上傳失敗或 MIME 不符：顯示「僅允許 JPEG / PNG / WebP，且檔案大小不得超過 5MB」。
- 速率限制觸及：HTTP 回應 429，顯示「呼叫過於頻繁，請稍後再試」。
- AI 服務不可用：顯示通用錯誤訊息，回退手動填寫模式。
- 圖片驗證失敗：不呼叫 AI API，直接顯示錯誤訊息。

## 應用程式介面契約（receipt_ocr.php）

- 呼叫方：前端 add_entry.php，以 multipart/form-data 提交圖片與 csrf_token。
- 接收方：後端 receipt_ocr.php，以 POST 處理。
- 請求欄位：receipt（圖片檔案，MIME 為 JPEG/PNG/WebP，≤5MB）、csrf_token（字串，跨站請求偽造權杖）。
- 回應 JSON 欄位：amount（整數分）、category（中文類別名稱）、date（Y-m-d 格式日期）、type（收支類型，值為 income/expense；income 為收入、expense 為支出）、description（備註字串）。
- HTTP 狀態碼：200（解析成功）、400（圖片驗證失敗或參數錯誤）、429（速率限制觸及）、500（AI API 呼叫失敗或伺服器錯誤）。
- 錯誤行為：AI API 呼叫逾時或回傳 JSON 格式錯誤時，回應 500 並顯示「AI 服務暫時無法使用，請手動填寫」。

## 身分驗證與權限控管

- 身分驗證：依賴既有 session 登入機制，requireLogin() 檢查 $_SESSION['user_id']，未登入導向 login.php。
- 權限控管：AI 解析僅限已登入使用者，與既有 session 與 CSRF 權杖綁定。
- 資料傳輸加密：依賴既有超文字傳輸協定安全版（HTTPS）Session Cookie 設定（secure、HttpOnly、SameSite=Lax）。
- API 金鑰安全：GEMINI_API_KEY 僅於後端讀取，不外洩給前端。

## 非本期範圍（Out of Scope）

- 直接將 AI 結果寫入資料庫（無人工確認）：本期保留人工核對步驟，不自動寫入。
- 本地 OCR（Tesseract）：本期採用外部多模態 AI API，不引入本地 OCR。
- 多語系收據支援：本期僅支援中文收據解析，多語系待評估。

## 非功能性需求

- 預期效能：AI 解析回應時間取決於外部 API，本地處理不超過 2 秒。
- 併發處理量：單使用者速率限制每分鐘 20 次，避免濫用耗盡 API 配額。
- 資料保留策略：AI 解析不產生額外資料，僅寫入 api_rate_limits 表（最近 24 小時清理舊紀錄）。
- 系統可用性：AI 服務不可用時回退手動填寫，不影響記帳功能。

## 待開發者決定的事項

- 多模態 AI API 供應商選定及其在 cPanel 主機可否存取。
- API 成本由誰負擔。
- 速率限制表 api_rate_limits 是否已存在，或需新增資料庫 migration。
- receipt_ocr.php 與 ai_parse.php 是否合併為同一端點，或分開維護。
