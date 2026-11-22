# Design: 年度／季度報表（多月份收支彙總）

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | Design: 年度／季度報表（多月份收支彙總） |
| 版本 | v1.1 |
| 建立日期 | 2026-11-22 |
| 更新日期 | 2026-11-22 |
| 變更摘要 | v1.1 補充頁面架構決定、ledger_id 缺失行為、帳本封存檢查、餘額公式、日期計算公式、SQL 寫法、錯誤處理結構、表單回填轉義、列印樣式 |

## 來源 Planning 與需求摘要

- Planning 檔案：`docs/yearly-quarterly-report/planning.md`
- 對應 Feature：年度／季度報表（第二階段第 6 項）
- 需求摘要：新增年度/季度報表頁面，讓使用者可選擇年份與季度，查看該期間內的收入、支出、轉帳、結餘彙總；不新增資料庫表，僅複用既有 `entries` 表；所有查詢皆透過 `ledger_access` 做 IDOR 防護；僅讀取查詢，不需要 CSRF Token。

## 設計概述

本設計依循專案既有設計系統與硬性約束（純 PHP + PDO 預備陳述式、整數分金額、session 登入、h() XSS 轉義、ledger_access 權限模型、相對路徑、無框架、無 Composer、無 Node.js）。主要變更如下：

1. **新增報表頁面**：建立 `yearly_report.php`（年度報表）與 `quarterly_report.php`（季度報表），兩個獨立頁面，放在根目錄，支援年份與季度選擇。
2. **查詢邏輯**：使用 `new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'))` 計算起迄日期，以 `Y-m-d` 格式與 `entries.entry_date` 比較；查詢透過 `INNER JOIN ledger_access` 做 IDOR 防護；GROUP BY 月份與類型計算彙總。
3. **前端顯示**：金額經 `formatCents()` 格式化、所有輸出經 `h()` 轉義；無記錄時顯示「該期間無記錄」；查詢失敗時改寫 `error.log` 並顯示友善訊息，不中斷頁面。
4. **無資料庫變更**：不新增資料庫表，無 migration 需求。
5. **CSRF**：本頁面為唯讀 GET 查詢，無狀態改寫表單，不產生也不驗證 CSRF Token。
6. **列印樣式**：參照 `ledger.php` 的 `@media print`，隱藏側欄與操作按鈕，僅保留報表內容。

## 設計圖表（Mermaid）

```mermaid
flowchart TD
    A[使用者開啟報表頁面] --> B[選擇報表類型：年度/季度]
    B --> C[輸入年份與季度]
    C --> D[驗證輸入參數：ledger_id 年份 季度]
    D --> E{參數有效？}
    E -->|否| F[顯示錯誤訊息，不中斷頁面]
    E -->|是| G[查詢 entries 表，INNER JOIN ledger_access 做 IDOR 防護]
    G --> H{查詢成功？}
    H -->|否| I[error.log 記錄，顯示友善訊息]
    H -->|是| J{有記錄？}
    J -->|否| K[顯示「該期間無記錄」]
    J -->|是| L[formatCents() 格式化金額，h() 轉義所有輸出]
    L --> M[顯示各月份彙總與期間總額]
    style I fill:#ffebee,stroke:#c62828,stroke-width:2px
    style K fill:#fff3e0,stroke:#e65100,stroke-width:2px
    style M fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px
```

## 程式修改路徑

| 檔案 | 修改內容 |
| --- | --- |
| `yearly_report.php`（新增） | 年度報表頁面：<br>1. 頁面頂端呼叫 `requireLogin()`，確保使用者已登入。<br>2. 讀取 `ledger_id`（GET），驗證為整數，透過 `ledger_access` 確認使用者有存取權；不合法時 `redirect('./ledgers.php?error=1')`。<br>3. 檢查帳本是否封存（`archived = 0`），若封存則顯示錯誤。<br>4. 讀取 `year`（GET），驗證為 4 位整數，格式不符時回退至當年。<br>5. 計算起始日期 `YYYY-01-01` 與結束日期 `YYYY-12-31`（Asia/Taipei 時區）。<br>6. 查詢 `entries` 表，加入 `INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id` 做 IDOR 防護，`WHERE e.ledger_id = :ledger_id AND e.deleted = 0 AND e.entry_date BETWEEN :startDate AND :endDate`。<br>7. 以 `strftime('%Y-%m', e.entry_date)` 分組，`SUM(CASE WHEN e.type = ...)` 計算各類總額，`SUM(amount_cents)` 需 `(int)` 強制轉型再傳入 `formatCents()`。<br>8. 金額經 `formatCents()` 格式化，所有輸出經 `h()` 轉義。<br>9. 無記錄時顯示「該年度無記錄」。<br>10. 查詢失敗時 `try/catch (Throwable $e)`，`logError()` 記錄並顯示友善訊息，不中斷頁面。 |
| `quarterly_report.php`（新增） | 季度報表頁面：<br>1. 與 `yearly_report.php` 邏輯相同，但多一個 `quarter` 參數（Q1-Q4 或 1-4）。<br>2. 根據季度計算對應三個月的起迄日期：Q1 = `YYYY-01-01` ~ `YYYY-03-31`，Q2 = `YYYY-04-01` ~ `YYYY-06-30`，Q3 = `YYYY-07-01` ~ `YYYY-09-30`，Q4 = `YYYY-10-01` ~ `YYYY-12-31`。<br>3. 其餘查詢、顯示邏輯與 `yearly_report.php` 相同。 |
| `assets/style.css` | 新增報表頁面樣式（`.report-table`、`.report-total`、`.report-empty`），沿用既有 `.btn`、`.alert`、`.table` 樣式；新增列印樣式（`@media print`），隱藏側欄與操作按鈕，僅保留報表內容。 |
| `add_entry.php`、`edit_entry.php`、`delete_entry.php`、`ledger.php`、`dashboard.php` | 無需修改（報表為獨立頁面，不影響既有功能）。 |
| `db.php` | 無需修改（不新增資料庫表，無 migration）。 |
| 無新增檔案 | 不新增 PHP 頁面以外的檔案，不新增資料庫表，不新增外部依賴。 |

## 資料庫變更

無。報表功能僅讀取既有 `entries` 表，不新增資料庫表，無 migration 需要。

## 前端變更說明

### 年度報表頁面（yearly_report.php）

- **頁面標題**：「年度報表」
- **篩選表單**：年份下拉選單（預設為當年）；帳本選擇（若使用者有多個帳本）。年份／季度／帳本選擇回填時必須經 `h()` 轉義。
- **結果顯示**：
  - 各月份彙總表格：月份（YYYY-MM）、收入、支出、轉帳、結餘。
  - 全年總計：總收入、總支出、總轉帳、總結餘（結餘 = 總收入 − 總支出，轉帳不計入）。
- **空白狀態**：若該年度無記錄，顯示「該年度無記錄」。
- **錯誤狀態**：查詢失敗時顯示友善錯誤訊息，不中斷頁面。

### 季度報表頁面（quarterly_report.php）

- **頁面標題**：「季度報表」
- **篩選表單**：年份、季度（Q1-Q4）下拉選單，預設為當年當季；帳本選擇。
- **結果顯示**：
  - 各月份彙總表格：月份（YYYY-MM）、收入、支出、轉帳、結餘。
  - 季度總計：總收入、總支出、總轉帳、總結餘。
- **空白狀態**：若該季度無記錄，顯示「該季度無記錄」。
- **錯誤狀態**：查詢失敗時顯示友善錯誤訊息，不中斷頁面。

### CSS 樣式（assets/style.css）

```css
/* 年度／季度報表樣式 */
.report-table {
    margin: var(--space-4) 0;
}
.report-total {
    margin: var(--space-4) 0;
    padding: var(--space-4);
    background: var(--paper-2);
    border: 1px solid var(--line);
    border-radius: var(--radius);
}
.report-empty {
    text-align: center;
    padding: var(--space-8);
    color: var(--ink-2);
}
.report-total .label {
    font-weight: 700;
}
.report-total .value {
    font-size: var(--text-xl);
    font-family: var(--font-mono);
    font-weight: 700;
}
/* 列印樣式，參照 ledger.php */
@media print {
    .side, .nav, .ctr-actions, .quick, .font-size-toggle {
        display: none !important;
    }
    .main { margin: 0; padding: 0; }
    .sheet { width: 100%; max-width: 100%; }
    body { background: #fff; color: #000; }
    .plate { border: 1px solid #000; }
    .v { color: #000; }
    .report-table, .report-total { page-break-inside: avoid; }
}
```

## SQL 查詢範例

### 月份彙總查詢（年度/季度通用）

```sql
SELECT
    strftime('%Y-%m', e.entry_date) AS ym,
    SUM(CASE WHEN e.type = 'income' THEN e.amount_cents ELSE 0 END) AS income_cents,
    SUM(CASE WHEN e.type = 'expense' THEN e.amount_cents ELSE 0 END) AS expense_cents,
    SUM(CASE WHEN e.type = 'transfer' THEN e.amount_cents ELSE 0 END) AS transfer_cents
FROM entries e
INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
WHERE e.ledger_id = :ledger_id
  AND e.deleted = 0
  AND e.entry_date BETWEEN :startDate AND :endDate
GROUP BY ym
ORDER BY ym
```

執行後，`SUM(amount_cents)` 回傳字串，需 `(int)` 強制轉型再傳入 `formatCents()`：
```php
$incomeCents = (int) $row['income_cents'];
$expenseCents = (int) $row['expense_cents'];
$transferCents = (int) $row['transfer_cents'];
```

### 餘額計算公式

結餘 = 總收入 − 總支出，轉帳（`transfer`）不計入收入或支出，僅單獨統計。

## 預期行為與驗證方法

| 驗收項 | 預期行為 | 驗證方法 |
| --- | --- | --- |
| 年度報表頁面存在 | 使用者可選擇年份，顯示該年度內各月份及全年總收支 | 開啟 `yearly_report.php?year=2026`，確認顯示2026年各月份及全年總額 |
| 季度報表頁面存在 | 使用者可選擇年份與季度，顯示該季度內各月份及季度總收支 | 開啟 `quarterly_report.php?year=2026&quarter=Q3`，確認顯示2026年第3季各月份及季度總額 |
| 金額格式正確 | 金額經 `formatCents()` 格式化（整數分除以 100、千分位顯示） | 檢查金額顯示是否包含千分位，如「1,234.56」 |
| 所有輸出經 h() 轉義 | 無 XSS 風險 | 檢查 HTML 原始碼，確認特殊字元被轉義 |
| IDOR 防護 | 使用者只能查看其被授權帳本的報表 | 以不同權限使用者存取，確認只能看到授權帳本的資料 |
| 無記錄時顯示提示 | 顯示「該期間無記錄」文字 | 查詢無記錄的年份/季度，確認顯示友善訊息 |
| 查詢失敗不中斷頁面 | 錯誤寫入 `error.log`，顯示友善訊息 | 模擬資料庫錯誤，確認頁面仍渲染 |
| 與現有功能並存 | 不破壞現有 `ledger.php` 月度報表 | 確認 `ledger.php` 仍正常顯示月度報表 |

## 非本期範圍（Out of Scope）

- 不新增多帳本合併報表（每次僅查看單一帳本）。
- 不新增圖表匯出（PDF/Excel）、不新增自動推播通知。
- 不新增預算對比（年度預算設定列為後續版本）。
- 不新增多年度比較圖表。

## 身分驗證與權限模型

不變更既有登入與帳本權限模型。繼續使用 session 登入與 `ledger_access` 權限檢查（owner/member/viewer 均可檢視報表，因為僅為唯讀查詢）。

## 資料傳輸加密標準

無新增外部 API 呼叫，全部為伺服端 SQL 與 session 驗證，資料僅儲存在站台自身 SQLite 資料庫。

## 非功能性需求

- **效能**：年度/季度查詢涉及較多記錄，但 SQLite 與 WAL 模式可接受；單一帳本查詢，效能影響可忽略。
- **併發**：僅讀取查詢，無資料庫寫入競合。
- **資料保留**：不涉及資料寫入，無資料保留問題。
- **可用性**：查詢失敗時不中斷頁面渲染，錯誤寫入 `error.log`。

## 差異與待確認

與 Planning 核心需求一致，所有待確認事項於 Planning 中已鎖定。

### 已釐清事項（原為待確認，現已決定）

1. **頁面架構**：決定為兩個獨立頁面（`yearly_report.php` / `quarterly_report.php`），放在根目錄。
2. **帳本選擇**：使用者有多個帳本時，報表頁面提供帳本下拉選單，讓使用者可選擇查看哪個帳本的報表。

## 設計審查記錄（設計階段）

| 階段 | 狀態 | 日期 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 已通過 | 2026-11-22 | 與 Planning 與現有程式碼一致 |
| Code（程式）審查 | 待審查 | — | 檢查 `yearly_report.php` / `quarterly_report.php` 新增頁面、查詢邏輯、相容性 |
| Security（資安）審查 | 待審查 | — | 檢查 XSS（`h()` 轉義）、SQL 注入（PDO 預備陳述式）、IDOR（`ledger_access` 權限）、CSRF（唯讀查詢不需要） |

實作階段完成後，需在同一份 Design.md 補上「實際變更」與「驗證證據」，並執行實作審查（Spec → Code → Security）。