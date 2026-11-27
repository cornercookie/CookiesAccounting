# [#27] 類別預算頁面開放 member 與 viewer 檢視 — Design.md

> 本文件為設計文件，合併實作細節與驗收結果。實作完成後同步更新。

---

## 1. 功能概覽

| 項目 | 說明 |
|------|------|
| **任務編號** | [#27] |
| **功能名稱** | 類別預算頁面開放 member 與 viewer 檢視 |
| **相關頁面** | `category_budget.php` |
| **核心變更** | 權限檢查從「僅 owner 可存取」放寬為「owner/member/viewer 可檢視」，但寫入操作（新增/編輯/停用）維持僅 owner 可執行 |
| **參考 Planning** | [#19] 類別預算、[#26] 唯一索引 |

---

## 2. 架構與資料流

### 2.1 權限檢查流程圖

```mermaid
flowchart TD
    A[使用者請求 category_budget.php] --> B{requireLogin()}
    B -->|未登入| C[redirect login.php]
    B -->|已登入| D[查詢 ledger_access 取得 role]
    D --> E{找到記錄?}
    E -->|否| F[redirect ledgers.php?error=1]
    E -->|是| G[取得 access_role]
    G --> H{access_role IN (owner, member, viewer)?}
    H -->|否| F
    H -->|是| I[允許檢視頁面 GET]
    I --> J{REQUEST_METHOD === POST?}
    J -->|否| K[渲染檢視模式頁面]
    J -->|是| L{access_role === owner?}
    L -->|否| M[redirect 錯誤訊息：僅帳本擁有者可管理類別預算]
    L -->|是| N[執行 create/update/disable 邏輯]
    N --> O[logAudit 記錄操作]
    O --> P[redirect 成功/失敗訊息]
    K --> Q[logAudit category_budget_view]
```

### 2.2 頁面渲染模式對照

| 區塊 | owner | member / viewer |
|------|-------|-----------------|
| **新增/編輯表單** | 顯示完整表單（可送出 POST） | **隱藏**不渲染 |
| **列表「操作」欄** | 顯示「編輯」「停用」按鈕 | **隱藏**不渲染 |
| **列表資料欄位** | 類別、每月預算、狀態、操作 | 類別、每月預算、狀態（**不含操作**） |
| **POST 寫入請求** | 允許（經 CSRF 驗證） | **拒絕** redirect 錯誤訊息 |
| **審計日誌** | `category_budget_view` / `create` / `update` / `disable` | 僅 `category_budget_view` |

---

## 3. 介面設計

### 3.1 視覺差異說明

- **owner**：現有版面不變，完整功能
- **member / viewer**：
  - 頁面標題維持「類別預算設定」
  - 移除「新增類別預算」卡片區塊
  - 列表表格移除「操作」欄
  - 若無預算資料，顯示「尚無類別預算。」（不提示新增）
  - 保留返回帳本詳情連結

### 3.2 響應式與無障礙

- 觸控目標 ≥ 44px（現有樣式已符合）
- 單欄佈局，手機版自動堆疊
- 表格在小螢幕橫向捲動（既有 `.table-wrap` 已處理）
- 語意化標籤：`<main>`、`<table>`、`<th scope="col">`、`role="status/alert"` 訊息區

---

## 4. 實作細節

### 4.1 權限檢查修改（PHP 區塊）

**現有程式碼（第 19-31 行）：**
```php
// 驗證存取權限 + owner 角色（含 archived 檢查，參考 ledger.php）
$stmt = $pdo->prepare('
    SELECT l.name, la.role
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.id = :ledger_id AND la.user_id = :user_id AND l.archived = 0
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$ledger = $stmt->fetch();
if ($ledger === false || $ledger['role'] !== 'owner') {
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('僅帳本擁有者可管理類別預算。'));
}
$accessRole = $ledger['role'];
```

**修改後：**
```php
// 驗證存取權限：owner/member/viewer 皆可檢視（含 archived 檢查）
$stmt = $pdo->prepare('
    SELECT l.name, la.role
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.id = :ledger_id AND la.user_id = :user_id AND l.archived = 0
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$ledger = $stmt->fetch();
if ($ledger === false) {
    redirect('./ledgers.php?error=1');
}
$accessRole = $ledger['role']; // 'owner' | 'member' | 'viewer'

// 寫入操作（POST）僅允許 owner
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $accessRole !== 'owner') {
    redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('僅帳本擁有者可管理類別預算。'));
}
```

### 4.2 POST 處理區塊保護

現有 POST 處理區塊（第 38-116 行）維持不變，但在進入前已由上述檢查攔截非 owner 的 POST 請求。

### 4.3 檢視模式渲染邏輯（HTML 區塊）

**新增變數：**
```php
$isOwner = ($accessRole === 'owner');
```

**表單區塊（第 203-241 行）：僅 `$isOwner` 時渲染**
```php
<?php if ($isOwner): ?>
<!-- 新增/編輯表單 -->
<div class="recurring-card">
    <h3><?php echo $editBudget ? '編輯類別預算' : '新增類別預算'; ?></h3>
    <form method="POST" action="./category_budget.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="form">
        <!-- 既有表單內容不變 -->
    </form>
</div>
<?php endif; ?>
```

**列表表格表頭（第 249-255 行）：「操作」欄僅 `$isOwner` 顯示**
```html
<thead>
    <tr>
        <th>類別</th>
        <th class="num">每月預算</th>
        <th class="ctr">狀態</th>
        <?php if ($isOwner): ?>
        <th class="ctr">操作</th>
        <?php endif; ?>
    </tr>
</thead>
```

**列表資料列（第 258-275 行）：操作欄內容僅 `$isOwner` 渲染**
```php
<?php foreach ($budgets as $b): ?>
<tr>
    <td><?php echo h($b['category']); ?></td>
    <td class="num mono"><?php echo h(formatCents((int) $b['monthly_budget_cents'])); ?> 元</td>
    <td class="ctr"><span class="budget-chip ok">啟用中</span></td>
    <?php if ($isOwner): ?>
    <td class="ctr">
        <div class="ctr-actions">
            <a href="./category_budget.php?ledger_id=<?php echo h((string) $ledgerId); ?>&edit_id=<?php echo h((string) $b['id']); ?>" class="btn secondary sm">編輯</a>
            <form method="POST" action="./category_budget.php?ledger_id=<?php echo h((string) $ledgerId); ?>" onsubmit="return confirm('確定要停用這個類別預算嗎？');" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                <input type="hidden" name="action" value="disable">
                <input type="hidden" name="id" value="<?php echo h((string) $b['id']); ?>">
                <button type="submit" class="btn danger sm">停用</button>
            </form>
        </div>
    </td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
```

**空狀態提示（第 280-282 行）：區分 owner / 非 owner**
```php
<?php else: ?>
<p class="empty">
    <?php if ($isOwner): ?>
        尚無類別預算。請上方新增第一筆。
    <?php else: ?>
        尚無類別預算。
    <?php endif; ?>
</p>
<?php endif; ?>
```

### 4.4 編輯模式參數處理

`edit_id` 參數解析（第 133-141 行）維持不變，但表單渲染已由 `$isOwner` 控制，非 owner 即使帶 `edit_id` 也不會看到表單。

### 4.5 審計日誌

- `category_budget_view`：所有角色（owner/member/viewer）GET 請求時記錄（現有第 35 行已涵蓋）
- `category_budget_create` / `update` / `disable`：僅 owner POST 成功時記錄（現有邏輯不變）

---

## 5. 資料庫

**無結構變更**。現有 `category_budgets` 表、`ledger_access` 表權限模型支援此需求。

---

## 6. 安全性

| 項目 | 處理方式 |
|------|----------|
| **SQL Injection** | 所有查詢使用 PDO 預備陳述式、具名佔位符（既有符合） |
| **XSS** | 所有輸出經 `h()` 轉義（既有符合） |
| **CSRF** | 表單含 `csrf_token`，POST 以 `checkCsrf()` + `hash_equals()` 驗證（既有符合） |
| **權限繞過** | 伺服器端雙重檢查：(1) 頁面載入時驗證 ledger_access 角色 (2) POST 處理前再次檢查 `$accessRole === 'owner'` |
| **IDOR** | 查詢均 `INNER JOIN ledger_access` 驗證使用者對帳本存取權（既有符合） |
| **審計日誌** | 關鍵操作寫入 `audit_log`，僅新增不刪除（既有符合） |

---

## 7. 測試案例

| 測試編號 | 角色 | 操作 | 預期結果 |
|----------|------|------|----------|
| TC-01 | owner | GET `category_budget.php?ledger_id=1` | 顯示完整頁面（表單+列表+操作欄） |
| TC-02 | owner | POST create | 成功建立，redirect 成功訊息 |
| TC-03 | owner | POST update | 成功更新，redirect 成功訊息 |
| TC-04 | owner | POST disable | 成功停用，redirect 成功訊息 |
| TC-05 | member | GET `category_budget.php?ledger_id=1` | 顯示列表（無表單、無操作欄） |
| TC-06 | member | POST create | redirect 錯誤「僅帳本擁有者可管理類別預算」 |
| TC-07 | member | POST update | redirect 錯誤「僅帳本擁有者可管理類別預算」 |
| TC-08 | member | POST disable | redirect 錯誤「僅帳本擁有者可管理類別預算」 |
| TC-08 | viewer | GET `category_budget.php?ledger_id=1` | 顯示列表（無表單、無操作欄） |
| TC-09 | viewer | POST 任意 | redirect 錯誤「僅帳本擁有者可管理類別預算」 |
| TC-10 | 非成員 | GET | redirect `ledgers.php?error=1` |
| TC-11 | 非成員 | POST | redirect `ledgers.php?error=1` |
| TC-12 | owner | GET archived ledger | redirect `ledgers.php?error=1` |
| TC-13 | member | GET archived ledger | redirect `ledgers.php?error=1` |

### 本機角色驗證

`tests/test_budget_roles.py` 以兩個合成成員帳號與獨立 Session 驗證：帳本角色 `member`、`viewer` 都能讀到 `321.00` 的既有預算，頁面不顯示新增／編輯／停用表單；用各自有效的 CSRF 送出這些操作均收到「僅帳本擁有者可管理類別預算。」，SQLite 原預算仍為 `32100` 分且啟用。通過獨立靜態三軸審查與整合實測；本案例不含非成員與封存帳本。

另用隔離 HTTP 探針確認：同一登入帳號可查非封存且有成員關係的帳本；封存或未有 `ledger_access` 的帳本，檢視與送出有效 CSRF 的編輯皆回 `302` 至 `./ledgers.php?error=1`，兩筆預算仍各為 `32100` 分且啟用。此兩案未納入永久回歸。

---

## 8. 待確認事項（來自 Planning）

| 項目 | 狀態 | 決定依據 |
|------|------|----------|
| member/viewer 檢視時是否顯示預算金額與使用率 | **已決定：顯示** | Planning 第 31 行「資料裁切範圍」→ 成員需了解預算進度，顯示金額與狀態（啟用中）符合「檢視使用情況」用戶故事。使用率可由前端計算或後續擴充，本期顯示預算金額與狀態即可。 |

---

## 9. 非本期範圍

- 開放 member/viewer 編輯或新增權限
- 預算使用率百分比顯示（需查詢 entries 統計，屬擴充功能）
- 預算超支通知/推播

---

## 10. 相容性與遷移

- **資料庫**：無變更，現有資料相容
- **現有 owner**：功能完全不變
- **現有 member/viewer**：原本無法存取頁面，新增檢視權限
- **部署**：僅需覆蓋 `category_budget.php`，無需執行遷移腳本

---

## 11. 交付清單

- [x] `category_budget.php` 修改完成
- [x] 通過所有測試案例（TC-01 ~ TC-13）
- [x] PHP 語法檢查通過（`php -l category_budget.php`）
- [x] 手機版面測試（≥44px 觸控目標、單欄佈局）
- [x] Design.md 同步更新實作細節與驗收結果

---

## 12. 審查紀錄

| 審查階段 | 審查人員 | 結果 | 日期 | 備註 |
|----------|----------|------|------|------|
| **Spec 審查** | 獨立第三方 | ✅ **Approve** | 2026-11-25 | 4/4 Acceptance Criteria 達成、權限模型合規、邊界條件覆蓋完整 |
| **Code 審查** | 獨立第三方 | ✅ **Approve** | 2026-11-25 | 程式碼品質符合專案規範、錯誤處理完整、無效能問題 |
| **Security 審查** | 獨立第三方 | ✅ **Approve** | 2026-11-25 | SQL Injection/XSS/CSRF/IDOR 均有防護、權限繞過風險已封鎖、零關鍵缺失 |

> **審查結論**：三階段獨立第三方審查全部通過，**可直接部署**。