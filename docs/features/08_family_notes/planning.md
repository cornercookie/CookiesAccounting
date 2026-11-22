# 家庭訊息留言板 — planning.md

## 文件基本資訊

- 文件名稱：家庭訊息留言板功能規劃
- 版本：v1.0
- 建立日期：2026-11-22
- 更新日期：2026-11-22
- 章節變更摘要：
  - 功能描述：初版，說明留言表與展開區塊規則
  - 使用者故事：初版，描述記錄留言需求
  - 預期行為：初版，列出留言新增與顯示規則
  - 業務驗收條件：初版，定義可驗證的完成條件
  - 資料流：新增，說明留言輸入至 SQLite 儲存與顯示的完整流向
  - 錯誤處理：新增，列出 CSRF 失敗、長度超限、權限不足等行為
  - 身分驗證與權限：新增，說明 ledger_access 權限檢查
  - 非本期範圍：新增，獨立列出本期不實作項目
  - 非功能性需求：新增，說明效能與可用性設計
  - 待開發者決定的事項：新增，列出本期尚需開發者確認的設計決策

## 功能描述

在分錄記錄旁加入簡短留言欄，家人可為特定記帳記錄補充說明（例如「這筆是買給媽媽的禮物」），資料留在自架主機，不需透過外部通訊軟體。

## 專案函數與術語說明

- 輸出轉義函數（h()）：用於防護跨站腳本（XSS）。
- 跨站請求偽造（CSRF）：防護網頁請求被偽造的機制。
- 超文字傳輸協定（HTTP）：網頁請求與回應傳輸協定。
- 預寫日誌（WAL）：SQLite 資料庫日誌模式，支援多讀寫併發，選用理由為 SQLite 在多併發讀寫場景下可避免鎖定失敗，符合共享主機環境需求。
- PHP 資料物件（PDO）：用於資料庫查詢的預備陳述式介面，選用理由為符合專案「純 PHP + PDO 預備陳述式」技術約束，防止 SQL 注入。
- 登入保護機制（requireLogin()）：檢查 $_SESSION['user_id']，未登入導向登入頁。
- 帳本存取權限表（ledger_access）：記錄使用者對各帳本的角色（擁有者（owner）/成員（member）/檢視者（viewer））。
- 留言資料表（entry_comments）：儲存分錄記錄留言的資料表，欄位包括留言編號（id）、關聯記錄編號（entry_id）、留言者使用者編號（user_id）、留言內容（comment）、建立時間（created_at）。
- 多位元組字串長度函數（mb_strlen）：PHP 內建函數，用於計算多位元組字串的字元長度。
- 稽核記錄表（audit_log）：記錄系統重要動作的資料表，本功能記錄留言新增動作，action 欄位為 entry_comment，detail 欄位不包含留言內容（避免隱私外洩）。

## 使用者故事

作為家庭成員，我想在記帳記錄旁加入留言說明，讓其他家人知道這筆錢的用途，避免月底對帳時詢問。

## 預期行為

1. 新增留言資料表（entry_comments），欄位包括：留言編號（id）、關聯記錄編號（entry_id）、留言者使用者編號（user_id）、留言內容（comment）、建立時間（created_at）。
2. ledger.php 分錄列表每行加入「留言」展開區塊，顯示該記錄的所有留言。
3. 使用者可新增留言（POST 表單，含跨站請求偽造（CSRF）權杖，comment 欄位經輸出轉義函數（h()）轉義輸出）。
4. 留言僅文字，不上傳檔案，長度上限 200 字元（多位元組字串長度函數（mb_strlen）驗證）。
5. 稽查記錄表（audit_log）記錄留言新增動作，action 為 entry_comment，detail 不包含留言內容（避免隱私）。
6. 留言列表依 created_at 降冪排列，顯示留言者與時間。
7. 所有輸出經 h() 轉義，防護跨站腳本（XSS）。
8. SQL 查詢使用 PDO 預備陳述式，具名佔位符。
9. 權限檢查：使用者對該帳本有 ledger_access（擁有者/member/檢視者皆可留言與檢視）。

## 資料流（Data Flow）

使用者於分錄列表的留言展開區塊輸入留言文字，以 POST 提交含 CSRF 權杖的表單；伺服端驗證登入階段（requireLogin）與帳本存取權限（ledger_access）；驗證 comment 長度不超過 200 字元（mb_strlen）；以 PDO 預備陳述式 INSERT 入 entry_comments 表；寫入 audit_log 記錄動作（detail 不含留言內容）；ledger.php 查詢 entry_comments 依 entry_id 分組，依 created_at 降冪排列顯示；所有輸出經 h() 轉義。

```mermaid
flowchart TD
    A[使用者輸入留言文字] --> B[POST 表單含 CSRF 權杖]
    B --> C[requireLogin 驗證登入]
    C --> D[ledger_access 權限檢查]
    D --> E[驗證 comment 長度 ≤200 字元]
    E --> F[PDO INSERT 入 entry_comments]
    F --> G[audit_log 記錄 entry_comment detail 不含內容]
    G --> H[ledger.php 查詢 entry_comments 依 created_at 降冪 h() 轉義顯示]
```

## 業務驗收條件

- 在 `docs/features/08_family_notes/entry_comment.php` 建立留言新增處理端點，ledger.php 僅負責顯示與查詢留言。
- db.php 確保資料表結構函式 ensureSchema() 新增 entry_comments 資料表與索引（冪等性守衛）。
- ledger.php 分錄列表每行加入留言展開區塊（預設隱藏，點擊顯示）。
- 留言表單含 CSRF 權杖，POST 時以 hash_equals() 驗證。
- comment 欄位長度上限 200 字元，mb_strlen 驗證。
- 留言新增後寫入 audit_log（action=entry_comment，detail 不含留言內容）。
- 所有輸出經 h() 轉義。

## 錯誤處理與邊界條件

- CSRF 權杖驗證失敗：拒絕請求，顯示通用錯誤訊息。
- comment 超過 200 字元：顯示錯誤訊息，不寫入資料庫，HTTP 回應 400。
- 空白留言：顯示錯誤訊息，不寫入資料庫。
- 使用者對該帳本無 ledger_access 權限：拒絕留言新增，顯示「您沒有該帳本的存取權限」，HTTP 回應 403。
- SQLite 寫入失敗：顯示通用錯誤頁，詳細錯誤寫入 private_data/error.log。
- audit_log 寫入失敗：不中斷主流程，改寫 error.log，繼續執行。

## 身分驗證與權限控管

- 身分驗證：複用專案既有 session 登入機制，requireLogin() 檢查 $_SESSION['user_id']，未登入導向 login.php。
- 權限控管：使用帳本存取權限表 ledger_access，角色分為擁有者（owner）、成員（member）、檢視者（viewer），三者皆可留言與檢視留言。
- 資料傳輸加密：複用專案既有超文字傳輸協定安全版（HTTPS）Session Cookie 設定（secure、HttpOnly、SameSite=Lax）。

## 非本期範圍（Out of Scope）

- 留言編輯/刪除：本期僅支援新增留言，不支援編輯或刪除既有留言。
- 留言通知：本期不發送新留言通知給其他成員。
- 留言@提醒：本期不支援 @ 特定成員的提醒功能。

## 非功能性需求

- 預期效能：留言查詢與既有分錄列表合併執行，頁面載入時間增加不超過 200 毫秒。
- 併發處理量：留言新增為單筆 INSERT，SQLite 預寫日誌（WAL）模式支援多讀寫併發，選用理由為 SQLite 在多併發讀寫場景下可避免鎖定失敗，符合共享主機環境需求。
- 資料保留策略：entry_comments 記錄長期保留，與 entries 記錄生命週期一致。
- 系統可用性：留言功能失敗不影響主帳本檢視功能。

## 待開發者決定的事項

- 身分驗證機制採專案既有 session 驗證（requireLogin() / $_SESSION['user_id']），還是本功能新增機制？
- 錯誤處理時的 HTTP 狀態碼與前端提示方式（回導向頁面、JSON 或訊息提示），需確認專案慣例。
