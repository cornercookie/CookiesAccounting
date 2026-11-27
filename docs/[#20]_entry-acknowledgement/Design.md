# Design & Implementation: 記錄確認/認領機制 ([#20])

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 名稱 | 記錄確認/認領機制設計與實作 |
| 版本 | v1.1 |
| 建立日期 | 2026-11-25 |
| 更新日期 | 2026-11-27 |
| 任務編號 | [#20] |
| 變更摘要 | v1.1 補記確認／爭議與稽核的隔離回歸；不變更實作契約。 |

## 來源規劃

- 規劃文件：`docs/[#20]_entry-acknowledgement/planning.md`（v1.0，2026-11-22）
- 任務編號：`[#20]`

## 1. 變更概述

分錄記錄預設標記為「待確認」，其他成員可點「我確認這筆是我花的」或「這筆不是我花的」，記錄確認者與時間，避免共同帳本中「這筆是誰花的？」爭議。

本次實作新增：
- `entries` 表三個欄位：`ack_status`、`ack_user_id`、`ack_at`
- 新頁面 `entry_ack.php`（POST）：處理確認/爭議動作
- 修改 `ledger.php`：分錄表格顯示確認狀態標籤（chip）與確認/爭議按鈕
- 稽查日誌：新增 `entry_confirm`、`entry_dispute` 動作代碼

## 2. 資料庫與架構變更

### 2.1 Schema Migration（entries 表）

在 `entries` 資料表新增三個欄位，並遵循 `db.php::ensureSchema()` 的冪等性守衛模式（先檢查欄位是否存在，再 `ALTER TABLE ADD COLUMN`），與既有 `receipt_path`/`recurring_source_id` 遷移保持相同模式：

| 欄位 | 型別 | 約束 | 說明 |
| --- | --- | --- | --- |
| `ack_status` | TEXT | NOT NULL DEFAULT 'pending', CHECK IN ('pending','confirmed','disputed') | 確認狀態：待確認/已確認/有爭議 |
| `ack_user_id` | INTEGER | NULLABLE, FK→users.id | 確認者使用者 ID |
| `ack_at` | TEXT | NULLABLE | 確認時間，格式 `datetime('now','localtime')` |

索引：
- `idx_entries_ack_status` (ack_status) — 供狀態篩選查詢

遷移程式碼模式（置於 `db.php::ensureSchema()`，三欄與索引各自以 `PRAGMA table_info` 獨立檢查後再 ALTER，與 db.php 現有 `receipt_path`/`recurring_source_id` 遷移模式一致，避免部分遷移導致後續執行跳過所有 ALTER）：

```php
$entriesCols = $pdo->query('PRAGMA table_info(entries)')->fetchAll(PDO::FETCH_ASSOC);
$has = [];
foreach ($entriesCols as $col) {
    $has[($col['name'] ?? '')] = true;
}

if (!isset($has['ack_status'])) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN ack_status TEXT NOT NULL DEFAULT 'pending' CHECK (ack_status IN ('pending','confirmed','disputed'))");
}
if (!isset($has['ack_user_id'])) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN ack_user_id INTEGER");
}
if (!isset($has['ack_at'])) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN ack_at TEXT");
}

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_entries_ack_status ON entries(ack_status)");
```

### 2.2 無新增資料表

本期不新增獨立確認歷史表（詳見 §8 非本期範圍），確認變更僅寫入 `entries` 三欄位與 `audit_log`。

## 3. 介面契約（entry_ack.php）

| 項目 | 內容 |
| --- | --- |
| 請求方法 | POST |
| 端點路徑 | `./entry_ack.php` |
| CSRF | 必須，以 `checkCsrf()` `hash_equals()` 驗證 |
| 身分驗證 | `requireLogin()` |

### 3.1 請求欄位

| 欄位 | 型別 | 說明 |
| --- | --- | --- |
| `entry_id` | INTEGER | 欲確認或爭議的分錄記錄 id |
| `action` | TEXT | 動作類型，`confirm`（確認）或 `dispute`（爭議） |
| `csrf_token` | STRING | CSRF 權杖，以 `hash_equals()` 驗證 |

### 3.2 權限檢查順序

1. `requireLogin()` — 未登入導向 `login.php`
2. 查詢 `entries` 取得 `ledger_id`、`user_id`（記錄建立者）；記錄不存在或已軟刪除（`deleted = 1`）→ 302 重導回分錄列表頁，顯示「找不到該記錄」
3. `INNER JOIN ledger_access` 驗證目前使用者對該 `ledger_id` 有存取權；無記錄 → 302 重導回分錄列表頁，顯示「您沒有權限變更此記錄的確認狀態」
4. 驗證操作者是否為記錄建立者（`entries.user_id = :userId`）或帳本 `ledger_access.role = 'owner'`；不符合 → 302 重導回分錄列表頁，顯示「您沒有權限變更此記錄的確認狀態」
5. 驗證 `action` 必須為 `confirm` 或 `dispute`；不符合 → 302 重導回分錄列表頁，顯示錯誤訊息
6. 執行 `UPDATE entries SET ack_status = :status, ack_user_id = :userId, ack_at = datetime('now','localtime') WHERE id = :entry_id AND ledger_id = :ledger_id AND deleted = 0`
7. `logAudit('entry_confirm' / 'entry_dispute', ...)`
8. 重導回 `./ledger.php?ledger_id=<ledger_id>&success=1&msg=...`（PRG 模式）

> 所有錯誤情境皆採 302 重導回分錄列表頁顯示錯誤訊息（與已確認決策 #1 一致），不回傳 JSON，不使用 403 狀態碼。

### 3.3 回應與狀態碼

| 情境 | HTTP 狀態碼 | 行為 |
| --- | --- | --- |
| 成功 | 302 | 重導回分錄列表頁，顯示成功訊息 |
| CSRF 驗證失敗 | 302 | 重導回分錄列表頁，顯示「安全驗證失敗，請重新操作」 |
| 權限不足 | 302 | 重導回分錄列表頁，顯示「您沒有權限變更此記錄的確認狀態」 |
| 記錄不存在 | 302 | 重導回分錄列表頁，顯示「找不到該記錄」 |
| 資料庫寫入失敗 | 302 | 302 重導回分錄列表頁，顯示通用錯誤訊息；詳細錯誤寫入 `private_data/error.log` |

> 決定：錯誤情境皆採「導回列表頁顯示錯誤訊息」（302 重導 + 查詢參數訊息），與專案現有模式（如 `ledger.php` set_budget）一致；不回傳 JSON。此為 Planning「待開發者決定的事項」之已確認決策。
>
> **狀態碼註釋**：planning.md §介面契約列出 HTTP 狀態碼 200/400/403/500，已依已確認決策 #1 統一為 302 重導（取代舊文的 200/400/403/500），不回傳 JSON。此為釐清文件，不新增規格。

## 4. 資料流與實作路徑

### 4.1 資料流圖

```mermaid
flowchart TD
    A[使用者於分錄列表點擊「確認」或「爭議」] --> B[POST ./entry_ack.php 含 CSRF 權杖]
    B --> C[requireLogin 驗證登入]
    C --> Cs[checkCsrf 驗證 CSRF 權杖；失敗→302 重導列表頁顯示錯誤訊息]
    Cs --> D[查詢 entries 取得 ledger_id、user_id；記錄不存在或已軟刪除→302 重導列表頁顯示「找不到該記錄」]
    D --> E[INNER JOIN ledger_access 驗證帳本存取權；無記錄→302 重導列表頁顯示「您沒有權限變更此記錄的確認狀態」]
    E --> F{操作者 = 記錄建立者 或 帳本 owner?}
    F -->|否| G[302 重導列表頁顯示「您沒有權限變更此記錄的確認狀態」]
    F -->|是| H[PDO UPDATE entries 設定 ack_status/ack_user_id/ack_at，WHERE deleted = 0]
    H --> I[audit_log 記錄 entry_confirm/entry_dispute]
    I --> J[302 重導回 ledger.php 顯示狀態標籤 h() 轉義]
```

### 4.2 實作路徑

1. **`db.php`**：`ensureSchema()` 新增 entries 三欄位與索引（冪等守衛）
2. **`entry_ack.php`**：新頁面，處理 POST 確認/爭議
3. **`ledger.php`**：
   - 分錄查詢 SELECT 加入 `e.ack_status, e.ack_user_id, e.ack_at`
   - 表格新增「確認狀態」欄位，顯示 chip（待確認/已確認/有爭議）
   - 對可寫入記錄（記錄建立者或帳本 owner）顯示確認/爭議按鈕表單
   - 檢視者（viewer）不顯示按鈕，僅顯示狀態 chip

### 4.3 ledger.php 表格欄位調整

現有表頭：編號、日期、成員、類別、金額、收據、備註、操作

新增表頭（在備註後、操作前）：確認狀態

| 欄位 | 顯示內容 | 條件 |
| --- | --- | --- |
| 確認狀態 | chip 顯示「待確認」「已確認」「有爭議」 | 所有使用者皆可見 |
| 確認操作 | 「確認」/「爭議」按鈕表單 | 僅記錄建立者或帳本 owner 且 $canWrite |

## 5. UI/UX 設計（設計系統規範）

### 5.1 確認狀態標籤（chip）

依設計系統色彩與晶片元件規範，新增三種確認狀態晶片：

| 狀態 | 中文名稱 | 晶片底色 | 晶片文字 | 用途 |
| --- | --- | --- | --- | --- |
| `pending` | 待確認 | `#efece0`（暗米） | `#6b675a`（輔助灰） | 預設狀態，尚無人確認 |
| `confirmed` | 已確認 | `#eaf1e6`（成功底） | `#1d4a24`（成功字） | 確認為本人花費 |
| `disputed` | 有爭議 | `#f6e3df`（危險底） | `#7a241a`（危險字） | 確認非本人花費 |

CSS 類別：`.ack-pending`、`.ack-confirmed`、`.ack-disputed`，圓角 2px，Courier 等寬字型，字級 0.875rem，最小高度 32px，桌機 ≥44px 觸控目標。

### 5.2 確認/爭議按鈕

在分錄列表操作欄（`.ctr-actions`）中，對符合權限的記錄顯示兩個小按鈕：

- 「確認」：`.btn secondary sm`，提交 `action=confirm`
- 「爭議」：`.btn danger sm`，提交 `action=dispute`，表單加 `onsubmit="return confirm('確定要標記為有爭議嗎？');"`（與作廢確認對話框一致）

所有按鈕最小高度 44px（手機），表單內含 `csrf_token` 隱藏欄位。

### 5.3 響應式

≤720px 手機：確認狀態 chip 與按鈕維持單欄，觸控目標 ≥44px；不隱藏確認狀態欄（核心資訊）。

## 6. 權限控管

| 操作 | 允許角色 | 檢查依據 |
| --- | --- | --- |
| 檢視確認狀態 chip | 所有可存取帳本角色 | ledger_access 存在 |
| 執行確認/爭議 | 記錄建立者（entries.user_id）或帳本 owner | entry_ack.php 驗證 |
| 檢視者（viewer） | 僅可見 chip，無按鈕 | $canWrite = false，不顯示操作 |

IDOR 防護：entry_ack.php 所有查詢與寫入皆 `INNER JOIN ledger_access` 或以 `ledger_id` 條件限定，並與目前使用者綁定。

> 授權規則註釋：以 planning.md §預期行為第 6 點為準——僅記錄建立者本人或帳本擁有者/管理員（ledger_access.role='owner'）可變更確認狀態。Planning §預期行為第 3 點「member/擁有者可對該筆記錄執行確認或爭議動作」以第 6 點為準，不新增規格。

## 7. 審計日誌

| 動作 | action 代碼 | detail 範例 |
| --- | --- | --- |
| 確認記錄 | `entry_confirm` | `entry #123 · ledger 4 · ack_status=confirmed` |
| 爭議記錄 | `entry_dispute` | `entry #123 · ledger 4 · ack_status=disputed` |

所有關鍵操作寫入 `audit_log` 表，僅新增不刪除（符合專案審計原則）。

## 8. 非本期範圍（Out of Scope）

- 確認通知：本期僅於網頁顯示狀態，不發送電子郵件或推播通知。
- 確認歷史紀錄：本期僅顯示目前狀態，不保留確認變更歷史。
- 批次確認：本期僅支援單筆確認，不支援一次選擇多筆記錄確認。

## 9. 非功能性需求

| 類別 | 需求 | 實作方式 |
| --- | --- | --- |
| 預期效能 | 確認動作為單筆 UPDATE，回應時間在可接受範圍內 | PDO 預備陳述式，單筆主鍵查詢 |
| 併發處理量 | 單筆 UPDATE 操作 | SQLite WAL 模式 + `busy_timeout=5000` |
| 資料保留策略 | `ack_status` 與 `ack_at` 隨 entries 記錄生命週期保留 | 不額外歸檔 |
| 系統可用性 | 確認失敗時顯示錯誤訊息，不影響分錄列表檢視 | 302 重導 + 錯誤訊息，主流程不中斷 |

## 10. 錯誤處理與邊界條件

| 場景 | 處理方式 | 使用者回饋 |
| --- | --- | --- |
| CSRF 權杖驗證失敗 | `checkCsrf()` 失敗，拒絕請求 | 重導列表頁顯示「安全驗證失敗，請重新操作」 |
| 權限不足 | 非建立者且非帳本 owner | 重導列表頁顯示「您沒有權限變更此記錄的確認狀態」 |
| 重複確認 | 同一記錄可多次變更狀態，每次寫入 audit_log | 不阻止重複操作 |
| 資料庫寫入失敗 | PDO Exception 捕捉，寫入 error.log | 重導列表頁顯示通用錯誤訊息；詳細錯誤寫入 error.log |
| 記錄不存在或已軟刪除 | 查無 entries 記錄（含已軟刪除 deleted=1） | 重導列表頁顯示「找不到該記錄」 |
| 非 POST 請求 | 僅接受 POST | 302 重導回列表頁顯示錯誤訊息 |

## 11. 驗證與測試計畫

- [ ] 分錄列表每行顯示確認狀態標籤（待確認/已確認/有爭議）
- [ ] 記錄建立者可於列表看到「確認」/「爭議」按鈕並成功提交
- [ ] 非建立者（僅 member）不看到「確認」/「爭議」按鈕（對他人建立的記錄）
- [ ] 帳本 owner 可對他人建立的記錄執行確認/爭議（授權規則與建立者相同）
- [ ] 檢視者（viewer）不看到按鈕，僅見狀態 chip
- [ ] 確認後 ack_status 更新為 confirmed、ack_user_id 為目前使用者、ack_at 有值
- [ ] 爭議後 ack_status 更新為 disputed
- [ ] audit_log 正確記錄 entry_confirm / entry_dispute
- [ ] CSRF 權杖缺失或錯誤時請求被拒絕
- [ ] 未登入者無法存取 entry_ack.php（導向 login.php）
- [ ] 所有輸出經 h() 轉義，無 XSS 風險
- [ ] 資料庫遷移冪等：重複執行 ensureSchema 不報錯

## 12. 實作註記（範圍說明）

- `entry_ack.php` 為獨立新頁面，未整合至 `ledger.php`（Planning 待開發者決定事項，此處採獨立頁面方案，與 `delete_entry.php` 模式一致）
- 錯誤回應皆採 302 重導回列表頁顯示訊息，不回傳 JSON（Planning 待開發者決定事項，此處採與專案現有模式一致的方案）
- 確認狀態標籤中文名稱沿用 Planning 術語：待確認、已確認、有爭議
- 資料庫遷移置於 `db.php::ensureSchema()`，與既有 `receipt_path`/`recurring_source_id` 遷移保持相同冪等模式

## 12.1 實作與設計變更

實作完成日期：2026-11-25

### 12.1.1 db.php
- 在 `ensureSchema()` 新增 entries 三欄位遷移：`ack_status`（TEXT, DEFAULT 'pending', CHECK）、`ack_user_id`（INTEGER）、`ack_at`（TEXT），以及索引 `idx_entries_ack_status`。
- 採單次 `PRAGMA table_info` 查詢後以關聯陣列檢查三欄位是否存在，各自獨立 `ALTER TABLE`，與既有 `receipt_path`/`recurring_source_id` 遷移模式一致，冪等。

### 12.1.2 entry_ack.php（新檔）
- 新增獨立 POST 端點 `./entry_ack.php`，處理確認/爭議動作。
- 入口驗證順序：`requireLogin()` → `checkCsrf()` → `entry_id` 整數驗證 → `action ∈ {confirm,dispute}` 驗證 → 查詢 `entries`（`deleted = 0`）→ `INNER JOIN ledger_access` 檢查帳本存取權 → 驗證操作者為記錄建立者或帳本 owner → `UPDATE entries SET ack_status, ack_user_id, ack_at WHERE deleted = 0` → `rowCount()` 檢查 → `logAudit('entry_confirm' / 'entry_dispute')` → 302 重導回 `ledger.php`。
- 所有錯誤情境皆 302 重導回列表頁顯示錯誤訊息（與決策 #1 一致），不回傳 JSON，不使用 403。

### 12.1.3 ledger.php
- 分錄 SELECT 加入 `e.ack_status, e.ack_user_id, e.ack_at, e.user_id`。
- 表格新增「確認狀態」欄（位於備註後、操作前），顯示 chip：待確認（`ack-pending`）、已確認（`ack-confirmed`）、有爭議（`ack-disputed`）。
- 操作欄改為：對「記錄建立者」或「帳本 owner」顯示確認/爭議表單（POST 至 `entry_ack.php`，含 CSRF、確認對話框），並保留編輯/作廢按鈕；檢視者與無權限者僅顯示 chip 不顯示按鈕。**修正**：實作審查發現原寫法誤用 `$canAck && $canWrite`，已修正為 `$canAck`（確認/爭議權限獨立於帳本寫入權限），符合 Planning 授權規則。

### 12.1.4 assets/style.css
- `:root` 新增 CSS 變數：`--success-bg`、`--success-text`、`--danger-bg`、`--danger-text`（對應設計系統 Success/Danger 狀態色）。
- 新增 `.ack-chip` 基礎樣式與三種狀態類別：`.ack-pending`（暗米底）、`.ack-confirmed`（成功綠底）、`.ack-disputed`（危險紅底）。
- `@media (max-width: 720px)` 新增 `.ack-chip` 字級與間距調整。

## 12.2 實際驗證結果

> 2026-11-27 已補上 PHP 8.4／PDO SQLite 的本機隔離 HTTP 驗證；下表未更新的項目仍僅有 2026-11-25 靜態證據。

| 驗收情境 | 環境、日期與版本 | 實際檢查與結果 | 證據及限制 |
|---|---|---|---|
| 分錄列表顯示確認狀態 chip | 靜態檢查 2026-11-25 | ledger.php SELECT 包含 ack_status/ack_user_id/ack_at；表格渲染 chip，三狀態類別對應 Design §5.1 | 僅靜態確認，需部署環境實測 |
| 記錄建立者可提交確認/爭議 | 本機隔離 HTTP／SQLite，2026-11-27 | 已登入的建立者（同時為帳本 owner）分別提交確認、爭議，SQLite 儲存正確狀態、操作者與時間戳 | `tests/test_entry_ack.py` 驗狀態；獨立查詢另驗操作者與時間戳。未區隔純建立者與 owner 權限，也未實點瀏覽器按鈕。 |
| 帳本 owner 可對他人記錄確認/爭議 | 靜態檢查 2026-11-25 | entry_ack.php `$isOwner = ($accessRole === 'owner')`；ledger.php `$isLedgerOwner = ($accessRole === 'owner')` | 僅靜態確認 |
| 檢視者不顯示按鈕 | 靜態檢查 2026-11-25 | `$canAck` 條件控制按鈕顯示，viewer 為 false（非建立者、非 owner） | 僅靜態確認 |
| CSRF 失敗導回列表頁 | 靜態檢查 2026-11-25 | entry_ack.php `checkCsrf()` 失敗 → 302 重導 ledger.php | 僅靜態確認 |
| 軟刪除記錄不可確認/爭議 | 靜態檢查 2026-11-25 | 查詢與 UPDATE 皆含 `AND deleted = 0` | 僅靜態確認 |
| audit_log 記錄 entry_confirm/entry_dispute | 本機隔離 HTTP／SQLite，2026-11-27 | 兩個 action 各寫入一筆對應分錄的紀錄；獨立查詢核對操作者及完整 detail | `tests/test_entry_ack.py` 通過，新增測試的 Codex Spec／Code／Security 唯讀審查均通過。 |
| 資料庫遷移冪等 | 靜態檢查 2026-11-25 | db.php 三欄各自獨立檢查後 ALTER，重複執行不報錯 | 僅靜態確認 |
| 所有輸出經 h() 轉義 | 靜態檢查 2026-11-25 | ledger.php 表格輸出皆用 h()；entry_ack.php 重導參數用 urlencode | 僅靜態確認 |

> 正式驗證需部署至測試環境（PHP 8.1+ / pdo_sqlite / WAL），執行上述情境並錄影/截圖存證。

## 13. 審查紀錄

| 階段 | 時點 | 複查次數 | 日期 | 審查者 | 方法 | 結論 | 備註 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 設計：Spec  | 設計完成後 | 2（第 2 次複查） | 2026-11-25 | 獨立第三方 | 唯讀比對 Planning、決策 #1、Design；確認 3 項阻塞已關閉，流程圖修正後與決策 #1 一致 | 無阻塞 | 通過 |
| 設計：Code  | 設計完成後 | 2（第 2 次複查） | 2026-11-25 | 獨立第三方 | 唯讀受影響程式；遷移模式與 db.php 一致，步驟序列完整；僅建議（CSRF 步驟未列、rowCount 未檢），無阻塞 | 無阻塞 | 通過 |
| 設計：Security | 設計完成後 | 2（第 2 次複查） | 2026-11-25 | 獨立第三方 | 唯讀檢查授權、IDOR、CSRF、輸出轉義、審計；未引入新風險 | 無阻塞 | 通過 |
| 實作：Spec  | 實作完成後 | 2（第 2 次複查） | 2026-11-25 | 獨立第三方 | 唯讀比對 Planning、Design、實際程式碼；修正 ledger.php 權限判斷後符合所有驗收條件 | 無阻塞（原阻塞已關閉） | 通過 |
| 實作：Code  | 實作完成後 | 2（第 2 次複查） | 2026-11-25 | 獨立第三方 | 唯讀讀取實際差異；ledger.php 單行修正，無副作用、CSRF/輸出轉義完整 | 無阻塞（原阻塞已關閉） | 通過 |
| 實作：Security | 實作完成後 | 2（第 2 次複查） | 2026-11-25 | 獨立第三方 | 唯讀核對授權邊界、CSRF/XSS/IDOR/審計；伺服端授權強制、前端按鈕僅 UX | 無阻塞（原阻塞已關閉） | 通過 |

> 設計階段三階段（Spec/Code/Security）已經獨立第三方複查通過（第 2 次複查），可進入實作階段。實作階段三階段（Spec/Code/Security）亦已經獨立第三方複查通過（第 2 次複查），**交付就緒**。正式驗收需部署測試環境執行 Design §12.2 列出之 13 項驗收情境並保留證據。

## 14. 待開發者確認事項

Planning 中「待開發者決定的事項」已在本 Design 做出決定，供開發者確認：

1. **錯誤情境回應行為**：採「導回列表頁顯示錯誤訊息」（302 重導），與專案現有模式一致；不回傳 JSON。
2. **標籤中文名稱**：待確認、已確認、有爭議（沿用 Planning 術語）。
3. **介面端點**：採獨立 `./entry_ack.php` 頁面，不整合至 `ledger.php`。

---

> 本文件為規劃文件，不完全是實作依據。實作階段補上實際變更、驗證證據與審查紀錄後，再交付。