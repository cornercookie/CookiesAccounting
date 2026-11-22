# 資料庫結構

> SQLite 資料庫結構定義：ERD、資料表欄位、索引、約束、遷移紀錄。

---

## ERD（實體關係圖）

```mermaid
erDiagram
    USERS ||--o{ LEDGERS : "owns"
    USERS ||--o{ LEDGER_ACCESS : "has access"
    USERS ||--o{ ENTRIES : "creates"
    USERS ||--o{ RECURRING_ENTRIES : "creates"
    USERS ||--o{ LOGIN_ATTEMPTS : "tracks"
    USERS ||--o{ LOGIN_SESSIONS : "has"
    USERS ||--o{ AUDIT_LOG : "performs"
    LEDGERS ||--o{ LEDGER_ACCESS : "grants"
    LEDGERS ||--o{ ENTRIES : "contains"
    LEDGERS ||--o{ RECURRING_ENTRIES : "contains"
    LEDGERS ||--o{ CATEGORIES : "uses (global)"

    USERS {
        INTEGER id PK
        TEXT username UK
        TEXT password_hash
        TEXT role "admin|member"
        TEXT created_at
        TEXT password_changed_at
    }
    LEDGERS {
        INTEGER id PK
        TEXT name
        INTEGER owner_id FK
        INTEGER archived "0|1"
        INTEGER monthly_budget_cents
        TEXT created_at
    }
    LEDGER_ACCESS {
        INTEGER id PK
        INTEGER ledger_id FK
        INTEGER user_id FK
        TEXT role "owner|member|viewer"
        UNIQUE(ledger_id, user_id)
    }
    ENTRIES {
        INTEGER id PK
        INTEGER ledger_id FK
        INTEGER user_id FK
        INTEGER amount_cents
        TEXT type "income|expense|transfer"
        TEXT category
        TEXT entry_date
        TEXT note
        TEXT receipt_path
        INTEGER deleted "0|1"
        INTEGER recurring_source_id FK
        TEXT created_at
    }
    RECURRING_ENTRIES {
        INTEGER id PK
        INTEGER ledger_id FK
        INTEGER user_id FK
        TEXT name
        INTEGER amount_cents
        TEXT type "income|expense"
        TEXT category
        TEXT start_date
        TEXT next_due_date
        INTEGER is_active "0|1"
        TEXT created_at
        TEXT updated_at
    }
    CATEGORIES {
        INTEGER id PK
        TEXT name UK
        INTEGER is_active "0|1"
        INTEGER ledger_id "NULL=全站共用"
        TEXT created_at
        TEXT updated_at
    }
    LOGIN_ATTEMPTS {
        TEXT identifier PK "username|IP"
        INTEGER failed_count
        TEXT locked_until
        TEXT updated_at
    }
    LOGIN_SESSIONS {
        INTEGER id PK
        INTEGER user_id FK
        TEXT ip
        TEXT user_agent
        TEXT login_at
        INTEGER is_first_seen "0|1"
    }
    AUDIT_LOG {
        INTEGER id PK
        INTEGER user_id FK
        TEXT username
        TEXT action
        TEXT detail
        TEXT ip
        TEXT created_at
    }
```

---

## 資料表詳細定義

### 1. users（使用者表）

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `username` | TEXT | NOT NULL, UNIQUE | 登入帳號，英數字底線 2-32 字元 |
| `password_hash` | TEXT | NOT NULL | bcrypt 雜湊 |
| `role` | TEXT | NOT NULL DEFAULT 'admin', CHECK IN ('admin','member') | 系統角色：admin（管理員）、member（一般成員） |
| `created_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 建立時間 |
| `password_changed_at` | TEXT | NULLABLE | 密碼最後變更時間，用於使既有 Session 失效 |

**索引**：主鍵 `id`、唯一 `username`

---

### 2. ledgers（帳本表）

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `name` | TEXT | NOT NULL | 帳本名稱 |
| `owner_id` | INTEGER | NOT NULL, FK→users.id | 擁有者 |
| `archived` | INTEGER | NOT NULL DEFAULT 0, CHECK IN (0,1) | 0=正常、1=封存 |
| `monthly_budget_cents` | INTEGER | NOT NULL DEFAULT 0 | 每月預算（整數分，0=未設定） |
| `created_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 建立時間 |

**索引**：`idx_ledgers_owner_id` (owner_id)

---

### 3. ledger_access（帳本存取權限表）

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `ledger_id` | INTEGER | NOT NULL, FK→ledgers.id | 帳本 |
| `user_id` | INTEGER | NOT NULL, FK→users.id | 使用者 |
| `role` | TEXT | NOT NULL DEFAULT 'member', CHECK IN ('owner','member','viewer') | 帳本內角色 |

**唯一約束**：`UNIQUE (ledger_id, user_id)` — 一位使用者對一個帳本只能有一種角色

**索引**：`idx_ledger_access_ledger_id` (ledger_id)、`idx_ledger_access_user_id` (user_id)

**角色權限矩陣**：

| 操作 | owner | member | viewer |
|------|-------|--------|--------|
| 檢視帳本詳情 | ✅ | ✅ | ✅ |
| 新增/編輯/作廢記錄 | ✅ | ✅ | ❌ |
| 匯入 CSV | ✅ | ✅ | ❌ |
| 匯出 CSV/PDF | ✅ | ✅ | ✅ |
| 設定每月預算 | ✅ | ❌ | ❌ |
| 管理重複記錄 | ✅ | ❌ | ❌ |
| 管理帳本成員 | ✅ | ❌ | ❌ |
| 封存/刪除帳本 | ✅ | ❌ | ❌ |

---

### 4. entries（記帳分錄表）— 核心業務表

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵，亦用於傳票編號（收-008 / 支-012 / 轉-005） |
| `ledger_id` | INTEGER | NOT NULL, FK→ledgers.id | 所屬帳本 |
| `user_id` | INTEGER | NOT NULL, FK→users.id | 記錄者 |
| `amount_cents` | INTEGER | NOT NULL | **金額整數分**，正數，不含 FLOAT |
| `type` | TEXT | NOT NULL, CHECK IN ('income','expense','transfer') | 類型：收入/支出/轉帳 |
| `category` | TEXT | NOT NULL DEFAULT '' | 分類名稱 |
| `entry_date` | TEXT | NOT NULL | 記帳日期 (Y-m-d) |
| `note` | TEXT | NOT NULL DEFAULT '' | 備註 |
| `receipt_path` | TEXT | NOT NULL DEFAULT '' | 收據圖片私密路徑 (private_data/uploads/xxx.jpg) |
| `deleted` | INTEGER | NOT NULL DEFAULT 0, CHECK IN (0,1) | 軟刪除標記：0=正常、1=已作廢 |
| `recurring_source_id` | INTEGER | NULLABLE, FK→recurring_entries.id | 來源重複記錄 ID |
| `created_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 建立時間 |

**索引**：
- `idx_entries_ledger_id` (ledger_id)
- `idx_entries_user_id` (user_id)
- `idx_entries_entry_date_deleted` (entry_date, deleted) — 篩選與分頁核心索引
- `idx_entries_type` (type)

**傳票編號規則**：
- 收入：`收-` + 零補齊 3 碼 `id`（如 `收-008`）
- 支出：`支-` + 零補齊 3 碼 `id`（如 `支-012`）
- 轉帳：`轉-` + 零補齊 3 碼 `id`（如 `轉-005`）

---

### 5. recurring_entries（重複記錄表）

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `ledger_id` | INTEGER | NOT NULL, FK→ledgers.id | 所屬帳本 |
| `user_id` | INTEGER | NOT NULL, FK→users.id | 建立者 |
| `name` | TEXT | NOT NULL DEFAULT '' | 重複記錄名稱（如「房租」、「水費」） |
| `amount_cents` | INTEGER | NOT NULL CHECK (>0) | 金額整數分，必須 > 0 |
| `type` | TEXT | NOT NULL CHECK IN ('income','expense') | 類型：收入/支出（無轉帳） |
| `category` | TEXT | NOT NULL DEFAULT '' | 分類 |
| `start_date` | TEXT | NOT NULL | 起始日期 (Y-m-d) |
| `next_due_date` | TEXT | NOT NULL | 下次預計記錄日期 (Y-m-d) |
| `is_active` | INTEGER | NOT NULL DEFAULT 1 CHECK IN (0,1) | 1=啟用、0=停用 |
| `created_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 建立時間 |
| `updated_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 更新時間 |

**索引**：
- `idx_recurring_entries_ledger_id` (ledger_id)
- `idx_recurring_entries_user_id` (user_id)
- `idx_recurring_entries_next_due` (next_due_date)

**生成機制**：使用者點擊「立即記一筆」→ 建立 `entries` 記錄，`recurring_source_id` 關聯 → `next_due_date` 由前端/排程更新（目前手動觸發）

---

### 6. categories（分類表）— 全站共用

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `name` | TEXT | NOT NULL, UNIQUE | 分類名稱，全站唯一 |
| `is_active` | INTEGER | NOT NULL DEFAULT 1 CHECK IN (0,1) | 1=啟用、0=停用（隱藏不刪除） |
| `ledger_id` | INTEGER | DEFAULT NULL | **固定為 NULL**，代表全站共用分類 |
| `created_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 建立時間 |
| `updated_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 更新時間 |

**索引**：`idx_categories_is_active` (is_active)、`idx_categories_name` (name)

> **設計決策**：分類為全站共用（`ledger_id=NULL`），不屬於單一帳本。管理員在後台管理，所有帳本共用同一組分類。

---

### 7. login_attempts（登入嘗試記錄）

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `identifier` | TEXT | PK | 識別碼：`username|IP` 格式（帳號加 IP 綁定） |
| `failed_count` | INTEGER | NOT NULL DEFAULT 0 | 連續失敗次數 |
| `locked_until` | TEXT | NULLABLE | 鎖定截止時間，NULL 表示未鎖定 |
| `updated_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 更新時間 |

**邏輯**：
- 失敗時 `failed_count + 1`，達 5 次 → `locked_until = now + 15 分鐘`
- 成功時清零 `failed_count=0`、`locked_until=NULL`
- 鎖定期間拒絕登入嘗試

---

### 8. login_sessions（登入會話記錄）— 異常登入偵測用

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `user_id` | INTEGER | NOT NULL, FK→users.id | 使用者 |
| `ip` | TEXT | NOT NULL DEFAULT '' | 登入 IP |
| `user_agent` | TEXT | NOT NULL DEFAULT '' | User-Agent 字串 |
| `login_at` | TEXT | NOT NULL | 登入時間 |
| `is_first_seen` | INTEGER | NOT NULL DEFAULT 1 CHECK IN (0,1) | 1=首次見到此 IP+UA 組合 |

**用途**：登入時比對 (IP, User-Agent)，若為首次見到 → 寫入 `login_sessions` 並顯示「新登入來源」提醒。

**索引**：`idx_login_sessions_user_id` (user_id)、`idx_login_sessions_login_at` (login_at)

---

### 9. audit_log（操作審計日誌）— 唯新增不刪除

| 欄位 | 型別 | 約束 | 說明 |
|------|------|------|------|
| `id` | INTEGER | PK, AUTOINCREMENT | 主鍵 |
| `user_id` | INTEGER | NULLABLE, FK→users.id | 操作者（未登入時為 NULL） |
| `username` | TEXT | NOT NULL DEFAULT '' | 操作者帳號（冗存，便於查詢） |
| `action` | TEXT | NOT NULL | 動作代碼（如 `login`、`entry_create`、`admin_user_delete`） |
| `detail` | TEXT | NOT NULL DEFAULT '' | 詳細資訊（JSON 或 key=value 格式） |
| `ip` | TEXT | NOT NULL DEFAULT '' | 操作者 IP |
| `created_at` | TEXT | NOT NULL DEFAULT (datetime('now','localtime')) | 發生時間 |

**常見 action 代碼**：
- `login` / `logout` / `login_failed` / `login_locked`
- `entry_create` / `entry_edit` / `entry_delete` / `entry_void`
- `csv_import` / `csv_export` / `pdf_export`
- `recurring_create` / `recurring_edit` / `recurring_toggle` / `recurring_generate`
- `ledger_create` / `ledger_archive` / `ledger_restore` / `ledger_budget_set`
- `admin_user_create` / `admin_user_reset_password` / `admin_user_change_role` / `admin_user_delete`
- `admin_ledger_create` / `admin_ledger_access_grant` / `admin_ledger_access_revoke` / `admin_ledger_reassign_owner`
- `category_create` / `category_update` / `category_delete`
- `password_change` / `ai_parse`

**索引**：`idx_audit_log_created_at` (created_at)、`idx_audit_log_user_id` (user_id)、`idx_audit_log_action` (action)

---

## 遷移紀錄

| 版本 | 日期 | 變更內容 | 相容性 |
|------|------|----------|--------|
| v1 | 初始 | 建立 users、ledgers、ledger_access、entries、login_attempts、audit_log | 基礎版本 |
| v2 | 2026-11 | 新增 login_sessions、categories、recurring_entries | 向前相容 |
| v3 | 2026-11 | entries.type CHECK 加入 'transfer'（需表重建） | 遷移腳本冪等 |
| v4 | 2026-11 | ledgers 加 monthly_budget_cents、archived | ALTER TABLE ADD COLUMN |
| v5 | 2026-11 | entries 加 receipt_path、recurring_source_id | ALTER TABLE ADD COLUMN |
| v6 | 2026-11 | users 加 password_changed_at | ALTER TABLE ADD COLUMN |

> **遷移原則**：`ensureSchema()` 在每次 `getPdo()` 執行，所有 ALTER 皆有冪等性守衛（檢查欄位是否存在）。

---

## 關鍵查詢模式與索引對應

| 查詢場景 | 使用索引 | 備註 |
|----------|----------|------|
| 帳本詳情：本月分頁查詢 | `idx_entries_entry_date_deleted` (entry_date, deleted) | 核心查詢，WHERE ledger_id + entry_date BETWEEN + deleted=0 |
| 儀表板：用戶可存取帳本彙總 | `idx_ledger_access_user_id` + `idx_entries_ledger_id` | JOIN ledger_access → entries |
| 分類統計：本月支出類別彙總 | `idx_entries_entry_date_deleted` + `idx_entries_type` | WHERE type='expense' + entry_date BETWEEN |
| 審計日誌：按時間查詢 | `idx_audit_log_created_at` | 管理員檢視 |
| 登入鎖定檢查 | 主鍵 (identifier) | PK 查詢極快 |
| 重複記錄：下次到期查詢 | `idx_recurring_entries_next_due` | 儀表板提醒 |

---

## 資料完整性規則

| 規則 | 實作方式 |
|------|----------|
| **金額精確到分** | `amount_cents` INTEGER，`toCents()` 解析、`formatCents()` 格式化，禁用 FLOAT |
| **軟刪除** | `entries.deleted=1` 標記，所有查詢預設 `WHERE deleted=0` |
| **IDOR 防護** | 所有查詢 `INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id` |
| **傳票編號唯一** | `entries.id` AUTOINCREMENT 保證唯一 |
| **審計不可刪除** | `audit_log` 無 DELETE 權限，僅管理員可讀 |
| **密碼變更失效 Session** | `users.password_changed_at` 更新，`requireSessionFresh()` 檢查並銷毀舊 Session |
| **至少一位管理員** | `admin/users.php` 刪除/變更角色時檢查 `COUNT(*) WHERE role='admin'` |

---

## 相關文件

- [系統架構](./system-architecture.md) — 技術架構、資料流、部署
- [業務邏輯](./business-logic.md) — 核心規則、狀態機、計算邏輯
- [使用者權限](./user-permissions.md) — 角色矩陣、權限規則
- [服務架構](./service-architecture.md) — 前後端歸屬、頁面對應