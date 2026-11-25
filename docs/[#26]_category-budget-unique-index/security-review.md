# Security 審查報告：category_budgets 唯一索引

**審查對象**：
- Design.md：`docs/[#26]_category-budget-unique-index/Design.md` v1.0
- 現況程式碼：`db.php`（ensureSchema）、`category_budget.php`（POST create 邏輯）

**審查日期**：2026-11-25  
**審查者**：Security Subagent  
**版本範圍**：Design v1.0、現有程式碼基準

---

## 審查結果摘要

| 編號 | 風險等級 | 類別 | 發現位置 | 描述 | 結論 |
|---|---|---|---|---|---|
| SEC-01 | 低 | 資訊洩露/推測攻擊 | Design.md §3.3、category_budget.php:157-158 | 唯一約束錯誤轉向的友善訊息「該類別已有啟用中的預算，請編輯現有記錄。」可讓攻擊者推測特定 `ledger_id` 下某 `category` 是否已有啟用中預算 | **需緩解** — 建議統一錯誤訊息不洩露「啟用中」細節 |
| SEC-02 | 低 | 資訊洩露/推測攻擊 | category_budget.php:59-63 | 應用層檢查（SELECT）同樣洩露類別是否存在於啟用中預算；唯一索引錯誤處理只是重複同一洩露面 | **現況可接受** — 需登入且為 owner 才能觸發，風險受限於授權使用者 |
| SEC-03 | 中 | 權限檢查順序 | category_budget.php:7-31, 38-43 | `requireLogin()` + `INNER JOIN ledger_access role='owner'` 在 try-catch 之前，CSRF `checkCsrf()` 也在 try-catch 之前 → **權限檢查與 CSRF 皆在交易邏輯之前生效** | **通過** — 順序正確 |
| SEC-04 | 中 | 錯誤詳情外洩 | category_budget.php:155-160 | `catch (PDOException $e)` 僅捕獲 code 23000/1555，**其他 PDOException 直接 `throw $e`** → 若無全域錯誤處理器，堆疊追蹤、SQL 內容可能外洩到使用者介面 | **需修正** — 需確認全域錯誤處理器存在，或在 catch 區塊記錄錯誤後轉向通用錯誤頁 |
| SEC-05 | 低 | 審計日誌不完整 | category_budget.php:69, 154-158 | create 成功時記錄 `logAudit('category_budget_create', ...)`；**唯一衝突失敗時（應用層檢查、DB 唯一約束）皆無審計記錄** | **需補強** — 失敗嘗試應記錄審計（action: `category_budget_create_failed`） |
| SEC-06 | 低 | CSRF 保護順序 | category_budget.php:40-43 | `checkCsrf()` 在 try-catch 之前，**正確** | **通過** |
| SEC-07 | 低 | 資料庫鎖爭用/效能 | Design.md §非功能性需求、db.php:241-248 | 新增唯一索引 `uq_category_budgets_ledger_category_active (ledger_id, category, is_active)` — 索引極小（三欄位），WAL 模式 + `busy_timeout=5000`，**無顯著鎖爭用風險**；現有索引 `idx_category_budgets_ledger_category` 保留，查詢計畫不受負面影響 | **通過** — 風險極低 |
| SEC-08 | 低 | IDOR/權限繞過 | category_budget.php:20-31, 59-63 | 所有查詢皆帶 `ledger_id = :ledger_id` 且先驗證 `INNER JOIN ledger_access role='owner'`，**無 IDOR 風險**；唯一索引不改變授權邏輯 | **通過** |
| SEC-09 | 低 | SQL Injection | category_budget.php:59-68, 148-153 | 所有查詢使用 PDO Prepared Statements + 具名佔位符，**無注入風險** | **通過** |
| SEC-10 | 低 | XSS | category_budget.php:180-185, 190-212 | 輸出皆經 `h()` (`htmlspecialchars` + `ENT_QUOTES | ENT_SUBSTITUTE`) 轉義，**無 XSS 風險** | **通過** |

---

## 詳細分析

### SEC-01：唯一約束錯誤訊息洩露業務資訊（低風險）

**位置**：Design.md §3.3 錯誤處理設計、category_budget.php:157-158（預計實作）

**現況/設計**：
- 應用層檢查失敗（第 59-63 行）：`SELECT ... WHERE ledger_id = :ledger_id AND category = :category AND is_active = 1` → redirect「該類別已有啟用中的預算，請編輯現有記錄。」
- DB 唯一約束失敗捕獲（設計第 157-158 行）：相同訊息

**威脅模型**：
- 攻擊者為合法登入使用者（擁有某帳本 owner 權限）
- 透過嘗試新增預算，觀察是否收到「該類別已有啟用中的預算」錯誤
- 可推測該帳本下哪些類別已設定啟用中預算

**影響評估**：
- 需先取得帳本 owner 權限（`ledger_access.role='owner'`），非匿名可利用
- 類別名稱來自全站 `categories` 表，非機密資料
- 「啟用中預算存在與否」屬業務資訊，非高敏感度個資/金鑰

**建議緩解**：
1. 統一錯誤訊息為較通用：「此類別預算已存在，請編輯現有記錄。」——移除「啟用中」細節
2. 或：在應用層檢查階段即攔截，DB 唯一約束失敗視為極少見競態，統一導向相同通用訊息（設計已一致）

---

### SEC-02：應用層檢查同樣洩露（低風險，現況可接受）

**位置**：category_budget.php:59-63

**分析**：
- 既有程式碼在唯一索引新增前，已有應用層 `SELECT ... is_active = 1` 檢查
- 該檢查同樣讓使用者得知「該類別是否有啟用中預算」
- 新增唯一索引**不增加**新的洩露面，僅在競態條件下由 DB 而非應用層攔截

**結論**：既有風險，非本任務引入。若需修正應在獨立任務統一處理所有類別預算查詢的資訊洩露。

---

### SEC-03：權限檢查順序（通過）

**位置**：category_budget.php:7-31（登入+授權）、38-43（CSRF）

**程式碼順序**：
```php
requireLogin();                                    // 第 7 行
$user = currentUser();                             // 第 8 行
$pdo  = getPdo();                                  // 第 9 行
// ... 解析 ledger_id ...
$stmt = $pdo->prepare('SELECT ... INNER JOIN ledger_access ... role='owner' ...'); // 第 20-26 行
$stmt->execute(...);                               // 第 27 行
$ledger = $stmt->fetch();                          // 第 28 行
if ($ledger === false || $ledger['role'] !== 'owner') { redirect(...); } // 第 29-30 行

// POST 處理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {       // 第 38 行
    $action = $_POST['action'] ?? '';              // 第 39 行
    $csrfRaw = $_POST['csrf_token'] ?? '';         // 第 40 行
    if (!checkCsrf(...)) { redirect(...); }        // 第 41-43 行
    // ... try-catch 區塊在第 57 行之後 ...
}
```

**結論**：
- 登入檢查、授權檢查、CSRF 驗證**全部在 try-catch 之前**
- 權限邊界在資料庫寫入操作前已建立，**通過**

---

### SEC-04：PDOException 詳情可能外洩（中風險，需修正）

**位置**：category_budget.php:155-160（設計片段）

**設計片段**：
```php
try {
    $stmt->execute([...]);
    logAudit('category_budget_create', ...);
} catch (PDOException $e) {
    if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1555) {
        redirect(...友善錯誤...);
    }
    throw $e;  // ⚠️ 其他 PDOException 直接拋出
}
```

**風險**：
- 若專案**無全域例外處理器**（如 `set_exception_handler`），PHP 預設會輸出堆疊追蹤、SQL 語句、參數值到瀏覽器
- 即使有全域處理器，`throw $e` 保留原始堆疊，錯誤頁可能洩露資料庫結構、SQL 片段

**現況檢查**：專案根目錄無明顯 `set_exception_handler` 設定檔（需確認 `functions.php` 或共用引入檔）

**建議修正**：
1. 在 `catch` 區塊記錄錯誤細節到 `error_log` 或 `audit_log`（含 SQL、參數、堆疊）
2. 統一導向通用錯誤頁（如 `./error.php?code=db_error`），不拋出原始例外
3. 或確認專案已有全域錯誤處理器並設定 `display_errors=Off`（生產環境）

---

### SEC-05：審計日誌缺失失敗嘗試記錄（低風險，需補強）

**位置**：category_budget.php:69（成功）、154-158（失敗無記錄）

**現況**：
- 成功 create：`logAudit('category_budget_create', 'ledger_id=... category=... budget_cents=...')`
- 應用層檢查失敗（第 61-62 行）：redirect，**無 audit_log**
- DB 唯一約束失敗（設計第 157-158 行）：redirect，**無 audit_log**

**威脅模型**：
- 惡意/誤操作使用者反覆嘗試建立重複預算
- 無審計記錄 → 無法追蹤異常行為、無法偵測自動化腳本濫用

**建議補強**：
```php
// 應用層檢查失敗
if ($chk->fetch()) {
    logAudit('category_budget_create_failed', 'ledger_id=' . $ledgerId . ' category=' . $category . ' reason=duplicate_active');
    redirect(...);
}

// DB 唯一約束失敗
catch (PDOException $e) {
    if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1555) {
        logAudit('category_budget_create_failed', 'ledger_id=' . $ledgerId . ' category=' . $category . ' reason=unique_constraint');
        redirect(...);
    }
    // ...
}
```

---

### SEC-06：CSRF 保護順序（通過）

**位置**：category_budget.php:40-43

`checkCsrf()` 在 `$action === 'create'` 分支之前、try-catch 之前，**正確**。

---

### SEC-07：資料庫鎖爭用與查詢效能（通過）

**分析**：
- 索引定義：`CREATE UNIQUE INDEX uq_category_budgets_ledger_category_active ON category_budgets(ledger_id, category, is_active)`
- 三欄位組合，資料量極小（類別預算表通常 < 100 列/帳本）
- SQLite WAL 模式：讀寫並行，`busy_timeout=5000` 等待鎖釋放
- 唯一索引由資料庫引擎原子檢查，**無應用層競態條件**
- 現有索引 `idx_category_budgets_ledger_category (ledger_id, category)` 保留，**查詢計畫不變差**
- 唯一索引最左前綴 `(ledger_id, category)` 可服務既有查詢，**效能中性或正面**

**結論**：風險極低，**通過**。

---

### SEC-08：IDOR/權限繞過（通過）

**分析**：
- 所有查詢（SELECT/INSERT/UPDATE）皆帶 `ledger_id = :ledger_id` 參數
- 頁面進入點即驗證 `INNER JOIN ledger_access la ON ... WHERE l.id = :ledger_id AND la.user_id = :user_id AND la.role = 'owner'`
- 唯一索引**不改變**授權邏輯，僅在資料庫層面強制資料完整性
- 無 IDOR 風險，**通過**。

---

### SEC-09：SQL Injection（通過）

**分析**：
- 所有查詢使用 `$pdo->prepare()` + 具名佔位符 (`:ledger_id`, `:category`, `:budget` 等)
- `db.php` 設定 `PDO::ATTR_EMULATE_PREPARES = false`（真正預備陳述式）
- **通過**。

---

### SEC-10：XSS（通過）

**分析**：
- 所有動態輸出經 `h()` 函數：`htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
- 錯誤/成功訊息透過 `urlencode()` 於 URL 傳遞，顯示時經 `h($_GET['msg'] ?? '')`
- **通過**。

---

## 修正建議優先級

| 優先級 | 編號 | 建議動作 | 負責階段 |
|---|---|---|---|
| **P1（必修）** | SEC-04 | 捕獲所有 PDOException 記錄錯誤細節，導向通用錯誤頁，不 `throw $e` 洩露堆疊 | 實作階段 |
| **P2（建議）** | SEC-01 | 統一錯誤訊息移除「啟用中」細節：`「此類別預算已存在，請編輯現有記錄。」` | 實作階段 |
| **P2（建議）** | SEC-05 | 於應用層檢查失敗、DB 唯一約束失敗時，寫入 `audit_log`（action: `category_budget_create_failed`，含 reason） | 實作階段 |
| **P3（可選）** | SEC-02 | 全站統一類別預算查詢的資訊洩露處理（獨立任務） | 後續任務 |

---

## 審查結論

| 階段 | 結論 | 備註 |
|---|---|---|
| **Security 審查** | **條件通過** | 需於實作階段修正 SEC-04（必修）、SEC-01/SEC-05（建議）；其餘項目通過 |

> **簽署**：Security Subagent  
> **日期**：2026-11-25  
> **下一步**：實作階段請依修正建議更新 `category_budget.php`，完成後進入實作後 Code/Security 複查。