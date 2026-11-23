# Design: 查帳統計與提醒（成員記帳進度、預算超支提醒、列印月結單、類別統計圖表）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Design: 查帳統計與提醒 |
| 版本 | v1.0 |
| 建立日期 | 2026-11-21 |
| 更新日期 | 2026-11-21 |
| 變更摘要 | 初始設計，依 Planning 四項功能拆解為程式修改路徑與前端變更 |

## 來源 Planning 與需求摘要

- Planning 檔案：`docs/ledger-statistics/planning.md`
- 對應 Feature：6（成員記帳進度）、7（預算超支醒目提醒）、9（列印友善月結單）、10（類別統計圖表）
- 需求摘要：在 `dashboard.php` 與 `ledger.php` 加入四項查帳統計功能，不新增資料表，不變更既有權限模型。

## 設計概述

本設計依循專案既有設計系統（`assets/style.css` 設計 token 與元件類別），四項功能分為：

1. **成員記帳進度**（`dashboard.php`）：先驗證當前使用者對該 `ledger_id` 有 `ledger_access` 記錄（IDOR 防護），再查詢該帳本所有成員（`INNER JOIN ledger_access` 不限制 `la.user_id`），`LEFT JOIN` 本月未刪除記錄（`entries`，`deleted = 0` 且 `entry_date` 在本月區間）並聚合筆數；沒記錄的成員顯示 0 筆；針對每個可存取帳本分別查詢。
2. **預算超支醒目提醒**（`dashboard.php`、`ledger.php`）：複用現有 `monthly_budget_cents` 與 `expenseCents` 變數，僅在當月（`$isCurrentMonth`）且預算 > 0 時計算，顯示黃色/紅色橫幅。
3. **列印友善月結單**（`ledger.php`）：在 `<head>` 內嵌 `@media print` CSS，隱藏 `.side`、`.nav`、`.ctr-actions`、`.quick`，保留 `.plates` 與 `.table-wrap`。
4. **類別統計圖表**（`ledger.php`）：PHP 端依 `entries.category` GROUP BY 計算各類別支出總額，產生 SVG `<rect>` 與 `<text>`，金額經 `formatCents()` 格式化，標籤經 `h()` 轉義；失敗時回退為 HTML 表格。

## 設計圖表（Mermaid）

### 功能整體流程圖

```mermaid
flowchart TD
    A[使用者開啟 dashboard.php] --> B[查詢各可存取帳本成員進度]
    B --> C[顯示本月各成員記帳筆數]
    C --> D[檢查預算超支橫幅]
    D --> E[顯示黃色/紅色提醒]
    E --> F[開啟 ledger.php]
    F --> G[顯示預算超支橫幅]
    G --> H[顯示類別統計 SVG 圖表]
    H --> I[使用者列印頁面]
    I --> J[@media print 隱藏側欄與按鈕，僅保留合計牌與分錄表]
```

## 程式修改路徑

| 檔案 | 修改內容 |
| --- | --- |
| `dashboard.php` | **驗證現有實作符合規格**（第 521-529 行、531-558 行、560-566 行）：成員記帳進度區塊與預算超支橫幅已實作，確認邏輯與規格一致（僅當月、預算 > 0 時顯示、黃/紅色橫幅）。 |
| `ledger.php` | **驗證現有實作符合規格**（第 521-529 行、531-558 行、560-566 行）：預算超支橫幅與 dashboard 同邏輯已實作；**驗證現有實作符合規格**（第 436-448 行）：列印友善月結單 `@media print` CSS 已內嵌於 `<head>`；**規格對齊確認**（第 716-783 行、784-822 行）：類別統計 SVG 圖表已實作，補充回退機制與顏色變數使用。 |
| `assets/style.css` | **驗證現有實作符合規格**：預算橫幅樣式（`.budget-banner`、`.budget-banner.warn`、`.budget-banner.danger`）與類別統計圖表樣式（`.category-chart rect { fill: var(--blue); }`）已存在；列印樣式已內嵌於 ledger.php，不修改 CSS。 |

## 前端變更說明

### 預算超支橫幅

- 樣式參考既有 `.budget-box` 與 `.budget-chip`（ledger.php 已有），但橫幅置於頁面頂部，更醒目。
- 計算公式：`$rate = $expenseCents / $monthly_budget_cents`（僅當月，`expenseCents` 為本月支出總額，`type='expense'` 且 `deleted=0`），`$rate >= 1` 顯示紅色危險橫幅，`$rate >= 0.8` 顯示黃色警告橫幅，預算 > 0 但未達 80% 不顯示橫幅（僅保留現有進度條）。
- 黃色（warn）：支出 ≥80% 預算，顯示「接近預算上限」。
- 紅色（danger）：支出 ≥100% 預算，顯示「已超過預算」。
- 僅在 `$isCurrentMonth === true` 且 `monthly_budget_cents > 0` 時顯示，歷史月份不顯示。

### 類別統計 SVG 圖表

- **顯示條件**：僅在 `!$isFiltered && $typeSel !== 'income'` 且本月有支出記錄（`$categoryTotalCents > 0`）時顯示（ledger.php 第 719 行、761 行）。
- PHP 端查詢：`SELECT e.category, SUM(e.amount_cents) FROM entries e INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id WHERE e.ledger_id = :ledger_id AND e.deleted = 0 AND e.type = 'expense' AND e.entry_date BETWEEN 本月首日 AND 本月末日 GROUP BY e.category ORDER BY SUM(e.amount_cents) DESC`
- 金額經 `formatCents()` 格式化；類別標籤與 `username` 所有輸出點（HTML 與 SVG `<text>`）皆經 `h()` 轉義；SVG `<rect>` 的 `x/y/width/height` 為 PHP 計算之數字，強制 cast 為數值。
- **顏色變數**：`<rect>` 不指定內聯 `fill`，改由 CSS 變數 `--blue` 控制（`assets/style.css` 第 634 行 `.category-chart rect { fill: var(--blue); }`），避免硬編碼衝突。
- 若無支出記錄，顯示文字「尚無支出紀錄」（ledger.php 第 820-821 行）。
- **回退機制**：SVG 產生區塊以 `try-catch` 包裹（ledger.php 第 766-782 行），失敗時輸出 HTML 表格（類別、金額、佔比），金額經 `formatCents()`、文字經 `h()` 轉義。

### 列印友善月結單

- `@media print` 內嵌於 ledger.php `<head>`。
- 隱藏：`.side`、`.nav`、`.ctr-actions`、`.quick`。
- 保留：`.plates`（三聯合計牌）、`.table-wrap`（分錄表）。
- `.plates` 與 `.table-wrap` 加入 `page-break-inside: avoid`。

## 資料庫變更

本主題**不新增資料表**，亦不變更既有資料表。所有查詢複用現有 `entries`、`ledgers`、`ledger_access`、`users` 表；`entries.category` 欄位已存在（`TEXT NOT NULL DEFAULT ''`，參照 `db.php` 建表語句），類別統計直接查詢此欄位。

## 預期行為與驗證方法

| 驗收項 | 預期行為 | 驗證方法 |
| --- | --- | --- |
| 成員記帳進度 | 儀表板顯示該帳本所有成員本月記帳筆數，沒記錄的成員顯示 0 筆；與 entries 表中該帳本本月未刪除記錄一致 | 開啟 `dashboard.php`，確認所有成員皆列出且筆數正確；無記錄成員顯示 0 |
| 預算超支橫幅 | 本月支出 ≥80% 顯示黃色橫幅，≥100% 顯示紅色橫幅；歷史月份不顯示 | 設定預算後查看當月與歷史月份頁面 |
| 列印月結單 | 瀏覽器列印時側欄與按鈕被隱藏，僅保留合計牌與分錄表 | 使用瀏覽器列印預覽，確認側欄不顯示 |
| 類別統計圖表 | 顯示 SVG 長條圖，標籤繁體中文，金額經 formatCents() 格式化 | 開啟 `ledger.php`，確認圖表顯示；無支出時顯示文字提示 |

## 設計審查記錄（設計階段）

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 待審查 | — | 將本 Design 與 `planning.md` 並排核對範圍、驗收條件 |
| Code（程式）審查 | 待審查 | — | 檢查 `dashboard.php`、`ledger.php`、`assets/style.css` 修改路徑與相容性 |
| Security（資安）審查 | 待審查 | — | 檢查 IDOR（ledger_access 權限）、XSS（`h()` 轉義）、SQL 注入（PDO 預備陳述式） |

> 設計審查三階段皆通過後方可進入實作。實作階段完成後，需在同一份 Design.md 補上「實際變更」與「驗證證據」，並執行實作審查（Spec → Code → Security）。

## 非本期範圍（Out of Scope）

- 不新增多年度比較、多帳本彙總圖表、圖表匯出（PDF/Excel）或即時推播通知。
- 不新增資料表。

## 身分驗證與權限模型

不變更既有登入與帳本權限模型。所有查詢皆透過 `ledger_access` 做 IDOR 防護：

- 成員記帳進度：先驗證當前使用者對該 `ledger_id` 有 `ledger_access` 記錄（IDOR 防護），再查詢該帳本所有成員（`INNER JOIN ledger_access` 不限制 `la.user_id`），`LEFT JOIN` 本月未刪除記錄並聚合筆數，沒記錄的成員顯示 0 筆。
- 預算超支提醒：`monthly_budget_cents` 來源於 `ledgers` 表，查詢時必須透過 `ledger_access` 確認該 `ledger_id` 對使用者有存取記錄（owner/member/viewer 角色），避免直接透過 `ledgers.id` 取得他人帳本預算。
- 類別統計圖表：SQL 必須加入 `INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id`，僅查詢該使用者可存取帳本的 entries。
- 列印月結單：僅影響前端樣式，不涉及資料存取。

## 資料傳輸加密標準

無新增外部 API。SVG 與統計皆在伺服端渲染，直接輸出至 HTML，不額外傳輸。

## 非功能性需求

- **效能**：SVG 圖表由 PHP 產生，不增加前端庫依賴；成員記帳進度單一 SQL 聚合查詢，效能影響可忽略。
- **併發**：無資料庫寫入競合，本主題不新增資料表。
- **資料保留**：無新增資料表，不涉及資料保留策略變更。
- **可用性**：列印樣式僅在列印時生效，不影響一般頁面載入；SVG 失敗時回退為 HTML 表格。

## 差異與待確認

與 Planning 核心需求一致，所有待確認事項於 Planning 中已列出：

- 成員記帳進度：本期僅顯示「本月」，不支援自訂月份。
- 預算超支橫幅：僅在頁面中顯示，不彈出通知。
- 列印月結單：不包含簽名欄位。