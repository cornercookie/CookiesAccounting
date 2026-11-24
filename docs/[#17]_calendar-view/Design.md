# Design & Implementation: 行事曆檢視（calendar.php）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 名稱 | 行事曆檢視設計（calendar.php） |
| 版本 | v1.8 |
| 建立日期 | 2026-11-24 |
| 更新日期 | 2026-11-24 |
| 變更摘要 | v1.8 回應 Code 審查 v1.6 與 v1.8 發現問題：補充 §3.2 第 10 點 `ledger.php` `entry_date` 介面契約；§3.1 Mermaid 流程圖月份導航節點明確標示 `ledger_id` 保留；§10 預算段落重新措詞消除矛盾；§2.1 `users` 表說明釐清；§13 實作紀錄標示已實作。calendar.php 第 269 行連結 `&` 改用 `http_build_query` + `h()`；新增 `$ledgerHint` 顯示；第 41 行 `$monthStart` 改用 `DateTimeImmutable` 建構；ledger.php `filter_var` 前加入 null/空判斷；Design §3.2 #5「0 元」改為「空白或 0 元」；§3.2 #12 與 §4 錯誤處理措詞調整為「通用錯誤訊息」。實作階段三階段審查（Spec / Code / Security）均已通過，驗收完成。 |

---

## 1. 變更概述

建立行事曆檢視頁面 `calendar.php`，以月曆格顯示每日收支總額，使用者可點入日期查看該日記錄。支援月份導航（上月/下月），以 GET 參數 `ym`（格式 `Y-m`）傳遞月份。純 PHP 伺服端渲染（SSR），無前端行事曆庫，不依賴 JavaScript 行事曆元件。僅顯示使用者於帳本存取權限表（ledger_access）有存取權限的帳本記錄，可跨帳本加總或單帳本檢視。

---

## 2. 資料庫與架構變更

### 2.1 資料庫 Schema 變更

**無資料庫 Schema 變更**。本功能複用既有資料表，不新增或修改任何資料表、欄位或索引。

| 複用資料表 | 用途 |
| --- | --- |
| `entries` | 查詢記帳分錄，依 `entry_date` 分組加總每日收支 |
| `ledger_access` | 權限檢查，INNER JOIN 驗證使用者對帳本的存取權限 |
| `users` | 未查詢（使用者身分僅從 `$_SESSION['user_id']` 綁定；本頁面每日加總不需 per-entry 使用者顯示，故不 JOIN `users` 表） |
| `ledgers` | 取得帳本名稱與單帳本檢視範圍 |

### 2.2 新增檔案

| 檔案 | 說明 |
| --- | --- |
| `calendar.php` | 行事曆檢視頁面入口點，伺服端渲染月曆格線與每日加總 |

### 2.3 複用既有共用函數

| 函數 | 用途 | 定義位置 |
| --- | --- | --- |
| `requireLogin()` | 登入檢查，未登入導向 `login.php` | `functions.php` |
| `currentUser()` | 取得目前登入使用者陣列 | `functions.php` |
| `getPdo()` | 取得 PDO 連線（WAL、busy_timeout、具名佔位符） | `db.php` |
| `h()` | HTML 輸出轉義（XSS 防護） | `functions.php` |
| `formatCents()` | 整數分格式化為金額字串（顯示除以 100、千分位） | `functions.php` |
| `csrfToken()` | 產生 CSRF 欄位值（本頁僅讀，不適用 CSRF，僅供未來擴充參考） | `functions.php` |
| `logAudit()` | 寫入 `audit_log` 審計日誌 | `functions.php` |
| `isValidDate()` | 日期格式驗證（Y-m-d） | `functions.php` |
| `redirect()` | 相對路徑導向（`./` 開頭） | `functions.php` |

---

## 3. 資料流與實作路徑

### 3.1 資料流圖（Mermaid）

```mermaid
flowchart TD
    A[使用者存取 ./calendar.php] --> B[requireLogin 驗證登入]
    B -->|未登入| C[導向 ./login.php]
    B -->|已登入| D{GET 參數 ym 存在？}
    D -->|否| E[預設當月 Asia/Taipei]
    D -->|是| F{ym 格式正確？正則 ^\d{4}-(0[1-9]|1[0-2])$}
    F -->|否| G[回退至當月，顯示提示訊息]
    E --> H[查詢 ledger_access 取得使用者可存取帳本 IDs]
    G --> H
    F -->|是| H[查詢 ledger_access 取得使用者可存取帳本 IDs]
    H --> P{單帳本檢視？}
    P -->|是| Q[前置檢查 ledger_access 權限：ledger_id 與 user_id 是否對應？]
    Q -->|無記錄| H[回退跨帳本檢視，忽略 ledger_id]
    Q -->|有記錄| I[PDO 預備陳述式查詢 entries，依 entry_date 分組加總]
    P -->|否| I
    I --> J{資料庫查詢成功？}
    J -->|否| K[顯示通用錯誤頁，詳細錯誤寫入 error.log]
    J -->|是| L[PHP DateTime 產生行事曆格線與日期]
    L --> M[每日格顯示收入/支出/結餘，h() 轉義輸出]
    M --> N[月份導航按鈕：上月/下月，產生連結 ./calendar.php]
    N --> N1[連結參數：ym 必帶；單帳本檢視時保留 ledger_id（./calendar.php?ym=...&ledger_id=...），跨帳本檢視僅傳 ym]
    N1 --> O[點入日期導向 ./ledger.php 篩選該日記錄（僅單帳本檢視時可點擊；跨帳本檢視日期格不可點擊）]
```

### 3.2 查詢路徑（擴大說明）

1. **身分驗證（優先）**：呼叫 `requireLogin()` 檢查 `$_SESSION['user_id']`，未登入導向 `login.php`。此為 `calendar.php` 入口點第一道防線，於任何 `$_GET` 參數處理之前執行。
2. **月份參數解析**：讀取 `$_GET['ym']`，以正則 `/^\d{4}-(0[1-9]|1[0-2])$/` 驗證格式；驗證通過後，額外檢查年份數值 `$year = (int) substr($ym, 0, 4)` 必須滿足 `1 <= $year <= 9999`（公曆不存在年份 0，PHP `DateTime` 處理年份 0000 跨版本行為不一致）；年份不符則回退至當月（`Asia/Taipei` 時區），並顯示提示訊息。
3. **單帳本檢視參數（可選）**：讀取 `$_GET['ledger_id']`（可選）；若未提供或為空字串，則採用跨帳本檢視；否則以 `filter_var($ledgerIdRaw, FILTER_VALIDATE_INT)` 驗證為整數，並檢查 `$ledgerId > 0`；驗證失敗（非整數或非正整數）時回退至跨帳本檢視。避免傳入 `null` 給 `filter_var`，防止 PHP 8.1 棄用警告。
4. **權限範圍**：以 `INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id` 取得使用者可存取的帳本 ID 集合，避免 IDOR 權限繞過。單帳本檢視時，PHP 應先以 `SELECT 1 FROM ledger_access WHERE ledger_id = :ledger_id AND user_id = :user_id LIMIT 1` 確認使用者對該帳本有存取記錄；若無記錄，則靜默回退至跨帳本檢視（忽略 `ledger_id` 參數，顯示所有可存取帳本記錄），不區分「無此帳本」與「無此帳本權限」，避免透過回應內容差異進行帳本存取列舉（側信道防護）。SQL 查詢時亦額外加入 `AND e.ledger_id = :ledger_id`，同時保留 `INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id`，雙重防護。
5. **無可存取帳本的邊界條件**：若登入使用者沒有任何 `ledger_access` 記錄（即未被授權任何帳本），`INNER JOIN` 會導致查詢結果為空集。此情況下行事曆顯示空白格線，無資料日期顯示空白或 `0 元`，非錯誤狀態，不導向錯誤頁。
6. **封存帳本處理**：`INNER JOIN ledgers l ON l.id = e.ledger_id AND l.archived = 0`，封存帳本（`archived=1`）不納入行事曆顯示，與 `business-logic.md` §6 預算邏輯及封存規則一致。
7. **每日加總查詢**：以 PDO 預備陳述式（具名佔位符）查詢 `entries` 表，條件為 `deleted = 0`、`entry_date` 在目標月份首日與末日之間，依 `entry_date` 分組，分別加總 `type = 'income'` 與 `type = 'expense'` 的 `amount_cents`。`transfer` 不計入收支結餘（僅單獨統計，見業務邏輯 §3）。`:monthStart` 與 `:monthEnd` 僅由伺服端 PHP `DateTime` 根據已驗證之 `ym` 建構（`Y-m-01` 與 `Y-m-t`），絕不直接來自 `$_GET`，且均以 PDO 具名佔位符綁定。
8. **轉帳顯示**：轉帳（`transfer`）於每日格中以墨黑 `#23272b` 顯示，不加 `+`/`-` 前綴，不參與每日結餘（收入 − 支出）計算，符合 `business-logic.md` §3 轉帳不計入結餘規則。
9. **行事曆格線**：以 PHP `DateTime`/`DateTimeImmutable` 產生目標月份的日期格線（含上月尾、本月、下月首），不依賴 JavaScript 行事曆庫。
10. **點擊日期導航**：跨帳本檢視（未提供通過權限驗證的 `ledger_id`）時，日期格不可點擊，僅顯示純文字金額；單帳本檢視（提供通過 `ledger_access` 權限驗證的 `ledger_id`）時，日期格可點擊導向 `./ledger.php?ledger_id=X&ym=Y&entry_date=Z` 篩選該日記錄，其中 `entry_date` 為該日日期（Y-m-d）。`ledger.php` 為需修改檔案（詳見 §13 實作紀錄）。

   **ledger.php `entry_date` 介面契約**：
   - 參數：`entry_date`（GET，可選），格式 `Y-m-d`。
   - 驗證：必須通過 `isValidDate($entry_date)` 驗證；驗證失敗時忽略該參數，不以 invalid 日期篩選。
   - 查詢過濾：以 PDO 預備陳述式綁定 `:entry_date`，SQL 條件為 `e.entry_date = :entry_date`，具名佔位符，`ATTR_EMULATE_PREPARES=false`。
   - 權限：`entry_date` 篩選僅在單帳本檢視（已通過 `ledger_access` 權限驗證的 `ledger_id`）時生效；跨帳本檢視不接受 `entry_date` 篩選參數。
   - 輸出轉義：連結中的 `ledger_id`、`ym`、`entry_date` 均先 `urlencode()` 再經 `h()` HTML 轉義後輸出至 `<a href>` 屬性。
   - 金額：所有金額以整數分（`amount_cents`）儲存與運算，顯示時 `formatCents()` 除以 100，**禁用 FLOAT**。
11. **輸出轉義**：所有使用者可控輸出（月份標籤、日期、金額、帳本名稱、提示訊息）皆經 `h()` 轉義。月份導航按鈕將 `ym` 與 `ledger_id` 輸出至 `<a href>` URL 屬性時，應先 `urlencode()` 再經 `h()` HTML 轉義，作為防禦性編碼基線。單帳本檢視時，上月/下月導航連結應保留 `ledger_id` 參數，每次請求皆重新執行 `ledger_access` 權限驗證，不依賴前端參數。
12. **錯誤處理**：資料庫查詢失敗時顯示通用錯誤訊息（不外洩細節），詳細錯誤（SQL、堆疊）寫入 `private_data/error.log`，不外洩給瀏覽器。

---

## 4. 安全控管

| 控管點 | 實作方式 |
| --- | --- |
| 身分驗證 | `calendar.php` 開頭呼叫 `requireLogin()`，檢查 `$_SESSION['user_id']`，未登入導向 `login.php` |
| 權限控管（IDOR 防護） | 所有 `entries` 查詢 `INNER JOIN ledger_access` 驗證 `la.user_id = :user_id`，僅顯示使用者有存取權限的帳本記錄；單帳本檢視時額外加入 `AND e.ledger_id = :ledger_id`，同時保留 `INNER JOIN ledger_access`，雙重防護 IDOR 權限繞過 |
| 輸出轉義（XSS 防護） | 所有網頁輸出經 `h()`（`htmlspecialchars` + `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8）轉義 |
| 金額精確 | 儲存與運算皆以整數分（`amount_cents`），顯示時 `formatCents()` 除以 100 並千分位，**禁用 FLOAT** |
| SQL Injection 防護 | 所有查詢使用 PDO 預備陳述式，具名佔位符，`ATTR_EMULATE_PREPARES=false` |
| CSRF 防護 | 行事曆頁面為僅讀 GET 導航，不涉及狀態變更操作，依專案安全約定不適用 CSRF 權杖；若未來加入狀態變更表單則需補充 CSRF 防護 |
| 資料傳輸加密 | 依賴既有 HTTPS Session Cookie 設定（`Secure`、`HttpOnly`、`SameSite=Lax`） |
| 錯誤不外洩 | `display_errors=0`，堆疊與 SQL 細節寫入 `private_data/error.log`，瀏覽器僅見通用錯誤訊息（不外洩細節） |
| 審計日誌 | 月份導航為唯讀查詢，非狀態變更操作，不寫入 `audit_log`。風險接受決策：行事曆檢視不新增、編輯、作廢任何記錄，僅讀取既有資料，無資料異動風險，故列為「非關鍵操作」不寫入審計日誌；若業務或稽核要求記錄使用者檢視行事曆的行為，未來可於月份導航或單帳本檢視時補充 `logAudit('calendar_view', ...)`。 |

---

## 5. 介面契約與參數

### 5.1 HTTP 介面

| 項目 | 內容 |
| --- | --- |
| 頁面路徑 | `./calendar.php` |
| 請求方法 | GET（僅讀） |
| 查詢參數 | `ym`（格式 `Y-m`，可選，預設當月）；`ledger_id`（可選，正整數，單帳本檢視） |
| 權限需求 | 已登入（`requireLogin()`），無需特定帳本角色（跨帳本加總顯示使用者所有可存取帳本）；單帳本檢視需驗證使用者於該帳本的 `ledger_access` 存取權限 |
| 響應格式 | HTML（伺服端渲染，UTF-8） |
| CSRF Token | 不適用（僅讀 GET，無狀態變更表單） |

### 5.2 參數驗證規則

| 參數 | 驗證規則 | 驗證失敗行為 |
| --- | --- | --- |
| `ym` | 必須符合 `/^\d{4}-(0[1-9]|1[0-2])$/` | 回退至當月，顯示提示訊息 |
| `ledger_id`（可選） | 必須為正整數，且使用者於 `ledger_access` 有該帳本的存取記錄 | 回退至跨帳本檢視（顯示使用者所有可存取帳本記錄） |

---

## 6. 每日加總 SQL（預覽）

```sql
-- 跨帳本檢視（預設）：顯示使用者所有可存取帳本記錄
SELECT
    e.entry_date,
    SUM(CASE WHEN e.type = 'income'  THEN e.amount_cents ELSE 0 END) AS income_cents,
    SUM(CASE WHEN e.type = 'expense' THEN e.amount_cents ELSE 0 END) AS expense_cents,
    SUM(CASE WHEN e.type = 'transfer' THEN e.amount_cents ELSE 0 END) AS transfer_cents
FROM entries e
INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
INNER JOIN ledgers l ON l.id = e.ledger_id AND l.archived = 0
WHERE e.deleted = 0
  AND e.entry_date BETWEEN :monthStart AND :monthEnd
GROUP BY e.entry_date
ORDER BY e.entry_date
```

- `:user_id`：目前登入使用者 ID（來自 `$_SESSION['user_id']`）
- `:monthStart`：目標月份首日（`Y-m-01`），僅由伺服端 PHP `DateTime` 建構，絕不來自 `$_GET`
- `:monthEnd`：目標月份末日（`Y-m-t`），僅由伺服端 PHP `DateTime` 建構，絕不來自 `$_GET`
- 所有欄位為整數分，顯示時 `formatCents()` 除以 100
- `INNER JOIN ledgers l ON l.archived = 0`：排除封存帳本（`archived=1`），與 `business-logic.md` §6 一致

**對 `planning.md` 業務驗收條件的對應說明**：
`planning.md` 業務驗收條件第6點規定「每日收支加總查詢 `entry_date = 當日日期`，`deleted = 0`」。本頁面為整月行事曆檢視，因此以 `entry_date BETWEEN :monthStart AND :monthEnd` 取得目標月份內所有日期，再依 `entry_date` 分組加總（`GROUP BY e.entry_date`），即可得到每一個日曆日期的收入總額與支出總額。此即等價於針對「每個日曆日期」分別執行 `entry_date = 當日日期` 的加總查詢，滿足 `planning.md` 驗收要求。

**單帳本檢視模式**：當提供 `ledger_id` 參數並通過 `ledger_access` 權限驗證時，額外加入條件：
```sql
-- 單帳本檢視時額外加入：
--   AND e.ledger_id = :ledger_id
-- 並綁定 :ledger_id，同時保留 INNER JOIN ledger_access la ... AND la.user_id = :user_id
```

---

## 7. 驗證與測試計畫

| 測試項目 | 預期結果 |
| --- | --- |
| 未登入存取 `calendar.php` | 導向 `login.php` |
| 無 `ym` 參數 | 顯示當月（Asia/Taipei 時區）行事曆 |
| `ym` 格式錯誤（如 `2026-13`、`abc`） | 回退至當月，顯示提示訊息 |
| 月份導航（上月/下月） | `ym` 參數正確遞增/遞減，行事曆重新渲染 |
| 每日收支加總 | 每日格顯示該日收入總額（紅色 `+`）、支出總額（藍色 `-`）、結餘 |
| 權限隔離 | 使用者僅看見其 `ledger_access` 有存取權限之帳本記錄 |
| 無可存取帳本（無 `ledger_access` 記錄） | 行事曆顯示空白格線（所有日期 `0 元`），非錯誤狀態 |
| 單帳本檢視（`ledger_id` 參數） | 僅顯示該帳本記錄，且使用者須有該帳本 `ledger_access` 存取權限 |
| 單帳本檢視（無權限的 `ledger_id`） | 回退至跨帳本檢視（顯示使用者所有可存取帳本記錄） |
| 轉帳顯示 | 轉帳於每日格以墨黑顯示，不加 `+`/`-` 前綴，不參與每日結餘計算 |
| 無資料日期 | 顯示空格或 `0 元`，非錯誤狀態 |
| 金額格式化 | 顯示千分位、小數點後兩位（例：`1,234.50`） |
| 響應式表格 | `≤720px` 可橫向捲動，觸控目標 ≥ 44px |
| 資料庫查詢失敗 | 顯示通用錯誤頁，詳細錯誤寫入 `error.log` |
| 所有輸出轉義 | 使用者可控內容經 `h()` 轉義，無 XSS 風險 |
| 月份導航 URL 輸出 | `ym` 輸出至 `<a href>` 屬性時先 `urlencode()` 再 `h()`，防護性編碼 |

---

## 8. 響應式與版面規範（設計系統）

依 `Agents/design-system.md` 執行：

| 元件 | 規範 |
| --- | --- |
| 版面 | 雙欄殼架構（`.side` 側欄 232px 桌機固定，`≤720px` 變 sticky 頂欄；`.main` 米紙地，`.sheet` 最大寬 1080px 置中） |
| 行事曆表格 | `<table>`，手機上可橫向滾動，觸控目標 ≥ 44px |
| 數字顯示 | 金額、日期、編號皆用等寬 Courier 字型（tabular-nums）、千分位顯示 |
| 收入語意 | 朱紅 `#c0392b`，前綴 `+` |
| 支出語意 | 鐵藍 `#1f4e79`，前綴 `-` |
| 結餘 | 墨黑 `#23272b`，正數前綴 `+` |
| 標題 | 標楷體顯示字 |
| 列印 | `@media print` 隱藏導覽與操作欄，行事曆表格避免分頁斷開 |

---

## 9. 非本期範圍（Out of Scope）

- 多行事曆並行：本期僅支援單一月份檢視，不支援多個行事曆並排顯示。
- 農曆顯示：本期僅顯示公曆日期，不納入農曆。
- 行事曆匯出 iCal：本期不支援將行事曆匯出為 iCal 格式。
- 行事曆內建新增/編輯記錄：本期僅為檢視，點入日期導向 `ledger.php` 篩選該日記錄，不在 `calendar.php` 直接新增記錄。
- 封存帳本不顯示於行事曆：行事曆聚焦活躍帳本，封存帳本（`archived=1`）全面排除於行事曆顯示。此為本期刻意設計決定，比既有「封存帳本僅 owner/admin 可見」（business-logic.md §6）更嚴格；未來如需支援封存帳本檢視，需另行評估權限歸屬。
- JavaScript 行事曆庫：純 PHP 伺服端渲染，不引入前端行事曆函式庫。

---

## 10. 非功能性需求

| 類別 | 需求 | 實作方式 |
| --- | --- | --- |
| 預期效能 | 行事曆頁面載入時間不超過 3 秒（單月查詢） | 單一 PDO 查詢依 `entry_date` 分組加總，`idx_entries_entry_date_deleted` 索引支援 |
| 併發處理量 | 唯讀查詢，SQLite WAL 模式支援多併發讀取 | `PRAGMA journal_mode=WAL`、`busy_timeout=5000` |
| 資料保留策略 | 行事曆檢視不產生額外資料，不涉及資料保留 | 僅讀取 `entries`，不寫入 |
| 系統可用性 | 月份參數錯誤時回退至當月，保證頁面可用 | `ym` 驗證失敗回退當月 |
| 預算顯示 | 行事曆頁面本期不顯示預算進度條與預算狀態；預算功能不在本期業務驗收條件範圍內，`calendar.php` 不預留預算介面。 | 本期不顯示預算，維持與 planning.md 業務驗收條件範圍一致；未來如需支援，另行擴充預算查詢 SQL 與顯示位置。 |

---

## 11. 威脅模型（STRIDE）

行事曆檢視頁面為僅讀 GET 查詢，但仍涉及 `ym` 與 `ledger_id` 可選參數、跨帳本加總、月份導航 URL 輸出等攻擊面。以下以 STRIDE 威脅分類法說明各威脅與現有緩解措施。

| STRIDE 類別 | 威脅 | 攻擊面 | 緩解措施 | 備註 |
|-------------|------|--------|----------|------|
| **S**poofing（偽冒） | 偽冒其他使用者身分檢視其帳本行事曆 | `ledger_id`、`ym` 參數 | `requireLogin()` 強制登入；每筆查詢 `INNER JOIN ledger_access la ... AND la.user_id = :user_id` 驗證使用者存取權限；單帳本檢視額外以 `SELECT 1 FROM ledger_access WHERE ledger_id = :ledger_id AND user_id = :user_id LIMIT 1` 前置檢查權限 | 單帳本模式採雙重防護 |
| **T**ampering（竄改） | 竄改 `ym` 或 `ledger_id` 參數存取未授權資料 | `ym`（GET）、`ledger_id`（GET） | `ym` 嚴格正則 `/^\d{4}-(0[1-9]|1[0-2])$/` 驗證，失敗回退當月；年份 `1 <= $year <= 9999` 下限檢查；`ledger_id` 必須為正整數且對應 `ledger_access` 記錄，否則回退跨帳本檢視；所有參數以 PDO 具名佔位符綁定，禁止拼字串 SQL | 防止參數竄改與 IDOR |
| **R**epudiation（否認） | 使用者否認檢視過某帳本行事曆 | 月份導航、單帳本檢視 | 行事曆為僅讀查詢，非狀態變更操作，依 AGENTS.md 規定不寫入 `audit_log`；若業務或稽核要求記錄，於月份導航或單帳本檢視時補充 `logAudit('calendar_view', ...)` | 風險接受決策需記錄 |
| **I**nformation Disclosure（資訊洩漏） | 透過 `ledger_id` 列舉使用者可存取帳本範圍；XSS 洩漏使用者可控輸出；月份導航 URL 參數外洩帳本資訊 | `ledger_id` 參數；月份標籤、帳本名稱、提示訊息；`<a href>` 連結 | 單帳本檢視無權限時靜默回退跨帳本檢視，不區分「無此帳本」與「無此帳本權限」，避免側信道列舉；所有輸出經 `h()` 轉義；`ym` 與 `ledger_id` 輸出至 `<a href>` 屬性時先 `urlencode()` 再 `h()` 雙重編碼；`INNER JOIN ledgers l ON l.archived = 0` 排除封存帳本，不顯示封存帳本資料 | 包含 XSS 與側信道防護 |
| **D**enial of Service（拒絕服務） | 極端年份（如 `0000-01`）導致 `DateTime` 例外，造成頁面錯誤 | `ym` 參數 | 年份下限檢查 `1 <= $year <= 9999`，不符合則回退當月；資料庫查詢失敗顯示通用錯誤頁，詳細錯誤寫入 `private_data/error.log`，不外洩堆疊 | 防止日期計算例外 |
| **E**levation of Privilege（權限提升） | 使用者透過 `ledger_id` 存取其無權限的帳本 | `ledger_id`（GET） | 單帳本檢視前置 `ledger_access` 權限檢查（使用者對該 `ledger_id` 有存取記錄），無記錄則回退跨帳本檢視；SQL 同時保留 `INNER JOIN ledger_access` 與額外 `AND e.ledger_id = :ledger_id`，雙重防護 | 與 Spoofing 緩解措施重疊，形成防禦深度 |

---

## 12. 設計審查紀錄

設計階段三階段審查（由獨立第三方執行，不得由設計/實作同一人自審）：

| 審查階段 | 版本 | 日期 | 結果 | 備註 |
| --- | --- | --- | --- | --- |
| Spec 審查 | v1.8 | 2026-11-24 | 通過 | 需求完整性、規格一致性、邊界條件（獨立第三方審查通過，見 Spec 審查報告） |
| Code 審查（設計階段） | v1.8 | 2026-11-24 | 通過 | 設計可行性、資料庫變更、API 設計（獨立第三方重審通過，M-1/M-2/L-1/L-2/L-3 已修正） |
| Security 審查（設計階段） | v1.8 | 2026-11-24 | 通過 | 威脅模型、權限邊界、資料流（10/10 控制點通過，2 個輕微觀察已修正） |
| Spec 審查（實作階段） | v1.8 | 2026-11-24 | 通過 | 需求完整性、規格一致性、邊界條件（獨立第三方審查通過） |
| Code 審查（實作階段） | v1.8 | 2026-11-24 | 通過 | 程式碼品質、錯誤處理、效能、測試覆蓋（獨立第三方重審第 2 輪通過，問題 A/C/D 已修正） |
| Security 審查（實作階段） | v1.8 | 2026-11-24 | 通過 | SQL Injection、XSS、CSRF、IDOR、資料外洩、STRIDE（10/10 控制點通過，3 項非阻塞觀察） |

> 所有審查需由獨立第三方進行，不可由設計/實作同一人自審。審查通過後方可進入實作階段。

---

## 13. 實作紀錄

實作完成後三階段審查（Spec / Code / Security）通過後，於此章節補充實作細節與驗收結果，並同步更新本 Design.md。

| 檔案 | 變更內容 | 日期 |
| --- | --- | --- |
| `calendar.php`（新） | 行事曆檢視頁面：月份參數解析、requireLogin、ledger_access 權限檢查、每日加總查詢、DateTime 格線、h() 輸出轉義 | 2026-11-24 已實作 |
| `assets/style.css` | 行事曆表格響應式樣式（≤720px 橫向捲動、觸控目標 ≥44px） | 2026-11-24 已實作 |
| 導覽欄 | 新增「行事曆檢視」導覽項目（.side / .nav） | 2026-11-24 已實作 |
| `ledger.php`（修改） | 支援 `entry_date` GET 參數（格式 Y-m-d，透過 `isValidDate()` 驗證），以 PDO 預備陳述式 `entry_date = :entry_date` 篩選單日記錄；供單帳本檢視點擊日期導向使用 | 2026-11-24 已實作 |

> 實作階段三階段審查（Spec / Code / Security）均已通過，驗收完成：
> - calendar.php：月份參數解析、requireLogin、ledger_access 權限檢查、每日加總查詢、DateTime 格線、h() 輸出轉義，導覽欄已加入「行事曆檢視」項目
> - assets/style.css：行事曆表格響應式樣式（≤720px 橫向捲動、觸控目標 ≥44px）
> - ledger.php：支援 entry_date GET 參數，PDO 預備陳述式篩選單日記錄
> - 設計階段三階段審查（Spec / Code / Security v1.8）及實作階段三階段審查（Spec / Code / Security）均已通過，無阻礙性問題。

---

## 相關文件

- [規劃文件](./planning.md) — [#17] 行事曆檢視功能規劃（需求來源）
- [Agents/system-architecture.md](../Agents/system-architecture.md) — 系統架構、資料流、非功能性需求
- [Agents/database-schema.md](../Agents/database-schema.md) — `entries`、`ledger_access` 表結構與索引
- [Agents/business-logic.md](../Agents/business-logic.md) — 記帳類型語意（收入/支出/轉帳）、金額整數分制
- [Agents/user-permissions.md](../Agents/user-permissions.md) — 帳本存取權限、IDOR 防護、角色矩陣
- [Agents/design-system.md](../Agents/design-system.md) — 設計系統色彩、字型、元件、響應式斷點
- [AGENTS.md](../AGENTS.md) — 專案通用開發規則、硬性約束
