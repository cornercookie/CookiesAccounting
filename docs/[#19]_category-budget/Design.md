# Design & Implementation: 類別預算設定頁面（類別層級預算 CRUD）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 名稱 | 類別預算設定頁面設計（類別層級預算新增／編輯／停用） |
| 版本 | v1.4 |
| 建立日期 | 2026-11-24 |
| 更新日期 | 2026-11-24 |
| 任務狀態 | 實作完成（基於既有 category_budget.php，無新增程式變更）；實作審查 Spec／Code／Security 三階段皆通過；已回寫 Agents/ 知識；PHP 語法檢查與實機驗收需於部署環境執行 |
| 使用者 Planning／需求來源 | `docs/[#19]_category-budget/planning.md` v1.0（2026-11-22），需求由該 Planning 原文提供，不自建或改名 Planning |
| 程式基準 | commit `77be9ca` ([#18])，main 分支，工作乾淨 |

### 版本與章節變更摘要

| 版本 | 變更章節 | 摘要 |
| --- | --- | --- |
| v1.0 | 全文 | 初稿，誤認類別預算設定頁面「缺失」並擬新實作；HTTP 契約與既有程式不相容；存取政策與既有程式矛盾；category 與 categories 表關係描述錯誤。 |
| v1.1 | 需求與現況、§4 設計與契約、§4.4 HTTP 契約、§4.5 權限控管、§4.6 邊界與錯誤、驗收條件 | 依獨立第三方設計審查修正：以既有 `category_budget.php`（268 行）為事實基準；更正現況（頁面已存在、完整實作 create/update/disable）；HTTP 契約對齊既有程式（action `create`/`update`/`disable`、audit `category_budget_create`/`category_budget_update`/`category_budget_disable`、刪除＝停用 `SET is_active=0`）；存取政策改為僅 owner 可存取頁面（member/viewer 與非成員無法檢視）；category 與 categories 表關係以程式碼為準（schema 有 FK，頁面以 categories 表拉 select 選項）。 |
| v1.2 | §4.6 邊界與錯誤；文件基本資訊 | 依設計審查第 2 次複查修正：§4.6「update／disable 的 id 不存在或不屬於該帳本」改為與 `category_budget.php` 實際行為一致（UPDATE/SET is_active=0 不檢查 rowCount，影響 0 列時仍 redirect 成功訊息，資料庫無變更），消除可核計的事實錯誤。 |
| v1.3 | 文件基本資訊、設計審查、實作與設計變更、實際驗證結果、實作審查、知識回寫 | 依設計審查與實作審查三階段皆通過，任務完成：任務狀態改為「完成」；補實作審查紀錄（Spec／Code／Security 三階段通過）；知識回寫 `Agents/user-permissions.md` 加入「設定類別預算」權限（僅 owner）；PHP 語法檢查與實機驗收標記為需於部署環境執行（本機無 PHP）。 |
| v1.4 | 文件基本資訊、實作與設計變更 | 實作階段結案：確認現有 `category_budget.php`（268 行）已完整實作設計契約（新增 create／編輯 update／停用 disable、CSRF、owner 權限、audit_log、PDO 預備陳述式、h() 轉義），無新增程式變更；實作審查 Spec／Code／Security 三階段皆由獨立子代理通過；任務可交付，僅剩部署環境之 PHP 語法檢查與 12 項實機驗收。 |

## 需求與現況

### 需求摘要（對照 Planning）

根據 `planning.md`「預期行為」章節，本任務範圍為第 4 項「新增類別預算設定頁面，僅擁有者可新增/編輯/刪除」，並落實第 5-9 項的控管（CSRF、整分金額、h() 轉義、PDO 預備陳述式、owner 權限）。第 1-3、10 項（資料表、查詢比較、80%/100% 警告、與帳本預算並存）已於現有程式碼中完成，此處不重複建置。

重要事實更正（v1.1）：類別預算設定頁面 `category_budget.php` **已存在**（268 行），已完整實作新增／編輯／停用、CSRF 防護、owner 權限授權、audit_log 記錄、PDO 預備陳述式、h() 輸出。本設計不新增頁面，僅將設計契約與既有程式記錄對齊，以供設計審查與後續回歸驗證使用。

業務驗收條件（Planning）：
1. 類別預算設定頁面可供帳本擁有者存取，僅擁有者可新增、編輯、刪除類別預算。（既有程式以「停用」實作刪除，見 §差異與待確認）
2. 類別預算表單提交時，系統驗證跨站請求偽造（CSRF）權杖，驗證失敗拒絕請求。（既有實作）
3. ledger.php 月份檢視頁面顯示各類別本月支出與類別預算的比較結果。（已存在）
4. 類別支出達預算 80% 時顯示警告，達 100% 時顯示危險。（已存在）
5. dashboard.php 頁面顯示類別預算警告橫幅。（已存在）
6. 所有使用者可控輸出經輸出轉義函數（h()）轉義，防護跨站腳本（XSS）。（既有實作）

### 已確認現況（程式碼為準）

| 項目 | 現況 | 來源 |
| --- | --- | --- |
| 資料表 `category_budgets` | 已建立：`id, ledger_id, category, monthly_budget_cents CHECK (>0), is_active, created_at, updated_at`；`category` 有 `FOREIGN KEY (category) REFERENCES categories(name)`；索引 `idx_category_budgets_ledger_active`、`idx_category_budgets_ledger_category`；唯一性由應用層保證（INSERT 前檢查啟用中同類別） | `db.php` ensureSchema() 行 228-248 |
| 頁面 `category_budget.php` | 已存在 268 行：`requireLogin()` + `INNER JOIN ledger_access` 驗證 owner；POST `create`/`update`/`disable`；CSRF `checkCsrf()`；類別長度≤50 與字元白名單；金額 `toCents()` >0；create 前檢查同類別重複；INSERT/UPDATE/SET is_active=0；audit `category_budget_create`/`category_budget_update`/`category_budget_disable`；GET 查詢 is_active=1 列表；以 `categories` 表全站啟用分類作為 select 選項；編輯模式類別不可變更；所有輸出 `h()` 轉義 | `category_budget.php` |
| 共用預算函數 | `calcBudgetStatus()`、`getBudgetStatusLabel()`、`getBudgetPercent()`（整數交叉相乘，≥80% warn、≥100% danger） | `functions.php` |
| ledger.php 類別預算查詢與顯示 | 已實作：查詢 `category_budgets` 與各類別本月支出比較，顯示進度條、狀態晶片、警告／危險標籤 | `ledger.php` 行 736-886 |
| dashboard.php 類別預警橫幅 | 已實作：批次查詢所有可存取帳本類別預算與支出，顯示 warn／danger 橫幅 | `dashboard.php` 行 238-319 |

### 差異與待確認（與 Planning、既有程式的差異）

1. **存取政策（與 Planning 權限段的差異）**：Planning 身分驗證與權限控管段寫「member 與 viewer 僅可檢視」，但預期行為 9 僅規定「role = 'owner' 可修改類別預算」，未明文開放檢視。既有 `category_budget.php:29` 將非 owner（含 member／viewer）全數 `redirect` 錯誤，無法檢視列表。Design 以既有程式為事實基準：**僅 owner 可存取頁面（檢視與修改）**，member／viewer 與非成員無法檢視。此為與 Planning「成員與 viewer 僅可檢視」的差異，需使用者確認；Design 不憑空新增「必須開放檢視」規格。

2. **刪除語意**：Planning 預期行為 4 以「新增/編輯/刪除」字眼，未定義刪除語意。既有程式以「停用」（`SET is_active = 0`）實作刪除，無硬刪除 `DELETE`；audit 動作為 `category_budget_disable`。Design 以既有程式為準；硬刪除屬範圍外，供使用者決定。

3. **category 與 categories 表關係**：`db.php:238` 宣告 `FOREIGN KEY (category) REFERENCES categories(name)`，頁面以 `categories` 表全站啟用分類作為 select 選項（`category_budget.php:110-112`）；但 `ledger.php` 類別支出比較以 `entries.category` 字串比對。Design 以程式碼為準，不宣稱「不參考 `categories` 表」。

4. **寫入歸屬驗證**：既有程式在 UPDATE／SET is_active 時皆加 `AND ledger_id = :ledger_id`（`category_budget.php:73,89`），已強制預算記錄歸屬該帳本，符合 IDOR 防護。Design §4.6 記錄此契約。

## 設計、資料流與契約

### 4.1 頁面入口

- 頁面：`./category_budget.php?ledger_id=<int>`（已存在，僅 owner 可存取）
- 入口：直接存取 `category_budget.php?ledger_id=X`；`ledger.php` 側欄連結既有程式未加入，Design 不納入本期實作（屬建議，供使用者決定；不以新增規格修正）

### 4.2 資料流（Mermaid）

```mermaid
flowchart TD
    A[使用者開啟 category_budget.php?ledger_id=X] --> B[requireLogin() 檢查登入]
    B -->|未登入| C[redirect login.php]
    B -->|已登入| D[INNER JOIN ledger_access 驗證 ledger_id + user_id 存取 + role='owner']
    D -->|非 owner 或無存取| E[redirect ledger.php?error=1 僅帳本擁有者可管理類別預算]
    D -->|owner| F[GET 查詢 category_budgets 該帳本 is_active=1 列表；以 categories 表拉 select 選項]
    F --> G[渲染類別預算列表與新增／編輯表單；僅 owner 顯示操作]

    H[Owner 提交表單 POST action=create/update/disable] --> I[checkCsrf() hash_equals 驗證]
    I -->|失敗| J[redirect 帶錯誤訊息，不寫入]
    I -->|通過| K[驗證類別非空、長度≤50、字元白名單；金額 toCents() >0]
    K -->|驗證失敗| L[redirect 帶錯誤訊息，不寫入]
    K -->|通過| M{action}
    M -->|create| N[INSERT 前檢查同 ledger_id+category+is_active=1 是否存在；存在則拒絕]
    N -->|存在| O[redirect 提示重複，不寫入]
    N -->|不存在| P[INSERT category_budgets；logAudit('category_budget_create')]
    M -->|update| Q[UPDATE category_budgets WHERE id=:id AND ledger_id=:ledger_id；僅可改金額，類別不可變更；logAudit('category_budget_update')]
    M -->|disable| R[UPDATE category_budgets SET is_active=0 WHERE id=:id AND ledger_id=:ledger_id；logAudit('category_budget_disable')]
    P --> S[redirect 回 category_budget.php 成功訊息]
    Q --> S
    R --> S

    T[ledger.php 月份檢視 ／ dashboard.php 載入] --> U[查詢 entries 各類別本月支出 LEFT JOIN category_budgets 比較]
    U --> V[≥80% 警告 ≥100% 危險 h() 轉義顯示]
```

### 4.3 資料庫契約（現有，不新增表）

`category_budgets` 表（已由 `db.php` ensureSchema() 建立，本任務不變更 schema）：

| 欄位 | 型別 | 約束 | 業務意義 |
| --- | --- | --- | --- |
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `ledger_id` | INTEGER | NOT NULL, FK→ledgers.id | 所屬帳本 |
| `category` | TEXT | NOT NULL, FK→categories(name) | 類別名稱，參考全站分類表；與 entries.category 字串比對 |
| `monthly_budget_cents` | INTEGER | NOT NULL CHECK (>0) | 每月預算金額，整數分 |
| `is_active` | INTEGER | NOT NULL DEFAULT 1 CHECK (0,1) | 1=啟用、0=停用 |
| `created_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 建立時間 |
| `updated_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 最後變更時間 |

索引：`idx_category_budgets_ledger_active` (ledger_id, is_active)、`idx_category_budgets_ledger_category` (ledger_id, category)。

### 4.4 契約：HTTP 介面（category_budget.php）

| 項目 | 內容 |
| --- | --- |
| URL | `./category_budget.php?ledger_id=<int>` |
| 方法 | GET（檢視列表與表單）、POST（新增／編輯／停用） |
| 查詢參數 | `ledger_id`（必填，正整數）；`edit_id`（GET 編輯預填） |
| POST 欄位 | `action` (`create`/`update`/`disable`)、`csrf_token`、`category`、`monthly_budget`、`id`（update／disable 時） |
| 權限前置 | `requireLogin()`；`INNER JOIN ledger_access` 確認 `user_id` 對 `ledger_id` 有存取且 `role='owner'`；非 owner 全數 redirect 錯誤（無法檢視） |
| CSRF | 所有 POST 表單含 `csrf_token`，以 `hash_equals()` 恆時比較 |
| 金額 | `toCents()` 解析為整數分，>0，禁止 FLOAT；顯示 `formatCents()` 除以 100 |
| 類別 | 長度 ≤ 50、字元白名單 `[\p{L}\p{N}\s\-_]`，非空；編輯模式類別不可變更 |
| 寫入歸屬 | UPDATE／SET is_active 皆加 `AND ledger_id = :ledger_id`，強制預算記錄歸屬該帳本（IDOR 防護） |
| 輸出轉義 | 所有使用者可控輸出（類別名稱、帳本名稱、訊息）經 `h()` 轉義 |
| SQL | 所有查詢與寫入使用 PDO 預備陳述式，具名佔位符，`ATTR_EMULATE_PREPARES=false` |
| 審計 | 新增 `logAudit('category_budget_create', ...)`；編輯 `logAudit('category_budget_update', ...)`；停用 `logAudit('category_budget_disable', ...)` |
| 錯誤導向 | 失敗皆 `redirect()` 帶錯誤訊息至 `category_budget.php?ledger_id=...&error=1&msg=...`，不外洩 SQL／堆疊 |

### 4.5 身分驗證與權限控管

- 身分驗證：`requireLogin()` 檢查 `$_SESSION['user_id']`，未登入導向 `login.php`；登入後 `session_regenerate_id(true)`（既有機制）。
- 帳本存取：`INNER JOIN ledger_access la ON la.ledger_id = l.id AND la.user_id = :user_id AND l.archived = 0`，無記錄或非 `owner` 即 redirect 錯誤（IDOR 防護）。
- 類別預算頁面存取：僅 `la.role = 'owner'` 可存取頁面（檢視與修改）；member 與 viewer 與非成員無法檢視列表，redirect `ledger.php?error=1`。此為與既有程式 `category_budget.php:29` 一致；Planning 預期行為 9 僅規定 owner 可修改，檢視政策未明文，以既有程式為準，需使用者確認。
- 站台管理員（users.role='admin'）不自動擁有本帳本權限，必須透過 ledger_access 被授權（比照既有原則）。
- 資料傳輸加密：依賴既有 HTTPS Session Cookie（secure、HttpOnly、SameSite=Lax）。

### 4.6 邊界與錯誤行為

| 場景 | 行為 |
| --- | --- |
| 未登入 | redirect login.php |
| 無 ledger_access 記錄或非 owner | redirect `ledger.php?error=1`，無法檢視列表 |
| CSRF 權杖缺失／不符 | redirect 錯誤訊息，不寫入（hash_equals 恆時比較） |
| 類別名稱空白／超過 50 字元／非白名單字元 | redirect 錯誤訊息，不寫入 |
| 金額負值、零值、非數字 | toCents() 回傳 false 或 ≤0，redirect 錯誤訊息，不寫入 |
| create 時同帳本同類別已有 is_active=1 | INSERT 前檢查，redirect 提示重複，不寫入 |
| update／disable 的 id 不存在或不屬於該帳本 | 程式執行 UPDATE／SET is_active=0 但不檢查受影響列數（rowCount）；即使 id 不存在或不屬於該帳本（影響 0 列），資料庫無變更，仍 redirect 成功訊息（此為既有 `category_budget.php` 實際行為，非錯誤導向；WHERE id=:id AND ledger_id=:ledger_id 僅約束歸屬，不保證影響列數） |
| 編輯模式 | 類別不可變更，僅可修改金額 |
| 資料庫寫入失敗 | 顯示通用錯誤頁，詳細錯誤寫入 private_data/error.log，不外洩 SQL |
| 審計寫入失敗 | try-catch → logError，不中斷主流程 |
| 無類別預算設定 | 列表顯示「尚無類別預算」，維持原版面（不顯示警告區塊） |

### 4.7 非功能性需求

| 項目 | 設計 |
| --- | --- |
| 效能 | 類別預算查詢與既有月份彙總分開單次查詢，不顯著增加頁面載入時間；dashboard 批次查詢避免 N+1 |
| 併發 | 單筆寫入，SQLite WAL 模式 + busy_timeout=5000；應用層 INSERT 前檢查同類別重複（無 DB 唯一索引，高併發下可能寫入重複，屬已接受限制，見建議） |
| 資料保留 | category_budgets 記錄長期保留，與帳本生命週期一致；audit_log 僅新增不刪除；刪除以停用 `is_active=0` 實作，資料保留 |
| 可用性 | 類別預算設定頁面失敗不影響主帳本檢視功能 |

## 非本期範圍（Out of Scope）

- 類別預算通知：本期僅於網頁顯示警告，不發送電子郵件或推播通知。
- 年度類別預算：本期僅支援每月預算，不支援年度預算。
- 預算轉撥：本期不支援類別間預算轉撥。
- 類別預算匯出／匯入：本期不納入 CSV／PDF 匯出。
- 硬刪除類別預算：本期以停用 `is_active=0` 實作刪除，audit_log 記錄 `category_budget_disable`，不執行 `DELETE`。
- member／viewer 開放檢視：Planning 預期行為 9 僅規定 owner 可修改，檢視政策未明文；既有程式僅 owner 可存取。是否開放 member／viewer 檢視屬範圍外，供使用者決定。
- ledger.php 側欄「類別預算設定」連結：既有程式未加入，屬建議，供使用者決定。
- DB 唯一索引 `(ledger_id, category, is_active)`：應用層已 INSERT 前檢查重複，高併發下可能重複；是否補加唯一索引屬建議，供使用者決定。

## 驗收條件與驗證方式

| 需求／情境 | 前置條件與操作 | 可判定預期結果 | 驗證方法 |
| --- | --- | --- | --- |
| 類別預算設定頁面僅 owner 可存取 | 登入為 owner，開啟 category_budget.php?ledger_id=X | 顯示類別預算列表與新增／編輯／停用操作 | 瀏覽器開啟、檢視 HTML |
| member／viewer 與非成員無法檢視 | 登入為 member／viewer，或無 ledger_access，開啟同一頁面 | redirect 錯誤訊息，無法檢視列表 | 瀏覽器開啟 |
| 未登入導向登入 | 未登入直接訪問 category_budget.php | redirect login.php | 瀏覽器開啟 |
| 新增類別預算成功 | owner 填類別與金額，POST action=create | 資料庫新增一筆 is_active=1；audit_log 有 category_budget_create；redirect 成功訊息 | 查 DB、audit_log、頁面訊息 |
| 編輯類別預算成功（僅改金額）| owner 開啟 edit_id 編輯，修改金額 POST action=update | 資料庫該筆 monthly_budget_cents 更新，類別不變；audit_log 有 category_budget_update | 查 DB、audit_log |
| 停用類別預算 | owner POST action=disable | 資料庫該筆 is_active=0；audit_log 有 category_budget_disable；redirect 成功訊息 | 查 DB、audit_log |
| CSRF 驗證失敗拒絕 | 移除或竄改 csrf_token POST | redirect 錯誤訊息，資料庫無變更 | 瀏覽器開發人員工具竄改表單 |
| 類別名稱格式不正確 | 填入空白／超過 50 字／非白名單字元 | redirect 錯誤訊息，不寫入 | 瀏覽器輸入 |
| 金額負值／零值被拒 | 填入 -100 或 0 送出 | redirect 錯誤訊息，不寫入資料庫 | 瀏覽器輸入 |
| 同類別重複新增 | 同帳本同類別已有啟用預算，再新增同類別 | redirect 提示重複，不寫入重複記錄 | 瀏覽器操作 |
| 編輯類別不可變更 | 編輯模式下類別欄位 disabled | 類別名稱不變，僅金額可改 | 檢視 HTML / 操作 |
| 所有輸出 h() 轉義 | 類別名稱含 HTML 特殊字元 | 頁面顯示轉義後文字，未執行 HTML | 檢視原始碼 |
| ledger.php 與 dashboard.php 類別預警不受影響 | 現有查詢顯示與橫幅 | 維持既有行為，不顯著增加載入時間 | 回歸檢查 ledger.php／dashboard.php |

## 設計審查

實作前依序完成 Spec → Code → Security。每次複查另起一列，註明第幾次。結論為待審查／需修正／受阻／通過；未執行不得預填通過。

| 階段 | 第幾次、日期、審查者／方法、版本與範圍 | 發現位置、依據及影響 | 修正與複查證據 | 結論 |
| --- | --- | --- | --- | --- |
| Spec | 第 1 次，獨立子代理（唯讀靜態推論），Design v1.0 | Design 現況誤認（頁面已存在）、category／categories FK 矛盾 | v1.1 以既有程式為事實基準更正現況、契約與權限 | 需修正（已修正） |
| Code | 第 1 次，獨立子代理（唯讀靜態推論），Design v1.0 | HTTP 契約與既有程式不相容（action、audit 動作、刪除＝停用）；存取政策與既有程式矛盾 | v1.1 對齊既有程式（create/update/disable、category_budget_create/update/disable、僅 owner 存取） | 需修正（已修正） |
| Security | 第 1 次，獨立子代理（唯讀靜態推論），Design v1.0 | 無阻結；僅文件補充建議（寫入歸屬驗證契約） | v1.1 已在 §4.4、§4.6 記錄寫入歸屬 `AND ledger_id=:ledger_id` | 通過 |
| Spec | 第 2 次複查，獨立子代理（唯讀靜態推論），Design v1.1 | §4.6 與 `category_budget.php` 實際行為不符（非存在 ID 的 update/disable 仍顯示成功，無 rowCount 檢查） | v1.2 §4.6 改為與程式實際行為一致 | 需修正（已修正） |
| Code | 第 2 次複查，獨立子代理（唯讀靜態推論），Design v1.1 | HTTP 契約、audit 動作、刪除＝停用、寫入歸屬均正確對齊 | — | 通過 |
| Spec | 第 3 次複查，獨立子代理（唯讀靜態推論），Design v1.2 | §4.6 與程式碼事實基準一致，未引入新阻塞 | — | 通過 |

## 實作與設計變更

本任務無新增程式變更：類別預算設定頁面 `category_budget.php`（268 行）已存在並完整實作新增（`create`）／編輯（`update`）／停用（`disable`）、CSRF 防護、owner 權限授權、audit_log 記錄、PDO 預備陳述式、`h()` 輸出。設計契約（Design §4.4 與 §4.6）已以既有程式為事實基準對齊（v1.1、v1.2）。

實際變更僅為文件層面：`docs/[#19]_category-budget/Design.md` v1.0 → v1.2，將設計契約與既有 `category_budget.php` 記錄對齊，消除四項設計審查阻塞（頁面現況、category/categories FK 關係、HTTP 契約與 audit 動作、僅 owner 存取政策、§4.6 與程式 rowCount 行為一致）。

若後續依使用者決定補加 `ledger.php` 側欄「類別預算設定」連結、DB 唯一索引 `(ledger_id, category, is_active)`、硬刪除或 `toggle_category_budget` 動作，此處補記實作變更。

| 檔案 | 變更內容 |
| --- | --- |
| `docs/[#19]_category-budget/Design.md` | v1.0 → v1.2，設計契約與既有 `category_budget.php` 對齊（見版本與章節變更摘要） |
| `category_budget.php` | 無變更（現有實作已滿足設計契約） |
| `db.php` | 無變更（`category_budgets` 表已存在） |
| `functions.php` | 無變更（`calcBudgetStatus` 等共用函數已存在） |
| `ledger.php` | 無變更（類別預算查詢顯示已存在） |
| `dashboard.php` | 無變更（類別預警橫幅已存在） |

## 實際驗證結果

| 驗收情境 | 環境、日期與版本 | 實際指令／操作與結果 | 證據及限制 |
| --- | --- | --- | --- |
| PHP 語法檢查 `php -l` | 本機（Windows，無 PHP 執行環境） | 指令 `php` 不可用，未執行 | 環境限制：需於部署環境（cPanel PHP 8.1+）或具備 PHP 的環境執行，不標示為已通過 |
| 設計契約與既有程式對照（靜態） | 文件審查，2026-11-24，Design v1.2 | §4.4 HTTP 契約（action `create`/`update`/`disable`、audit 動作、刪除＝停用）與 `category_budget.php` 完全對齊；§4.6 邊界與錯誤與程式行為一致（已通過獨立子代理設計審查 Spec／Code／Security） | `category_budget.php` 268 行；Design.md §4.4、§4.6 |
| 驗收條件（12 項） | 未執行 | 未於實機驗證 | 需於具備 PHP 與 SQLite 的環境，以瀏覽器與資料庫查詢逐一驗證（見上方驗收條件表） |

> 未部署不得寫成已上線；真人驗收需於部署後另行進行。

## 實作審查

實作與驗證後依序完成 Spec → Code → Security，與設計審查分開記錄。每次複查另起一列。不能以測試通過取代審查。

| 階段 | 第幾次、日期、審查者／方法、版本與範圍 | 發現位置、依據及影響 | 修正與複查證據 | 結論 |
| --- | --- | --- | --- | --- |
| Spec | 通過（獨立子代理實作審查，Design v1.2 與 `category_budget.php` 對齊；12 項驗收條件需於部署環境實機驗證） | — | — | 通過 |
| Code | 通過（獨立子代理實作審查，PDO 預備陳述式／CSRF `hash_equals()`／整分金額／`h()` 轉義／寫入歸屬 `AND ledger_id` 均符合） | — | — | 通過 |
| Security | 通過（獨立子代理實作審查，`requireLogin()`、`INNER JOIN ledger_access` 僅 owner、CSRF、輸入驗證、`audit_log` 僅新增皆符合） | — | — | 通過 |

> 本機無 PHP 執行環境，實作審查為靜態程式／文件推論；`php -l` 與 12 項驗收條件未於實機執行（已於「實際驗證結果」註明環境限制）。實作審查無阻塞發現（6 項建議不影響結論），可交付。

## 知識回寫、剩餘工作與交付狀態

- 受影響的專案知識主題：`Agents/user-permissions.md`（已回寫，加入「設定類別預算」權限：僅 owner，`category_budget.php` POST create/update/disable，member／viewer 與非成員無法檢視）；`Agents/database-schema.md`（category_budgets 已存在，無須變更）。
- 剩餘工作：PHP 語法檢查 `php -l` 與 12 項驗收條件需於部署環境（cPanel PHP 8.1+）或具備 PHP 的環境執行（本機無 PHP，已於「實際驗證結果」註明環境限制）；真人驗收需於部署後另行進行。
- 已知風險：應用層 INSERT 前檢查同類別重複無 DB 唯一索引，高併發下可能寫入重複記錄（Planning 已接受「無鎖定、最後寫入勝出」）；類別預算的 category 與 entries.category 字串比對，若大小寫或空白差異會導致比對不命中（比照既有 ledger.php 行為）。
- 完成／部署／真人驗收各自描述，不互相冒充；未部署不得寫成已上線。本任務設計與實作審查已通過，可交付；部署與真人驗收不在此任務範圍。

