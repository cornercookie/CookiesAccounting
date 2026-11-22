# 登入提醒 — planning.md

## 文件基本資訊

- 文件名稱：登入提醒功能規劃
- 版本：v1.0
- 建立日期：2026-11-22
- 更新日期：2026-11-22
- 章節變更摘要：
  - 功能描述：初版，說明登入時預算與固定開銷提醒規則
  - 使用者故事：初版，描述登入提醒需求
  - 預期行為：初版，列出預算警告與固定開銷提醒規則
  - 業務驗收條件：初版，定義可驗證的完成條件
  - 資料流：新增，說明 dashboard 讀取至提醒顯示的完整流向
  - 錯誤處理：新增，列出資料庫查詢失敗、session 過期等行為
  - 身分驗證與權限：新增，說明 ledger_access 權限檢查
  - 非本期範圍：新增，獨立列出本期不實作項目
  - 非功能性需求：新增，說明效能與可用性設計

## 功能描述

預算即將超支或固定開銷未記帳時，於登入頁面顯示提醒（dashboard 登入時彈出），不依賴外部推播服務，降低實作複雜度。

## 專案函數與術語說明

- 輸出轉義函數（h()）：用於防護跨站腳本（XSS）。
- 跨站請求偽造（CSRF）：防護網頁請求被偽造的機制。
- 工作階段（session）：伺服端儲存使用者登入狀態的機制。
- 網頁推播（Web Push）：瀏覽器接收伺服端推送訊息的技術。
- 雲端開發平台（Firebase）：Google 所提供的雲端開發平台，包含推播通知服務。
- 警告區塊（alert）：網頁中顯示重要訊息的區塊元件。
- 登入保護機制（requireLogin()）：檢查 $_SESSION['user_id']，未登入導向登入頁。
- 帳本存取權限表（ledger_access）：記錄使用者對各帳本的角色（擁有者（owner）/成員（member）/檢視者（viewer））。
- 預寫日誌（WAL）：SQLite 資料庫日誌模式，支援多讀寫併發，選用理由為 SQLite 在多併發讀寫場景下可避免鎖定失敗，符合共享主機環境需求。

## 使用者故事

作為家庭成員，我想在登入時看到預算警告或未記帳的固定開銷提醒，及時處理。

## 預期行為

1. dashboard.php 登入後，檢查各帳本本月預算使用率（≥80% 警告、≥100% 危險），顯示橫幅。
2. dashboard.php 檢查重複記錄表（recurring_entries）未於本月產生記錄，顯示「有 X 筆固定開銷尚未記帳」提醒（已於既有 dashboard.php 部分實作，此項為擴充與統一）。
3. 提醒以 alert 區塊顯示，所有文字經 h() 轉義。
4. 使用者可將提醒標記為「已閱讀」（session 級別，不持久化至資料庫）。
5. 不依賴外部推播服務（無 Web Push、無 Firebase、無電子郵件）。
6. 權限檢查：僅顯示使用者有帳本存取權限表（ledger_access）權限的帳本提醒。

## 資料流（Data Flow）

使用者登入後進入 dashboard.php；伺服端驗證登入階段（requireLogin）；查詢各帳本本月預算使用率（SUM(amount_cents) WHERE type='expense' 與 monthly_budget_cents 比較）及重複記錄表（recurring_entries）與 entries 表關聯，判斷本月是否已產生記錄；以 alert 區塊顯示提醒，所有文字經 h() 轉義；使用者關閉提醒後記錄於 $_SESSION['notice_dismissed']，session 級別不持久化。

```mermaid
flowchart TD
    A[使用者登入進入 dashboard.php] --> B[requireLogin 驗證登入]
    B --> C[查詢各帳本本月預算使用率]
    C --> D[查詢 recurring_entries 與 entries 關聯判斷未記帳]
    D --> E[alert 區塊顯示提醒 h() 轉義]
    E --> F[使用者關閉提醒記錄 $_SESSION notice_dismissed]
```

## 業務驗收條件

- 使用者登入後，dashboard 頁面顯示預算警告橫幅（≥80% 警告、≥100% 危險）。
- 使用者登入後，dashboard 頁面顯示固定開銷未記帳提醒（檢查重複記錄表與記錄表關聯，未產生則顯示）。
- 所有提醒文字經輸出轉義函數（h()）轉義。
- 使用者可將提醒標記為「已閱讀」（session 級別，不持久化至資料庫）。
- 權限檢查：僅顯示使用者有帳本存取權限表（ledger_access）權限的帳本提醒。

## 錯誤處理與邊界條件

- 資料庫查詢失敗：顯示通用錯誤頁，詳細錯誤寫入 private_data/error.log，不影響其他 dashboard 內容。
- session 遺失或過期：requireLogin 導向登入頁，提醒不顯示。
- 權限不足：ledger_access 無對應帳本則不顯示該帳本提醒。
- 預算欄位為空（monthly_budget_cents = 0）：不顯示預算警告橫幅。

## 身分驗證與權限控管

- 身分驗證：依賴既有 session 登入機制，requireLogin() 檢查 $_SESSION['user_id']，未登入導向 login.php。
- 權限控管：使用帳本存取權限表 ledger_access，角色分為擁有者（owner）、成員（member）、檢視者（viewer），僅顯示使用者有存取權限的帳本提醒。
- 資料傳輸加密：依賴既有 HTTPS Session Cookie 設定（secure、HttpOnly、SameSite=Lax）。
- session 級別的「關閉提醒」動作：為純前端狀態，不涉及資料庫寫入，暫不適用 CSRF 權杖；若未來改為持久化則需補充 CSRF 防護。

## 非本期範圍（Out of Scope）

- 手機推播通知：本期僅於網頁顯示提醒，不支援手機推播。
- 電子郵件提醒：本期不支援電子郵件通知。
- Web Push API：本期不支援 Web Push 推播。

## 非功能性需求

- 預期效能：dashboard 載入時提醒查詢不超過 2 秒。
- 併發處理量：提醒查詢為唯讀，SQLite WAL 模式支援多併發讀取。
- 資料保留策略：提醒為 session 級別，不持久化，瀏覽器關閉後 session 結束即消失。
- 系統可用性：提醒查詢失敗不影響 dashboard 主要功能顯示。

## 待開發者決定的事項

- 提醒是否持久化至資料庫或僅保持 session 級別。
- session 級別的「關閉提醒」動作是否需要 CSRF 防護機制。
- ledger_access 權限的具體定義，以及與既有權限結構的對應關係。
