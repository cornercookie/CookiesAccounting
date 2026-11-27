# Design: 收支比較報表（本月 vs 上月 / 今年 vs 去年）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Design: 收支比較報表（本月 vs 上月 / 今年 vs 去年） |
| 版本 | v1.3 |
| 建立日期 | 2026-11-24 |
| 更新日期 | 2026-11-27 |
| 變更摘要 | v1.2 新增比較報表、SVG 長條、差額、篩選與導覽；v1.3 修正多類別長條裁切及兩系列同色，補記隔離回歸與 Chrome 實跑。無資料庫變更。 |

## 來源 Planning 與需求摘要

- Planning 檔案：`docs/[#18]_comparison-report/planning.md`
- 對應 Feature：收支比較報表（第 18 項任務）
- 需求摘要：在既有 yearly_report.php 與 quarterly_report.php 基礎上，新增 `comparison_report.php` 報表頁面，支援「本月 vs 上月」與「今年 vs 去年」兩種比較模式，各類別支出並列顯示兩區間金額、差額（絕對值與百分比），支援類別與帳本篩選，以 SVG 條形圖並列呈現；不新增資料庫表，僅複用既有 `entries` 與 `ledger_access` 表；所有查詢透過 `ledger_access` 做 IDOR 防護；篩選表單提交需驗證 CSRF 權杖。

## 設計概述

本設計依循專案既有設計系統與硬性約束（純 PHP + PDO 預備陳述式、整數分金額、session 登入、h() XSS 轉義、ledger_access 權限模型、相對路徑、無框架、無 Composer、無 Node.js）。主要變更如下：

1. **新增報表頁面**：建立 `comparison_report.php`，放在根目錄，支援比較模式選擇、帳本下拉選單、類別下拉選單。
2. **比較模式**：
   - `mode=month`：本月 vs 上月（Period A = 本月全月，Period B = 上月全月）
   - `mode=year`：今年 vs 去年（Period A = 今年全年，Period B = 去年全年）
3. **查詢邏輯**：分別針對兩個日期區間，以 `entry_date BETWEEN :startDate AND :endDate`、`deleted = 0`、`type = 'expense'` 條件，透過 `INNER JOIN ledger_access` 做 IDOR 防護，依 `category` 分組加總 `amount_cents`；計算差額（絕對值）與百分比變動，全程整數運算，禁止 FLOAT。
4. **前端顯示**：表格列出類別、Period A 金額、Period B 金額、差額、百分比變動；SVG 條形圖並列顯示兩區間對比；金額經 `formatCents()` 格式化，所有輸出經 `h()` 轉義；無記錄時顯示「尚無記錄」。
5. **CSRF 防護**：篩選表單提交時須驗證 CSRF 權杖，驗證失敗則拒絕請求並顯示錯誤訊息。
6. **無資料庫變更**：不新增資料庫表，無 migration 需求。
7. **列印樣式**：參照 `ledger.php` 與 `yearly_report.php` 的 `@media print`，隱藏側欄與操作按鈕，僅保留報表內容。
8. **導覽連結**：在 `ledgers.php`、`ledger.php`、`dashboard.php` 新增收支比較報表連結。

## 設計圖表（Mermaid）

```mermaid
flowchart TD
    A[使用者開啟 comparison_report.php] --> B[requireLogin() 驗證登入]
    B --> C[取得使用者可存取帳本列表 ledger_access]
    C --> D[讀取比較模式、帳本、類別參數]
    D --> E{參數驗證通過？}
    E -->|日期格式錯誤| F[回傳 HTTP 400 錯誤]
    E -->|帳本無權限| G[redirect ledgers.php?error=1]
    E -->|通過| H[CSRF 權杖驗證 若表單已提交]
    H -->{CSRF 驗證通過？}
    H -->|否| I[顯示錯誤訊息，拒絕請求]
    H -->|是| J[PDO 查詢 Period A entries 依 category 加總 amount_cents]
    J --> K[PDO 查詢 Period B entries 依 category 加總 amount_cents]
    K -->{兩區間查詢皆成功？}
    K -->|否| L[error.log 記錄，顯示通用錯誤頁]
    K -->|是| M[合併類別，計算差額與百分比 整數運算]
    M --> N{有資料？}
    N -->|否| O[顯示「尚無記錄」]
    N -->|是| P[SVG 條形圖並列顯示 兩區間對比 h() 轉義]
    P --> Q[顯示類別比較表格 與 差額百分比]
    style F fill:#ffebee,stroke:#c62828,stroke-width:2px
    style I fill:#ffebee,stroke:#c62828,stroke-width:2px
    style L fill:#ffebee,stroke:#c62828,stroke-width:2px
    style O fill:#fff3e0,stroke:#e65100,stroke-width:2px
    style Q fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px
```

## 程式修改路徑

| 檔案 | 修改內容 |
| --- | --- |
| `comparison_report.php`（新增） | 收支比較報表頁面：<br>1. 頁面頂端呼叫 `requireLogin()`，確保使用者已登入。<br>2. 取得使用者可存取帳本列表（`INNER JOIN ledger_access WHERE la.user_id = :user_id AND l.archived = 0`）。<br>3. 讀取 `ledger_id`（GET），驗證為整數；若未提供且僅有一個可存取帳本則自動選擇，否則導向 `ledgers.php?error=1`。<br>4. 透過 `ledger_access` 確認使用者有存取權；不合法時 `redirect('./ledgers.php?error=1')`。<br>5. 檢查帳本是否封存（`archived = 0`），若封存則顯示錯誤。<br>6. 讀取 `mode` 參數（`month` / `year`），預設 `month`；驗證為允許值。<br>7. 根據模式計算兩個日期區間（Period A / Period B），以 `Y-m-d` 格式與 `entries.entry_date` 比較：<br>   - month：Period A = 本月 `Y-m-01` ~ `Y-m-t`，Period B = 上月全月<br>   - year：Period A = 今年 `Y-01-01` ~ `Y-12-31`，Period B = 去年全年<br>8. 日期格式驗證：`isValidDate()`，格式錯誤時 HTTP 回應 400 並立即終止腳本（exit），不執行查詢。<br>9. 類別下拉選單：查詢該帳本下所有已使用類別（`DISTINCT category FROM entries WHERE ledger_id = :ledger_id AND deleted = 0 ORDER BY category`）。<br>10. 分別查詢兩區間的 entries，依 `category` 分組加總 `amount_cents`，`type = 'expense'`，`deleted = 0`，透過 `INNER JOIN ledger_access` 做 IDOR 防護。<br>11. 合併兩區間類別清單，計算差額（abs(Period A − Period B)，整數分，取絕對值）與百分比變動（整數除法，Period B 為 0 時顯示「－」）。<br>12. 金額經 `formatCents()` 格式化，所有輸出經 `h()` 轉義。<br>13. SVG 條形圖：每個類別並列兩條條形（Period A / Period B），圖例與標籤使用中文。<br>14. 無記錄時顯示「尚無記錄」。<br>15. 查詢失敗時 `try/catch (Throwable $e)`，`logError()` 記錄至 `private_data/error.log` 並顯示友善訊息，不中斷頁面。<br>16. 篩選表單使用 GET 方法，包含 CSRF 權杖隱藏欄位；僅於偵測到提交標記（`$_GET['_submit'] === '1'`）時執行 `checkCsrf()` 驗證，失敗則顯示錯誤訊息並拒絕請求；初次載入網址（含 mode/ledger_id）不視為提交，不強制 CSRF，維持網址可分享。<br>17. 頁面內聯 `@media print` 樣式，隱藏側欄、導覽、篩選表單。 |
| `assets/style.css` | 新增比較報表樣式：`.comparison-table`、`.comparison-table tfoot tr.comparison-total-row`、`.diff-positive`、`.diff-negative`、`.diff-neutral`、SVG 條形圖容器 `.comparison-chart`，與既有 `.report-table`、`.report-total`、`.report-empty` 樣式一致；支援手機單欄顯示。 |
| `ledgers.php` | 在帳本列表操作欄新增「收支比較報表」連結按鈕。 |
| `ledger.php` | 頁面頂端動作區新增「收支比較報表」連結按鈕。 |
| `dashboard.php` | 各帳本卡片操作欄新增「收支比較報表」連結按鈕。 |
| `db.php` | 無需修改（不新增資料庫表，無 migration）。 |
| 無新增檔案 | 不新增 PHP 頁面以外的檔案，不新增資料庫表，不新增外部依賴。 |

## 資料庫變更

無。報表功能僅讀取既有 `entries` 與 `ledger_access` 表，不新增資料庫表，無 migration 需要。

## 前端變更說明

### 比較報表頁面（comparison_report.php）

- **頁面標題**：「收支比較報表」
- **篩選表單**：
  - 比較模式下拉選單（本月 vs 上月 / 今年 vs 去年）
  - 帳本下拉選單（僅顯示使用者有存取權的帳本）
  - 類別下拉選單（可選，預設顯示所有類別）
  - CSRF 權杖隱藏欄位
  - 所有選擇回填時必須經 `h()` 轉義
- **結果顯示**：
  - 類別比較表格：類別、Period A 金額、Period B 金額、差額（絕對值）、百分比變動
  - 總計列：Period A 總計、Period B 總計、總差額
- **SVG 條形圖**：每個類別並列兩條條形（Period A / Period B），圖例與標籤使用中文，條形寬度比例對應金額大小
- **空白狀態**：若兩區間皆無記錄，顯示「尚無記錄」
- **錯誤狀態**：日期格式錯誤回 400；資料庫查詢失敗顯示友善錯誤訊息，不中斷頁面

### CSS 樣式（assets/style.css）

```css
/* 收支比較報表樣式 */
.comparison-table {
    margin: var(--space-4) 0;
    width: 100%;
    border-collapse: collapse;
}
.comparison-table th,
.comparison-table td {
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--line);
    text-align: left;
}
.comparison-table th {
    background: var(--paper-3);
    font-weight: 700;
    font-size: 0.875rem;
}
.comparison-table td.num {
    text-align: right;
    font-family: var(--font-mono);
}
.comparison-table tfoot tr.comparison-total-row {
    background: var(--paper-2);
    font-weight: 700;
    border-top: 2px solid var(--ink);
}
.diff-positive { color: #1d4a24; }
.diff-negative { color: #7a241a; }
.diff-neutral { color: var(--ink-2); }
.comparison-chart {
    margin: var(--space-4) 0;
    width: 100%;
    max-width: 700px;
}
.comparison-chart svg {
    width: 100%;
    height: auto;
    display: block;
}

/* 收支比較報表列印樣式 */
@media print {
    .side, .nav, .ctr-actions, .quick, .font-size-toggle, .filters {
        display: none !important;
    }
    .main { margin: 0; padding: 0; }
    .sheet { width: 100%; max-width: 100%; }
    body { background: #fff; color: #000; }
    .plate { border: 1px solid #000; }
    .v { color: #000; }
    .comparison-table, .comparison-chart { page-break-inside: avoid; }
    .comparison-table tfoot tr.comparison-total-row td {
        background: var(--paper-2) !important;
        border-top: 2px solid #000 !important;
        font-weight: 700;
    }
}
```

## SQL 查詢範例

### 取得可存取帳本列表

```sql
SELECT l.id, l.name, la.role
FROM ledgers l
INNER JOIN ledger_access la ON la.ledger_id = l.id
WHERE l.archived = 0 AND la.user_id = :user_id
ORDER BY l.name
```

### 驗證帳本存取權限（IDOR 防護）

```sql
SELECT l.id, l.name, l.archived, la.role
FROM ledgers l
INNER JOIN ledger_access la ON la.ledger_id = l.id
WHERE l.id = :ledger_id
  AND la.user_id = :user_id
LIMIT 1
```

### 取得該帳本已使用類別（供下拉選單）

```sql
SELECT DISTINCT e.category
FROM entries e
INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
WHERE e.ledger_id = :ledger_id
  AND e.deleted = 0
ORDER BY e.category
```

### Period A 類別支出彙總

```sql
SELECT e.category, SUM(e.amount_cents) AS total_cents
FROM entries e
INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
WHERE e.ledger_id = :ledger_id
  AND e.deleted = 0
  AND e.type = 'expense'
  AND e.entry_date BETWEEN :startDateA AND :endDateA
GROUP BY e.category
ORDER BY total_cents DESC
```

### Period B 類別支出彙總（結構同上，不同日期區間參數）

```sql
SELECT e.category, SUM(e.amount_cents) AS total_cents
FROM entries e
INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
WHERE e.ledger_id = :ledger_id
  AND e.deleted = 0
  AND e.type = 'expense'
  AND e.entry_date BETWEEN :startDateB AND :endDateB
GROUP BY e.category
ORDER BY total_cents DESC
```

執行後，`SUM(amount_cents)` 回傳字串，需 `(int)` 強制轉型再傳入 `formatCents()`：
```php
$periodACents = (int) $rowA['total_cents'] ?? 0;
$periodBCents = (int) $rowB['total_cents'] ?? 0;
$diffCents = abs($periodACents - $periodBCents);
```

### 百分比變動計算（整數運算，禁止 FLOAT）

```php
if ($periodBCents > 0) {
    $percentChange = (int) floor($diffCents * 100 / $periodBCents);
} else {
    $percentChange = null; // 顯示「－」
}
```

## 預期行為與驗證方法

| 驗收項 | 預期行為 | 驗證方法 |
| --- | --- | --- |
| 比較報表頁面存在 | 使用者可存取 `comparison_report.php`，選擇比較模式與帳本 | 開啟 `comparison_report.php?mode=month&ledger_id=1`，確認顯示比較報表 |
| 未登入導向登入頁 | 未登入使用者存取即導向 `login.php` | 未登入狀態下開啟頁面，確認導向登入 |
| 帳本權限隔離 | 僅顯示使用者有 `ledger_access` 權限的帳本資料 | 以不同權限使用者存取，確認只能看到授權帳本 |
| 本月 vs 上月模式 | 顯示本月與上月各類別支出並列對比 | 選擇 mode=month，確認兩區間日期正確 |
| 今年 vs 去年模式 | 顯示今年與去年各類別支出並列對比 | 選擇 mode=year，確認兩區間日期正確 |
| 差額與百分比計算正確 | 差額取絕對值 abs(Period A − Period B)（整數分），百分比為整數運算 | 手工計算核對幾個類別的數值 |
| 金額格式正確 | 金額經 `formatCents()` 格式化（整數分除以 100、千分位顯示） | 檢查金額顯示是否包含千分位，如「1,234.56」 |
| 禁止 FLOAT | 所有金額運算使用整數，無浮點數資料類型 | 檢查程式碼無 `float` / `double` 型別金額運算 |
| SVG 條形圖並列顯示 | 每個類別並列兩條條形，圖例與標籤使用中文 | 檢查 SVG 元素包含兩組條形與中文標籤 |
| 所有輸出經 h() 轉義 | 無 XSS 風險 | 檢查 HTML 原始碼，確認特殊字元被轉義 |
| IDOR 防護 | 查詢皆 `INNER JOIN ledger_access` | 檢查 SQL 查詢皆包含 `ledger_access` JOIN |
| CSRF 權杖驗證 | 篩選表單提交時驗證 CSRF，失敗拒絕請求 | 提交無效 CSRF 權杖，確認顯示錯誤訊息 |
| 無記錄時顯示提示 | 顯示「尚無記錄」文字 | 查詢無記錄的期間，確認顯示友善訊息 |
| 日期格式錯誤回 400 | 日期格式不正確時 HTTP 回應 400 | 傳入無效日期參數，確認回應 400 |
| 查詢失敗不中斷頁面 | 錯誤寫入 `error.log`，顯示友善訊息 | 模擬資料庫錯誤，確認頁面仍渲染 |
| 與現有功能並存 | 不破壞既有 `ledger.php`、`yearly_report.php`、`quarterly_report.php` | 確認既有報表頁面正常運作 |
| 導覽連結可用 | 從 ledgers/ledger/dashboard 可進入比較報表 | 點擊各頁面報表連結，確認正確導向 |

## 非本期範圍（Out of Scope）

- 不新增多帳本合併比較報表（每次僅查看單一帳本）。
- 不新增匯出比較報表 PDF/Excel。
- 不新增預警通知（預算超支或趨勢異常自動通知）。
- 不新增多年趨勢圖表（本期僅支援兩區間對比）。
- 不新增收入/轉帳類別比較（本期僅比較支出類別）。
- 不新增資料庫表，不新增 PHP 頁面以外的檔案。

## 身分驗證與權限模型

不變更既有登入與帳本權限模型。繼續使用 session 登入與 `ledger_access` 權限檢查（owner/member/viewer 均可檢視報表，因為僅為唯讀查詢）。所有涉及帳本資料的查詢必須 `INNER JOIN ledger_access` 驗證目前使用者對該帳本的存取權限。

## 資料傳輸加密標準

無新增外部 API 呼叫，全部為伺服端 SQL 與 session 驗證，資料僅儲存在站台自身 SQLite 資料庫。依賴既有 HTTPS Session Cookie 設定（secure 僅安全連線傳送、HttpOnly 僅伺服端可存取、SameSite=Lax 跨站請求限制）。

## 非功能性需求

- **效能**：報表頁面載入時間不超過 3 秒（兩區間查詢）。單一帳本查詢，SQLite WAL 模式與索引可接受。
- **併發**：僅讀取查詢，無資料庫寫入競合。
- **資料保留**：報表不產生額外資料，不涉及資料保留。
- **可用性**：單一區間查詢失敗時可顯示另一區間結果，避免整頁失效；查詢失敗時不中斷頁面渲染，錯誤寫入 `error.log`。

## 差異與待確認

與 Planning 核心需求一致。以下為設計階段已釐清的決定：

1. **比較模式定義**：Period A 與 Period B 均為完整期間（本月全月 vs 上月全月、今年全年 vs 去年全年），不使用「至今日」的月份至今，以確保兩區間長度一致、可公平比較。
2. **僅比較支出類別**：Planning 使用者故事強調「各類別支出」，因此查詢條件限定 `type = 'expense'`，不包含收入與轉帳。
3. **CSRF 權杖在 GET 表單**：篩選表單使用 GET 方法（方便網址分享與書籤），但於隱藏欄位包含 CSRF 權杖，提交時執行 `checkCsrf()` 驗證，失敗則顯示錯誤訊息並拒絕請求，符合 Planning 項目 10 要求。
4. **差額顯示規則**：差額取絕對值 abs(Period A − Period B)，顯示兩區間金額變動幅度；百分比變動以整數除法計算，Period B 為 0 時顯示「－」。色彩用於標示變動幅度，不以正負號區別增加/減少。
5. **異常訊息顯示方式**：資料庫查詢失敗時顯示通用友善訊息，詳細錯誤寫入 `private_data/error.log`，不中斷頁面；日期格式錯誤時 HTTP 回應 400。此為設計階段對 Planning:111 待開發者決定事項之釐清結果。

## 設計審查記錄（設計階段）

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 已通過 | 2026-11-24 | 獨立第三方子代理審查，無阻塞 |
| Code（程式）審查 | 已通過 | 2026-11-24 | 獨立第三方子代理複查，差額定義阻塞已關閉 |
| Security（資安）審查 | 已通過 | 2026-11-24 | 獨立第三方子代理審查，無阻塞 |

## 實作階段審查記錄

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 已通過 | 2026-11-24 | 獨立第三方子代理複查，SVG 金額標籤阻塞已關閉 |
| Code（程式）審查 | 已通過 | 2026-11-24 | 獨立第三方子代理複查，SVG 金額標籤阻塞已關閉 |
| Security（資安）審查 | 已通過 | 2026-11-24 | 獨立第三方子代理複查，SVG 金額標籤阻塞已關閉，無新阻塞 |

## 實際變更與驗證證據

### 新增檔案
- `comparison_report.php` - 收支比較報表頁面（根目錄）

### 修改檔案
- `assets/style.css` - 新增 `.comparison-table`、`.comparison-chart` 及列印樣式
- `ledgers.php` - 帳本列表操作欄新增「收支比較報表」連結按鈕
- `ledger.php` - 頁面頂端動作區新增「收支比較報表」連結按鈕
- `dashboard.php` - 各帳本卡片操作欄新增「收支比較報表」連結按鈕

### 後續修正與實跑

- SVG `viewBox` 高度依類別數及兩根長條的共用步距擴展，最低維持 420；查詢、差額、權限和表格未變。8 類共 16 根長條，最後底端為 514；修復前超出高度 420，修復後包含於高度 524。
- 隔離 PHP／SQLite 回歸先確認失敗、再驗修復通過；Chrome 實跑 1100／390px 視窗確認 16 根長條依序顯示且未超出圖表。無記錄提示及前期為 0 時顯示「－」另經 HTTP 實測。
- 月度統計的藍色選擇器限縮在自身容器，避免覆蓋比較圖的紅色，並更新比較頁樣式快取版本。Chrome 回歸先重現四個圖形全藍，再確認當期／前期長條分別與藍／紅圖例同色，月度統計保留藍色；1100／390px 均通過。

### 初版靜態檢查記錄
- [x] `comparison_report.php` 新增，頁面頂端呼叫 `requireLogin()`
- [x] 所有 SQL 使用 PDO 預備陳述式、具名佔位符、`INNER JOIN ledger_access` 做 IDOR 防護
- [x] 金額全程整數分運算，`abs()` 差額、`(int) floor()` 百分比，禁止 FLOAT
- [x] 所有輸出經 `h()` 轉義（含 SVG 類別標籤與表單回填）
- [x] 篩選表單 GET 方法，隱藏 CSRF 權杖與 `_submit` 標記，僅提交時驗證 `checkCsrf()`
- [x] 日期格式錯誤回 400 並終止腳本
- [x] 無記錄時顯示「尚無記錄」
- [x] 查詢失敗 `try/catch`，`logError()` 記錄至 `private_data/error.log`，不中斷頁面
- [x] SVG 條形圖並列顯示兩區間，圖例與標籤使用中文
- [x] 內聯 `@media print` 樣式，隱藏側欄與操作欄
- [x] 導覽連結：ledgers.php、ledger.php、dashboard.php 新增收支比較報表連結
- [x] 無資料庫變更，無 migration
- [x] 所有路徑使用 `./` 相對路徑
- [x] PHP 基本語法結構檢查通過（括號、引號、標籤皆配對；無 PHP 解析器可執行 `php -l`）
