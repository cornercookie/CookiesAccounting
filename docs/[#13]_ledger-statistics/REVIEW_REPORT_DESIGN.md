# 第三方專家審查報告：[#13]_ledger-statistics Design.md

## 審查角色與範圍
- **審查者**：獨立第三方審查者（非設計者、非實作者）
- **審查對象**：`docs/[#13]_ledger-statistics/Design.md` (v1.0, 2026-11-21)
- **審查依據**：
  - `docs/[#13]_ledger-statistics/planning.md`（需求規劃）
  - 專案 `AGENTS.md` 硬性約束
  - 既有程式碼：`dashboard.php`、`ledger.php`、`functions.php`、`assets/style.css`
  - `dev-workflow` 技能規範的三階段審查標準（Spec / Code / Security）

---

## 一、規格審查 — Spec Review

### 1.1 需求完整性核對

| Planning 需求 | Design.md 覆蓋 | 狀態 |
|-------------|---------------|------|
| Feature 6：成員記帳進度（儀表板） | ✅ 設計概述第 1 項、程式修改路徑 dashboard.php、前端變更 | **通過** |
| Feature 7：預算超支醒目提醒（儀表板＋帳本詳情） | ✅ 設計概述第 2 項、程式修改路徑、預算橫幅細節 | **通過** |
| Feature 9：列印友善月結單 | ✅ 設計概述第 3 項、列印友善月結單細節 | **通過** |
| Feature 10：類別統計圖表 | ✅ 設計概述第 4 項、類別統計 SVG 圖表細節 | **通過** |
| 不新增資料表 | ✅ 資料庫變更章節明確說明 | **通過** |
| 不變更既有權限模型 | ✅ 身分驗證與權限模型章節 | **通過** |

### 1.2 驗收條件一致性

| AC 編號 | Planning 驗收條件 | Design.md 對應 | 差異/風險 |
|---------|------------------|---------------|-----------|
| AC1 | 儀表板顯示各成員本月記帳筆數，與 entries 表一致 | 程式修改路徑、前端變更、預期行為表 | ✅ 一致 |
| AC2 | 本月支出 ≥80% 黃色、≥100% 紅色；未設定預算不顯示 | 預算橫幅計算公式、僅當月顯示 | ✅ 一致 |
| AC3 | 列印時側欄與按鈕隱藏，保留合計牌與分錄表 | @media print 隱藏清單 | ✅ 一致 |
| AC4 | SVG 長條圖、繁體中文標籤、formatCents 格式化 | 類別統計 SVG 圖表章節 | ✅ 一致 |
| AC5 | 不破壞既有權限檢查與整數分金額 | 身分驗證與權限模型、整數分金額 | ✅ 一致 |

### 1.3 待確認事項（Planning → Design 同步）

Planning 第 43 行列出三項待確認，Design.md 第 127-130 行已同步：
- 本期僅顯示「本月」，不支援自訂月份 ✅
- 預算超支橫幅僅在頁面中顯示，不彈出通知 ✅
- 列印月結單不包含簽名欄位 ✅

---

## 二、程式碼審查 — Code Review

### 2.1 與既有實作一致性檢查

#### 2.1.1 成員記帳進度 — dashboard.php 現有實作（第 394-439 行）

**現有程式碼已實作：**
```php
// dashboard.php 第 408-426 行
$memberStmt = $pdo->prepare('
    SELECT u.username, COALESCE(COUNT(e.id), 0) AS entry_count
    FROM ledger_access la
    INNER JOIN users u ON u.id = la.user_id
    LEFT JOIN entries e
        ON e.ledger_id = la.ledger_id
       AND e.user_id = la.user_id
       AND e.deleted = 0
       AND e.entry_date BETWEEN :ms AND :me
    WHERE la.ledger_id = :ledger_id
    GROUP BY u.id, u.username
    ORDER BY u.username
');
```

**Design.md 說明：**
> 「先驗證當前使用者對該 ledger_id 有 ledger_access 記錄（IDOR 防護），再查詢該帳本所有成員（INNER JOIN ledger_access 不限制 la.user_id），LEFT JOIN 本月未刪除記錄並聚合筆數，沒記錄的成員顯示 0 筆；針對每個可存取帳本分別查詢」

✅ **一致**：現有實作已正確使用 `ledger_access` join 限定帳本、`LEFT JOIN entries` 含 `deleted=0` 且日期區間、`GROUP BY u.id, u.username`，且在 dashboard 主查詢中已先驗證 `ledger_access`。

#### 2.1.2 預算超支橫幅 — 現有實作

**dashboard.php 現有（第 148-156 行、第 228-234 行）：**
- 已有 `$budgetBanners` 邏輯，僅 `$isCurrentMonth && $lsBudget > 0` 時計算
- 已有 `.budget-banner.budget-banner-warn` / `.budget-banner.budget-banner-danger` 顯示

**ledger.php 現有（第 521-529 行、第 560-566 行）：**
- 已有 `$budgetProgress` 邏輯，僅 `$isCurrentMonth && $budgetCents > 0` 時計算
- 已有 `.budget-banner` 顯示（第 523-529 行）
- 另有一處重複顯示 `.alert warn/danger`（第 560-566 行）

**Design.md 說明：**
> 「新增預算超支橫幅（僅當月、預算 > 0 時顯示）」

⚠️ **發現問題 C-01（Major）**：**dashboard.php 與 ledger.php 已實作預算橫幅**，Design.md 將其列為「新增」修改，實際上是**重複設計**。需於 Design.md 標註「已實作，本設計為規格對齊確認」或移除重複描述。

#### 2.1.3 列印友善月結單 — ledger.php 現有實作（第 436-448 行）

**現有程式碼已實作：**
```php
<style>
@media print {
    .side, .nav, .ctr-actions, .quick, .budget-form, .filters, .pager, .font-size-toggle {
        display: none !important;
    }
    .main { margin: 0; padding: 0; }
    .sheet { width: 100%; max-width: 100%; }
    .plates, .table-wrap { page-break-inside: avoid; }
    body { background: #fff; color: #000; }
    .plate { border: 1px solid #000; }
    .v { color: #000; }
}
</style>
```

**Design.md 說明：**
> 「在 `<head>` 內嵌 `@media print` CSS，隱藏 `.side`、`.nav`、`.ctr-actions`、`.quick`，保留 `.plates` 與 `.table-wrap`」

✅ **一致**：現有實作已包含 Design.md 指定的所有隱藏類別，且額外隱藏了 `.budget-form`、`.filters`、`.pager`、`.font-size-toggle`（更完善）。

#### 2.1.4 類別統計圖表 — ledger.php 現有實作（第 760-782 行）

**現有程式碼已實作：**
```php
// 第 720-737 行：查詢包含 INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
// 第 764-782 行：SVG 產生
<svg class="category-chart" viewBox="0 0 600 300" role="img" aria-labelledby="category-chart-title">
    <title id="category-chart-title">本月各類別支出佔比</title>
    <?php foreach ($categoryStats as $stat): ?>
        $percent = ...; $barWidth = ...;
        echo '<rect x="80" y="..." width="..." height="30" fill="#1f4e79" rx="2" />';
        echo '<text x="75" y="..." text-anchor="end" font-size="12" fill="#23272b">' . h($label) . '</text>';
        echo '<text x="..." y="..." font-size="12" fill="#23272b">' . $amount . ' 元 (' . (int) $percent . '%)</text>';
    <?php endforeach; ?>
</svg>
```

**Design.md 說明：**
> 「PHP 端依 entries.category GROUP BY 計算各類別支出總額，產生 SVG `<rect>` 與 `<text>`，金額經 formatCents() 格式化，標籤經 h() 轉義；失敗時回退為 HTML 表格」

⚠️ **發現問題 C-02（Major）**：
1. **現有實作已完成**，Design.md 列為「新增」修改 — 同 C-01，屬重複設計。
2. **現有 SVG 使用硬編碼顏色 `fill="#1f4e79"`**（第 776 行），而非 CSS 變數 `var(--blue)`。Design.md 未提及此差異，且 style.css 第 634 行定義 `.category-chart rect { fill: var(--blue); }`，兩者**衝突** — 內聯 style 會覆蓋 CSS。
3. **現有實作缺少「失敗回退 HTML 表格」機制** — Design.md 第 68 行承諾「SVG 產生失敗時回退為 HTML 表格」，但現有程式碼無此邏輯。
4. **現有查詢條件限制**：`if (!$isFiltered && $typeSel !== 'income')`（第 719 行）— 當使用者篩選類別或類型為收入時，不顯示圖表。Design.md 未說明此限制。

#### 2.1.5 類別預算狀態 — ledger.php 現有實作（第 784-819 行）

現有程式碼已實作類別預算狀態表格（`.category-budget-status`），Design.md 未提及此功能。此屬 [#19]_category-budget 範疇，非本期範圍，但 Design.md 圖表區塊未說明與類別預算的關係。

---

### 2.2 程式碼品質與一致性檢查

| 檢查項目 | 結果 | 說明 |
|---------|------|------|
| PDO 預備陳述式 | ✅ 通過 | 所有查詢使用具名佔位符 |
| 整數分金額 | ✅ 通過 | 所有金額運算使用 `amount_cents`、顯示用 `formatCents()` |
| CSRF 防護 | ✅ 通過 | 所有 POST 表單含 `csrf_token`，用 `checkCsrf()` 驗證 |
| 輸出轉義 `h()` | ✅ 通過 | 所有 HTML 輸出經 `h()`，SVG `<text>` 內容亦經 `h()` |
| 相對路徑 | ✅ 通過 | 所有連結、資源使用 `./` 開頭 |
| Session 登入檢查 | ✅ 通過 | 兩檔案開頭皆 `requireLogin()` |
| 權限檢查 | ✅ 通過 | 皆透過 `ledger_access` join 驗證 |
| 審計日誌 | ✅ 通過 | 關鍵操作呼叫 `logAudit()` |
| 無硬編碼密鑰 | ✅ 通過 | 環境變數從 `.env` 讀取 |

---

### 2.3 設計文件與實作的差距（Design Gap）

| 項目 | Design.md 說明 | 實際實作 | 差距等級 |
|------|---------------|----------|----------|
| 預算橫幅 | 「新增」 | 已存在（dashboard.php、ledger.php） | **Major** — 文件誤導實作者 |
| 列印樣式 | 「新增 @media print」 | 已存在（ledger.php） | **Major** — 文件誤導實作者 |
| 類別統計 SVG | 「新增」 | 已存在（ledger.php） | **Major** — 文件誤導實作者 |
| SVG 失敗回退 | 「回退為 HTML 表格」 | **無實作** | **Major** — 承諾未兌現 |
| SVG 顏色 | 未提及 | 硬編碼 `#1f4e79` vs CSS `var(--blue)` | **Minor** — 樣式不一致 |
| 圖表顯示條件 | 未說明 | `!$isFiltered && $typeSel !== 'income'` | **Minor** — 行為未文檔化 |
| 成員進度 | 「針對每個可存取帳本分別查詢」 | 已正確實作 | ✅ 無差距 |

---

## 三、資安審查 — Security Review

### 3.1 IDOR（不安全直接物件參考）

| 功能 | Design.md 說明 | 實際實作 | 風險評估 |
|------|---------------|----------|----------|
| 成員記帳進度 | 「先驗證當前使用者對該 ledger_id 有 ledger_access 記錄，再查詢該帳本所有成員」 | dashboard.php 主查詢已 `INNER JOIN ledger_access la ON la.ledger_id = l.id AND la.user_id = :user_id`，進度子查詢同樣 `WHERE la.ledger_id = :ledger_id` | ✅ **安全** — 雙重驗證 |
| 預算超支提醒 | 「查詢時必須透過 ledger_access 確認該 ledger_id 對使用者有存取記錄」 | dashboard.php、ledger.php 皆先查詢 `ledgers` + `ledger_access` 驗證 | ✅ **安全** |
| 類別統計圖表 | 「SQL 必須加入 INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id」 | ledger.php 第 723 行已正確加入 | ✅ **安全** |
| 列印月結單 | 「僅影響前端樣式，不涉及資料存取」 | 正確 | ✅ **安全** |

### 3.2 XSS（跨站腳本攻擊）

| 輸出點 | Design.md 要求 | 實際實作 | 風險 |
|--------|--------------|----------|------|
| HTML 一般輸出 | `h()` 轉義 | 全站一致使用 `h()` | ✅ |
| SVG `<text>` 內容 | `h()` 轉義 | ledger.php 第 777 行 `h($label)` | ✅ |
| SVG 屬性（x/y/width/height/fill） | 「強制 cast 為數值」 | 現有實作 `(int) $y`、`(int) $barWidth` | ✅ |
| 類別標籤 | `h()` 轉義 | 現有實作 `h($stat['category'])` | ✅ |

✅ **無 XSS 風險** — 所有使用者可控輸出皆經 `h()` 轉義，SVG 內容同樣處理。

### 3.3 SQL Injection

| 查詢 | 參數化方式 | 風險 |
|------|-----------|------|
| 所有查詢 | PDO 預備陳述式 + 具名佔位符 | ✅ 無風險 |

✅ **無 SQL Injection 風險** — 全專案強制 `ATTR_EMULATE_PREPARES=false`，所有查詢使用具名佔位符。

### 3.4 CSRF（跨站請求偽造）

| 表單/操作 | CSRF Token | 驗證方式 |
|----------|------------|----------|
| 所有 POST 表單 | `csrfToken()` 產生 | `checkCsrf()` + `hash_equals()` |

✅ **無 CSRF 風險** — 所有狀態變更操作皆有 CSRF 防護。

### 3.5 其他資安議題

| 議題 | 現況 | 備註 |
|------|------|------|
| 整數分金額運算 | ✅ 正確 | 避免浮點誤差，交叉相乘判定（`expense * 100 >= budget * 80`） |
| 審計日誌 | ✅ 關鍵操作記錄 | `logAudit()` 呼叫完整 |
| 錯誤資訊洩漏 | ✅ 無 | 例外捕獲後記錄 error.log，不輸出至前端 |
| Session 固定攻擊防護 | ✅ `session_regenerate_id(true)` | `requireLogin()` 內呼叫 `requireSessionFresh()` |

---

## 四、綜合審查結論

### 4.1 發現彙總

| 編號 | 類別 | 嚴重度 | 標題 | 位置 | 狀態 |
|------|------|--------|------|------|------|
| **C-01** | Code | **Major** | Design.md 將「已實作功能」列為「新增修改」：預算橫幅、列印樣式、類別統計 SVG | Design.md 第 49、50、51、63-68 行 | **需修正** |
| **C-02** | Code | **Major** | 類別統計 SVG：承諾「失敗回退 HTML 表格」但無實作；硬編碼顏色衝突 CSS 變數；顯示條件未文檔化 | ledger.php 第 719、776 行；Design.md 第 68 行 | **需修正** |
| **C-03** | Code | **Minor** | Design.md 未提及類別預算狀態表格（屬 [#19] 範疇），圖表區塊關係未說明 | Design.md 第 63-68 行 | 建議補充說明 |
| **S-01** | Security | **Info** | 無新發現資安風險，既有防護完整 | — | 通過 |

### 4.2 審查判定

| 階段 | 判定 | 說明 |
|------|------|------|
| **Spec 審查** | **有條件通過** | 需求覆蓋完整、驗收條件一致、待確認事項同步；但 Design.md 需修正 C-01、C-02 所述的「已實作 vs 新增」誤導與承諾未兌現問題 |
| **Code 審查** | **請修正後重審** | 既有程式碼品質良好，符合專案硬性約束；但 Design.md 與實作有重大差距（C-01、C-02），需修正文件或補充實作 |
| **Security 審查** | **通過** | 無新增資安風險，IDOR/XSS/SQLi/CSRF 皆有完善防護 |

### 4.3 修正建議（阻塞項）

**必須修正後方可進入實作階段（或視為實作驗證階段）：**

1. **修正 Design.md 第 49-51、63-68 行**：將「新增」改為「規格對齊確認 / 已實作驗證」，或移除重複描述，明確標註現有實作位置。
2. **補充類別統計 SVG 失敗回退機制**：在 ledger.php 第 760-782 行區塊加入 `try-catch`，失敗時輸出 HTML 表格（類別、金額、佔比），金額經 `formatCents()`、文字經 `h()`。
3. **修正 SVG 硬編碼顏色**：將 `fill="#1f4e79"` 改為 `fill="var(--blue)"` 或移除內聯 fill 讓 CSS 生效（style.css 第 634 行已定義）。
4. **補充文檔圖表顯示條件**：在 Design.md 說明「僅在未篩選（`!$isFiltered`）且非收入篩選（`$typeSel !== 'income'`）時顯示」。

---

## 五、給實作者的具體行動項目

若本設計文件用於**實作驗證**（確認現有實作符合規格），請執行：

| 動作 | 檔案 | 具體修改 |
|------|------|----------|
| 1. 修正 Design.md 語意 | `docs/[#13]_ledger-statistics/Design.md` | 將「新增」改為「驗證現有實作符合規格」，標註具體行號 |
| 2. 補充 SVG 失敗回退 | `ledger.php` 第 760-782 行 | 用 `try-catch` 包裹 SVG 產生，`catch` 時輸出 HTML 表格 |
| 3. 修正 SVG 顏色 | `ledger.php` 第 776 行 | `fill="#1f4e79"` → `fill="var(--blue)"` |
| 4. 同步更新 Design.md | `docs/[#13]_ledger-statistics/Design.md` | 第 63-68 行補充顯示條件、回退機制、顏色變數說明 |

---

## 六、審查簽署

| 角色 | 簽署 | 日期 |
|------|------|------|
| 獨立第三方審查者（Spec） | ✅ 有條件通過 | 2026-11-23 |
| 獨立第三方審查者（Code） | ⚠️ 請修正後重審 | 2026-11-23 |
| 獨立第三方審查者（Security） | ✅ 通過 | 2026-11-23 |

> **備註**：本審查為設計階段審查。若專案已進入實作階段（現有程式碼顯示功能大多已實作），建議將本 Design.md 定性為「實作驗證規格文件」，並依上述修正建議更新文件與補充缺失實作（SVG 回退、顏色變數）。實作完成後需再執行一次完整的三階段審查（Spec → Code → Security），並在 Design.md 補上「實際變更」與「驗證證據」。