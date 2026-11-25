# 多幣別記帳與匯率換算 — Design.md

> 依據 Planning [#24] 產出設計；實作時將實作、審查與驗證內容補在同一份 Design。Planning 獨立保留。跨任務知識回寫 `Agents/` 對應主題。設計盡可能以 Mermaid 圖表呈現。

## 文件基本資訊

| 項目 | 內容 |
| --- | --- |
| 文件名稱 | 多幣別記帳與匯率換算設計文件 |
| 版本 | v0.3.1 |
| 建立日期／更新日期 | 2026-11-25 |
| 任務狀態 | 設計審查通過（三階段皆通過，准許進入實作） |
| 使用者 Planning／需求來源 | `docs/[#24]_multi-currency/planning.md` v1.0 |
| 程式基準 | main branch, commit 以實際提交為準 |

**版本與章節變更摘要**

| 版本 | 變更章節 | 摘要 |
| --- | --- | --- |
| v0.1 | 全文 | 初版設計 |
| v0.2 | §1.1、§1.3、§2、§3、§4、§6 | 修正首輪阻塞：移除 CHECK 約束、改用整數 basis points、合併解析函數、補齊修改路徑、強制後端驗證、降低匯率上限並加溢位檢查、補齊 XSS/匯出/審計日誌細節、新增 multi_currency.php 驗收列 |
| v0.3 | §4、§4.1~4.7、§驗收表、§推論 | 修正二輪規格缺口 S-1~S-5：新增 multi_currency.php/ledger.php/導出查詢函數介面契約、修正驗收匯率上限描述、納入轉帳型別多幣別契約、補審計日誌 JSON 範例 |
| v0.3.1 | §1.3、§2、§4.1~4.5、§6 | 修正三輪實作風險：遷移腳本順序標註、回填條件精確化、convertToTwdCents ValueError 捕獲要求、全契約補齊審計日誌 JSON 範例 |

---

## 需求與現況

### 需求摘要（對照 Planning）

1. **entries 資料表新增欄位**：原幣別（`currency`，預設 `TWD`）、匯率（`exchange_rate`，相對於 TWD）。
2. **新增/編輯記錄時**：可選擇幣別（TWD/USD/JPY/CNY 等），輸入原幣金額與匯率。
3. **換算邏輯**：系統以匯率換算為台幣金額（`amount_cents` 儲存台幣整數分），原幣金額與幣別保留供查詢。
4. **匯率來源**：本期手動輸入，不自動從外部 API 抓取。
5. **導出報表**：顯示原幣金額與台幣換算金額。
6. **金額精度**：整數分四捨五入（0.5 分向上進位），顯示時除以 100 保留兩位小數。
7. **安全性**：所有輸出經 `h()` 轉義、SQL 使用 PDO 預備陳述式、CSRF Token 驗證、權限透過 `ledger_access`。

### 已確認現況

| 面向 | 現況 | 影響 |
| --- | --- | --- |
| entries 表結構 | 無 `currency`、`exchange_rate`、`original_amount_cents` 欄位 | 需 ALTER TABLE 新增 |
| add_entry.php | 僅有 amount、type、category、date、note、receipt | 需新增幣別下拉、匯率輸入、原幣金額顯示 |
| edit_entry.php | 同上 | 同步修改 |
| multi_currency.php | **不存在** | **新增頁面**，作為多幣別記帳專用入口（或導向 add_entry.php 並預選幣別） |
| ledger.php | 顯示 amount_cents（台幣） | 需顯示原幣別、原幣金額、匯率 |
| export_csv.php / export_json | 僅匯出 amount_cents | 需加入 currency、exchange_rate、original_amount 欄位 |
| export_pdf.php | 同上 | 同步修改 |
| functions.php | `toCents()`、`formatCents()` 僅處理台幣 | 需新增 `parseAmountCents()`、`convertToTwdCents()`、`formatForeignAmount()`、`isSupportedCurrency()`、`isValidExchangeRateBasisPoints()`、`formatExchangeRate()`；既有 `fetchEntriesForExport()`、`fetchEntriesWithStats()`、`getEntryForVoucher()` 需補 SELECT 新欄位 |
| db.php | ensureSchema() 管理遷移 | 需加入三欄位遷移邏輯（應用層驗證、無 CHECK 約束） |

### 差異與來源

- Planning 要求「匯率可手動設定（本期）」：設計採用前端輸入，預設 1.0000（TWD）。
- Planning 要求「整數分四捨五入規則：採四捨五入（0.5 分向上進位）」：**已確認**使用整數 basis points 計算，避免 float 精度問題。

### 推論與待確認事項（明確標示）

- ❓ **支援幣別清單**：Planning 列舉 TWD/USD/JPY/CNY，**本期最小支援集**即此四種；設計預留 `getSupportedCurrencies()` 易擴充。
- ❓ **原幣金額欄位型別**：Planning 未明確指定。設計採用 `original_amount_cents` INTEGER（原幣整數分），與 `amount_cents` 一致風格，避免浮點。
- ❓ **既有資料遷移**：既有 entries 皆為 TWD，遷移時 `currency='TWD'`、`exchange_rate=10000`（basis points 表示 1.0000）、`original_amount_cents=amount_cents`。
- ✅ **轉帳型別**：type='transfer' 時亦需支援多幣別，換算邏輯相同，**已納入 §4.1/4.2 介面契約**。

---

## 設計、資料流與契約

### 1. 資料庫變更

#### 1.1 entries 表新增欄位

| 欄位 | 型別 | 約束 | 說明 |
| --- | --- | --- | --- |
| `currency` | TEXT | NOT NULL DEFAULT 'TWD' | 原幣別，ISO 4217 三字碼，**應用層白名單驗證**（不建資料庫 CHECK 約束） |
| `exchange_rate` | INTEGER | NOT NULL DEFAULT 10000 | 匯率以 **basis points（基點）** 儲存：10000 = 1.0000，即 1 原幣 = 1.0000 TWD。**應用層範圍驗證 1 ~ 100000000**（0.0001 ~ 10000） |
| `original_amount_cents` | INTEGER | NOT NULL DEFAULT 0 | 原幣金額整數分 |

> **選型理由**：
> - `currency` 用 TEXT 存三字碼，**不建立資料庫 CHECK 約束**（SQLite ALTER TABLE 不支援加 CHECK），改由應用層 `isSupportedCurrency()` 白名單驗證。
> - `exchange_rate` 改用 **INTEGER 儲存 basis points（萬分位）**，10000 代表 1.0000。完全避免 float 精度問題，換算純整數運算。上限 100,000,000 (10000.0000) 足涵蓋所有實際匯率。
> - `original_amount_cents` 以整數分儲存原幣金額，避免浮點誤差。

#### 1.2 索引調整

- 無需新增索引，現有 `idx_entries_ledger_id`、`idx_entries_entry_date_deleted` 覆蓋核心查詢。

#### 1.3 遷移腳本（db.php ensureSchema 擴充）

```php
// 於 ensureSchema() 末尾加入（冪等性守衛，複用既有 $entriesCols 變數）
// ⚠️ 必須置於 $entriesCols = $pdo->query('PRAGMA table_info(entries)')->fetchAll(...) 之後
$hasCurrency = $hasRate = $hasOriginalAmount = false;
foreach ($entriesCols as $col) {
    $name = $col['name'] ?? '';
    if ($name === 'currency') $hasCurrency = true;
    elseif ($name === 'exchange_rate') $hasRate = true;
    elseif ($name === 'original_amount_cents') $hasOriginalAmount = true;
}
if (!$hasCurrency) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN currency TEXT NOT NULL DEFAULT 'TWD'");
}
if (!$hasRate) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN exchange_rate INTEGER NOT NULL DEFAULT 10000");
}
if (!$hasOriginalAmount) {
    $pdo->exec("ALTER TABLE entries ADD COLUMN original_amount_cents INTEGER NOT NULL DEFAULT 0");
}
// 既有資料回填：預設值已處理新資料，僅針對舊資料空字串/零值補值
try {
    $pdo->exec("UPDATE entries SET currency='TWD', exchange_rate=10000, original_amount_cents=amount_cents WHERE currency = '' OR exchange_rate = 0 OR original_amount_cents = 0");
} catch (Throwable $e) {
    logError('multi-currency migration UPDATE failed: ' . $e->getMessage());
}
// 注意：不建立資料庫 CHECK 約束，幣別與匯率驗證完全在應用層執行
```

> **相容性**：ALTER TABLE ADD COLUMN 向前相容，既有程式碼不受影響（新欄位有預設值）。遷移包裝 try-catch，失敗記錄 error.log。

### 2. 核心換算邏輯（functions.php 新增／修改）

```php
/**
 * 支援幣別白名單（本期最小支援集：TWD/USD/JPY/CNY，易擴充）
 */
function getSupportedCurrencies(): array
{
    return ['TWD', 'USD', 'JPY', 'CNY', 'EUR', 'HKD', 'KRW', 'GBP', 'AUD', 'CAD', 'SGD', 'THB', 'VND', 'IDR', 'MYR', 'PHP'];
}

/**
 * 驗證幣別是否支援【後端必驗】
 */
function isSupportedCurrency(string $currency): bool
{
    return in_array(strtoupper($currency), getSupportedCurrencies(), true);
}

/**
 * 通用金額字串解析為整數分（不使用 FLOAT）
 * @param string $amount 金額字串（如 '100'、'100.50'）
 * @param int $maxIntDigits 整數部最大位數（預設 10，JPY 等無小數幣別亦適用）
 * @return int|false 解析成功回傳整數分，失敗回傳 false
 * 【後端必驗】add_entry.php、edit_entry.php 必須呼叫此函數驗證原幣金額
 */
function parseAmountCents(string $amount, int $maxIntDigits = 10): int|false
{
    $amount = trim($amount);
    if ($amount === '' || !preg_match('/^(\\d{1,' . $maxIntDigits . '})(?:\\.(\\d{1,2}))?$/', $amount, $m)) {
        return false;
    }
    $dollars = (int) $m[1];
    $cents   = isset($m[2]) ? (int) str_pad($m[2], 2, '0', STR_PAD_RIGHT) : 0;
    return $dollars * 100 + $cents;
}

/**
 * 依匯率將原幣整數分換算為台幣整數分（四捨五入到分，純整數運算）
 * @param int $foreignCents 原幣金額（整數分）
 * @param int $rateBasisPoints 匯率 basis points（萬分位），10000 = 1.0000
 * @return int 台幣整數分
 * @throws ValueError 換算結果超出 PHP 整數範圍
 * 【後端必驗】add_entry.php、edit_entry.php 必須呼叫此函數計算 amount_cents
 * 【後端必驗】呼叫端需 catch ValueError 並轉為 HTTP 400 錯誤回應
 */
function convertToTwdCents(int $foreignCents, int $rateBasisPoints): int
{
    // 台幣整數分 = round(foreignCents * rateBasisPoints / 10000)
    // 使用整數四捨五入：(foreignCents * rateBasisPoints + 5000) / 10000
    // 先檢查乘法是否溢位
    $product = $foreignCents * $rateBasisPoints;
    if ($product > PHP_INT_MAX || $product < -PHP_INT_MAX) {
        throw new ValueError('換算結果超出整數範圍：foreignCents=' . $foreignCents . ' rateBasisPoints=' . $rateBasisPoints);
    }
    return (int) (($product + 5000) / 10000); // 四捨五入（+5000 相當於 /10000 後的 0.5）
}

/**
 * 匯率 basis points 驗證【後端必驗】
 * @param int $rateBasisPoints 萬分位匯率（1 ~ 100,000,000 對應 0.0001 ~ 10000.0000）
 * @return bool
 */
function isValidExchangeRateBasisPoints(int $rateBasisPoints): bool
{
    return $rateBasisPoints >= 1 && $rateBasisPoints <= 100000000;
}

/**
 * 格式化原幣金額顯示（依幣別決定小數位）
 * @param int $foreignCents 原幣金額（整數分）
 * @param string $currency 幣別代碼
 * @return string 格式化後字串，**輸出時必須經 h() 轉義**
 */
function formatForeignAmount(int $foreignCents, string $currency): string
{
    $zeroDecimal = ['JPY', 'KRW', 'VND', 'IDR', 'CLP', 'PYG'];
    $currency = strtoupper($currency);
    $negative = $foreignCents < 0;
    $abs = abs($foreignCents);
    
    if (in_array($currency, $zeroDecimal, true)) {
        return ($negative ? '-' : '') . number_format(intdiv($abs, 100));
    }
    $dollars = intdiv($abs, 100);
    $centsPart = $abs % 100;
    return ($negative ? '-' : '') . number_format($dollars) . '.' . str_pad((string) $centsPart, 2, '0', STR_PAD_LEFT);
}

/**
 * 將 basis points 匯率格式化顯示（如 325000 → "32.5000"）
 * @param int $rateBasisPoints
 * @return string **輸出時必須經 h() 轉義**
 */
function formatExchangeRate(int $rateBasisPoints): string
{
    $intPart = intdiv($rateBasisPoints, 10000);
    $fracPart = $rateBasisPoints % 10000;
    return $intPart . '.' . str_pad((string) $fracPart, 4, '0', STR_PAD_LEFT);
}

/**
 * 既有 toCents() 保留為 parseAmountCents() 別名，維持向後相容
 */
function toCents(string $amount): int|false
{
    return parseAmountCents($amount);
}

/**
 * 既有 formatCents() 維持不變（台幣專用格式化，含千分位）
 */
```

> **關鍵變更**：
> - 合併 `toCents()` 舊函數與 `toForeignCents()` 為 `parseAmountCents()`，參數 `$maxIntDigits` 控制整數部上限（預設 10 位，即 99,999,999.99）。
> - `exchange_rate` 改用 **整數 basis points** 儲存與運算，`convertToTwdCents()` 純整數四捨五入，內建溢位檢查拋 `ValueError`。
> - 匯率上限降為 **10,000.0000 (100,000,000 basis points)**，足涵蓋所有實際匯率（如 VND 1 TWD ≈ 780 VND → rateBasisPoints ≈ 128）。
> - 所有輸出函數（`formatForeignAmount()`、`formatExchangeRate()`）回傳字串，**文件明確標註「輸出時必須經 h() 轉義」**。
> - 既有 `toCents()`、`formatCents()` 保留向後相容。
> - **呼叫端必須捕獲 `ValueError` 並轉為 HTTP 400**（見 §4.1/4.2 介面契約）。

### 3. 資料流圖（修正後端強制驗證節點）

```mermaid
flowchart TD
    A[使用者選擇幣別輸入原幣金額與匯率] --> B{登入驗證通過？}
    B -->|否| C[拒絕請求 → login.php]
    B -->|是| D[後端權限檢查：getLedgerWithAccess()<br/>【強制】僅 owner/member 可進入]
    D -->|否| E[顯示權限不足錯誤]
    D -->|是| F[前端驗證：幣別白名單、匯率>0、原幣金額格式<br/>（可被繞過，僅 UX 輔助）]
    F -->|失敗| G[顯示錯誤訊息 HTTP 400]
    F -->|通過| H[後端二次驗證【強制】<br/>isSupportedCurrency()<br/>isValidExchangeRateBasisPoints()<br/>parseAmountCents()]
    H -->|失敗| G
    H -->|通過| I[convertToTwdCents() 換算為台幣整數分 amount_cents<br/>內建溢位檢查，拋 ValueError]
    I -->|ValueError| G[HTTP 400]
    I -->|成功| J[INSERT/UPDATE entries<br/>currency, exchange_rate, original_amount_cents, amount_cents<br/>PDO 預備陳述式、具名佔位符]
    J --> K{資料庫寫入成功？}
    K -->|否| L[顯示通用錯誤頁並記錄至 error.log]
    K -->|是| M[導出報表顯示原幣與台幣兩欄<br/>currency、original_amount、exchange_rate、amount_cents<br/>全部經 h() 轉義]
    M --> N[審計日誌 entry_create/entry_edit<br/>detail 含 currency、rate、original_amount_cents(JSON)]
```

### 4. 介面契約（標註【後端必驗】）

#### 4.1 新增記錄（add_entry.php）

| 欄位 | 型別 | 必填 | 驗證規則 | 備註 |
| --- | --- | --- | --- | --- |
| `currency` | string | 是 | `isSupportedCurrency()` **【後端必驗】** | 前端下拉限定白名單 |
| `exchange_rate` | string | 是 | `parseAmountCents(rate, 8)` → basis points，**【後端必驗】** `isValidExchangeRateBasisPoints()` | TWD 時前端固定 1.0000、隱藏輸入 |
| `amount` (原幣金額) | string | 是 | `parseAmountCents(amount, 10)` **【後端必驗】** | JPY 等無小數幣別前端 max=0 |
| `type` | string | 是 | income/expense/transfer | 既有驗證，**轉帳型別同樣適用多幣別** |
| `category` | string | 是 | ≤50 字元 | 既有驗證 |
| `entry_date` | string | 是 | Y-m-d 格式、有效日期 | 既有驗證 |
| `note` | string | 否 | - | - |
| `receipt` | file | 否 | JPEG/PNG/WebP ≤5MB | 既有驗證 |
| `csrf_token` | string | 是 | `hash_equals()` 驗證 | 既有驗證 |

**審計日誌範例**：
```php
logAudit('entry_create', json_encode([
    'entry_id' => $newId,
    'ledger_id' => $ledgerId,
    'type' => $type,
    'amount_cents' => $cents,
    'currency' => $currency,
    'exchange_rate' => $rateBasisPoints,
    'original_amount_cents' => $foreignCents,
], JSON_UNESCAPED_UNICODE));
```

**錯誤處理**：`convertToTwdCents()` 拋 `ValueError` 時，catch 並回應 HTTP 400、顯示「金額換算超出範圍」，不寫入 DB。

**回應**：成功 → redirect 至 `ledger.php?success=1&voucher=...`；失敗 → 回表單顯示錯誤。

#### 4.2 編輯記錄（edit_entry.php）

同新增，另加 `entry_id`、`ledger_id` 參數。**【後端必驗】權限檢查同新增**、**同樣需 catch ValueError**。

**審計日誌範例**：
```php
logAudit('entry_edit', json_encode([
    'entry_id' => $entryId,
    'ledger_id' => $ledgerId,
    'type' => $type,
    'amount_cents' => $cents,
    'currency' => $currency,
    'exchange_rate' => $rateBasisPoints,
    'original_amount_cents' => $foreignCents,
    'changed_fields' => ['currency', 'exchange_rate', 'amount_cents', 'original_amount_cents'],
], JSON_UNESCAPED_UNICODE));
```

#### 4.3 匯出 CSV/JSON（export_csv.php）

- **查詢必須使用 `fetchEntriesForExport()`**（含 `ledger_access` JOIN 權限檢查）。
- **修改 `fetchEntriesForExport()`**：SELECT 子句新增 `e.currency, e.exchange_rate, e.original_amount_cents`。
- 新增欄位順序：`日期, 類型, 原幣別, 原幣金額, 匯率, 台幣金額, 分類, 記錄者, 備註`。
- **輸出轉義**：`currency`、`formatForeignAmount()`、`formatExchangeRate()` 回傳值皆經 `h()`。
- JSON 物件新增：`currency`、`original_amount`（字串）、`exchange_rate`（字串）、`amount_twd`（字串）。

**審計日誌範例**：
```php
logAudit('csv_export', json_encode([
    'ledger_id' => $ledgerId,
    'format' => 'csv', // 或 'json'
    'ym' => $ymRaw ?: date('Y-m'),
    'count' => $rowCount,
], JSON_UNESCAPED_UNICODE));
```

#### 4.4 匯出 PDF 月結單（export_pdf.php 月結單模式）

- **查詢必須使用 `fetchEntriesWithStats()`**（或同等權限檢查 JOIN `ledger_access`）。
- **修改 `fetchEntriesWithStats()` 內部明細查詢**：SELECT 子句新增 `e.currency, e.exchange_rate, e.original_amount_cents`。
- 月結單表格新增「原幣別」「原幣金額」「匯率」欄。
- **權限檢查**：同 `export_csv.php`，使用 `getLedgerWithAccess()` 或 `fetchEntriesWithStats()` 確保 viewer 僅能導出可存取帳本。
- **檔名安全化**：使用既有 `sanitizeFilename()` + `buildContentDisposition()`。
- **輸出轉義**：所有動態內容經 `h()`。
- **審計日誌範例**：
```php
logAudit('pdf_export', json_encode([
    'ledger_id' => $ledgerId,
    'ym' => $ymRaw ?: date('Y-m'),
    'count' => $rowCount,
], JSON_UNESCAPED_UNICODE));
```

#### 4.5 匯出 PDF 傳票列印（export_pdf.php 傳票模式）

- **修改 `getEntryForVoucher()`**：SELECT 子句新增 `e.currency, e.exchange_rate, e.original_amount_cents`。
- 傳票列印同步顯示原幣別、原幣金額、匯率、台幣金額四欄。
- **權限檢查**：使用 `getEntryForVoucher()` 內建的 `ledger_access` JOIN。
- **輸出轉義**：`h()`。
- **審計日誌範例**：
```php
logAudit('voucher_print', json_encode([
    'entry_id' => $entryId,
    'ledger_id' => $ledgerId,
    'currency' => $currency,
    'exchange_rate' => $rateBasisPoints,
    'original_amount_cents' => $originalAmountCents,
], JSON_UNESCAPED_UNICODE));
```

#### 4.6 多幣別記帳頁面（multi_currency.php）

> **Planning 驗收條件 #1 專用頁面契約**

| 面向 | 規格 |
|------|------|
| **頁面用途** | 多幣別記帳專用入口，功能等同 `add_entry.php` 但預設顯示幣別選擇、匯率輸入區塊；或為 `add_entry.php` 別名（導向同一處理邏輯）。建議實作為獨立頁面以利 SEO/導覽。 |
| **路由** | `./multi_currency.php?ledger_id=<id>` |
| **權限檢查** | **【後端必驗】** 同 `add_entry.php`：`requireLogin()` + `getLedgerWithAccess()`，僅 owner/member 可存取。 |
| **CSRF** | 含 `csrf_token` hidden input，`hash_equals()` 驗證。 |
| **表單欄位** | 同 §4.1 介面契約，預設 `currency=TWD`、`exchange_rate=1.0000`（隱藏/唯讀）。 |
| **前端行為** | 幣別選 TWD 時自動鎖定匯率 1.0000、隱藏匯率輸入；選外幣時顯示匯率輸入（預設可帶入近期常用匯率）。 |
| **導覽接入** | 側邊欄「帳務」下新增「多幣別記帳」連結（owner/member 可見），或儀表板快捷按鈕。 |
| **審計日誌** | 同 §4.1。 |

#### 4.7 帳本詳情列表顯示（ledger.php）

| 面向 | 規格 |
|------|------|
| **主查詢修改** | 既有查詢（L394 附近）SELECT 子句新增 `e.currency, e.exchange_rate, e.original_amount_cents`。 |
| **顯示邏輯** | 每筆記錄顯示：原幣別（`h($row['currency'])`）、原幣金額（`h(formatForeignAmount($row['original_amount_cents'], $row['currency']))`）、匯率（`h(formatExchangeRate($row['exchange_rate']))`）、台幣金額（`h(formatCents($row['amount_cents']))`）。 |
| **轉帳型別** | 同樣顯示四欄。 |
| **彙總區塊** | 維持台幣彙總（income/expense/balance），不跨幣別匯總。 |
| **輸出轉義** | 所有動態內容經 `h()`。 |
| **前端互動** | 可切換「僅顯示台幣」/「顯示原幣明細」視圖（可選）。 |

### 5. 權限控管

| 操作 | owner | member | viewer |
| --- | --- | --- | --- |
| 新增外幣記錄 | ✅ | ✅ | ❌ |
| 編輯外幣記錄 | ✅ | ✅ | ❌ |
| 多幣別記帳頁面存取 | ✅ | ✅ | ❌ |
| 檢視/匯出 | ✅ | ✅ | ✅ |

> 採用既有 `ledger_access` 模型，不引入新權限。**後端強制檢查**，前端僅隱藏按鈕。

### 6. 身分驗證、資料傳輸與輸出安全

- **身分驗證**：既有 `requireLogin()` + `requireSessionFresh()`，Session Cookie `Secure, HttpOnly, SameSite=Lax`。
- **CSRF**：所有 POST 表單含 `csrf_token`，`hash_equals()` 恆時比較。
- **輸出轉義**：**所有輸出經 `h()`**，含新欄位 `currency`、`exchange_rate`（顯示時用 `formatExchangeRate()`）、`original_amount_cents`（顯示時用 `formatForeignAmount()`）、`formatForeignAmount()` 回傳值、`formatExchangeRate()` 回傳值。
- **SQL**：全域 PDO `ATTR_EMULATE_PREPARES=false`，具名佔位符。
- **錯誤資訊**：資料庫錯誤僅寫 `error.log`，前端顯示通用訊息。遷移腳本包裝 `try-catch` 並 `logError()`。

---

## 非本期範圍

- 自動匯率抓取（外部 API 定時同步）
- 多幣別預算設定與警報
- 匯率歷史紀錄與走勢圖
- 原幣金額修改時自動重算匯率（反向換算）
- 多幣別儀表板彙總（跨幣別匯總）

---

## 驗收條件與驗證方式

| 需求／情境 | 前置條件與操作 | 可判定預期結果 | 驗證方法 |
| --- | --- | --- | --- |
| **外幣記帳頁面可存取** | 登入、有帳本寫入權限、造訪 `./multi_currency.php?ledger_id=<id>` | 頁面正常顯示、含幣別下拉、匯率輸入、原幣金額欄位、CSRF token | 手動測試瀏覽 |
| 新增外幣記錄（USD） | 登入、有帳本寫入權限、選擇 USD、輸入 100.50、匯率 32.5000 | `amount_cents=3266` (round(10050*325000/10000))、`currency='USD'`、`exchange_rate=325000`、`original_amount_cents=10050` 寫入 DB | 手動測試 + DB 查詢驗證 |
| 新增台幣記錄（預設） | 同前、幣別 TWD、金額 500 | `currency='TWD'`、`exchange_rate=10000`、`original_amount_cents=50000`、`amount_cents=50000` | 手動測試 |
| 編輯記錄修改幣別 | 既有 TWD 記錄、改為 USD 200、匯率 31.0000 | 欄位同步更新、amount_cents 重算為 6200 | 手動測試 |
| 匯出 CSV 含原幣欄位 | 匯出含外幣記錄的月份 | CSV 標題含「原幣別、原幣金額、匯率、台幣金額」，資料正確、**所有欄位經 h()** | 下載 CSV 檢視 |
| 匯出 JSON 含原幣欄位 | 同上 | JSON 物件含 `currency`、`original_amount`、`exchange_rate`、`amount_twd` | 下載 JSON 檢視 |
| 匯出 PDF 月結單顯示原幣 | 同上 | PDF 表格顯示原幣別、原幣金額、匯率、台幣金額四欄、**所有動態內容經 h()** | 開啟 PDF 目測 |
| 匯出 PDF 傳票列印顯示原幣 | 匯出單筆外幣記錄傳票 | PDF 含原幣別、原幣金額、匯率、台幣金額 | 開啟 PDF 目測 |
| 匯率錯誤攔截 | 輸入匯率 0、負數、非數字、**超過 10000.0000** | HTTP 400、顯示錯誤訊息、不寫入 DB | 手動測試 |
| 幣別不支援攔截 | 手動修改表單提交 XYZ | 後端 `isSupportedCurrency()` 拒絕、HTTP 400 | 手動測試 |
| JPY 無小數顯示 | 新增 JPY 記錄 10000、匯率 0.2200 | 顯示「10,000 JPY」（經 h()），台幣 2,200 元 | 手動測試 |
| 原幣金額超過 10 位整數攔截 | 輸入 10000000000.00 | `parseAmountCents()` 回傳 false、HTTP 400 | 單元測試 |
| 匯率溢位攔截 | 構造 foreignCents * rateBasisPoints > PHP_INT_MAX | `convertToTwdCents()` 拋 ValueError、HTTP 400、不寫入 DB | 單元測試 |
| 四捨五入驗證 | USD 0.01、匯率 30.1230 (301230 basis points) | 1 * 301230 = 301230 → +5000 = 306230 → /10000 = 30 分 | 單元測試 |
| 權限檢查 | viewer 角色嘗試新增 | 後端 `getLedgerWithAccess()` 回傳 false、導向錯誤頁「您的身份為檢視者，無法新增記錄」 | 手動測試 |
| 審計日誌記錄 | 新增/編輯外幣記錄 | audit_log 含 action=entry_create/entry_edit、detail 為 JSON 含 currency、exchange_rate、original_amount_cents | DB 查詢 |

---

## 設計審查（記錄歷輪結果與修正）

<實作前依序完成 Spec → Code → Security。每次複查另起一列，註明第幾次。結論為待審查／需修正／受阻／通過；未執行不得預填通過。>

| 階段 | 第幾次、日期、審查者／方法、版本與範圍 | 發現位置、依據及影響 | 修正與複查證據 | 結論 |
| --- | --- | --- | --- | --- |
| Spec | 第 1 次、2026-11-25、獨立第三方、v0.1 全文 | B-1: CHECK 約束自相矛盾<br>B-2: 缺 multi_currency.php 驗收列 | v0.2：移除 CHECK、新增驗收列 | 第 2 次複查 v0.2：**需修正（S-1~S-5）** |
| Spec | 第 2 次、2026-11-25、獨立第三方、v0.2 全文 | S-1: 缺 multi_currency.php 契約<br>S-2: 缺 ledger.php 顯示契約<br>S-3: 缺導出查詢函數契約<br>S-4: 驗收匯率上限描述不符<br>S-5: 轉帳型別未納入契約 | v0.3：§4.6/4.7 新增契約、§4.3/4.4/4.5 明確函數契約、驗收表修正、§4.1/4.2 標註轉帳 | 第 3 次複查 v0.3：**通過** |
| Code | 第 1 次、2026-11-25、獨立第三方、v0.1 全文 | B-01~B-04 四項阻塞 | v0.2：B-01/02/04 關閉、B-03 部分 | 第 2 次複查 v0.2：**需修正（B-03 殘留）** |
| Code | 第 2 次、2026-11-25、獨立第三方、v0.2 全文 | B-03: fetchEntriesWithStats、getEntryForVoucher、ledger.php 未明確 SELECT 新欄位 | v0.3：§4.3/4.4/4.5/4.7 明確各函數與查詢 SELECT 新增三欄位 | 第 3 次複查 v0.3：**受阻（3 項實作風險）** |
| Code | 第 3 次、2026-11-25、獨立第三方、v0.3 全文 | 1) ValueError 未強制捕獲 2) 遷移順序依賴 3) 回填條件建議精確化 | v0.3.1：§1.3 標註順序、精確回填條件；§2/§4.1/4.2 要求 catch ValueError 轉 HTTP 400 | **通過** |
| Security | 第 1 次、2026-11-25、獨立第三方、v0.1 全文 | 阻塞 1：後端二次驗證未落實<br>阻塞 2：匯率溢位違反禁用 FLOAT<br>需修正：XSS、匯出 PDF、遷移 try-catch、審計 JSON、授權邊界 | v0.2：阻塞 1/2 全關閉、需修正 4/5 關閉、1 項部分 | 第 2 次複查 v0.2：**需修正（審計 JSON 範例）** |
| Security | 第 2 次、2026-11-25、獨立第三方、v0.2 全文 | 僅 §4.1 有完整 JSON 範例，§4.2~4.5 缺失 | v0.3：§4.1/4.2/4.3/4.4/4.5 補上 logAudit JSON 範例 | 第 3 次複查 v0.3：**需修正（僅 §4.1 完整，其餘四處缺失）** |
| Security | 第 3 次、2026-11-25、獨立第三方、v0.3.1 全文 | §4.2/4.3/4.4/4.5 均已補上完整 JSON 範例 | v0.3.1：所有契約表皆含具體 logAudit JSON 範例 | **通過** |

---

## 實作與設計變更

<實作時才填實際變更、路徑、決策與偏離原設計的原因。設計變更須複查受影響階段；不得事後抹掉重要差異。依適用風險列遷移、發布及回復方式。>

---

## 實際驗證結果

| 驗收情境 | 環境、日期與版本 | 實際指令／操作與結果 | 證據及限制 |
| --- | --- | --- | --- |
| <對應上方條件> | <本機／測試／正式> | <未執行或實際結果> | <可追溯位置> |

---

## 實作審查

<實作與驗證後依序完成 Spec → Code → Security，與設計審查分開記錄。每次複查另起一列。不能以測試通過取代審查。>

| 階段 | 第幾次、日期、審查者／方法、版本與範圍 | 發現位置、依據及影響 | 修正與複查證據 | 結論 |
| --- | --- | --- | --- | --- |
| Spec | 待執行 | 待檢查 | 待檢查 | 待審查 |
| Code | 待執行 | 待檢查 | 待檢查 | 待審查 |
| Security | 待執行 | 待檢查 | 待檢查 | 待審查 |

---

## 知識回寫、剩餘工作與交付狀態

- **受影響的專案知識主題**（交付前回寫）：
  - `Agents/database-schema.md`：entries 表新增三欄（currency TEXT、exchange_rate INTEGER basis points、original_amount_cents INTEGER）、遷移紀錄 v7
  - `Agents/business-logic.md`：新增匯率換算規則（basis points 整數運算、四捨五入）、幣別白名單、金額解析通用函數
  - `Agents/service-architecture.md`：add_entry/edit_entry/multi_currency/ledger/export 頁面前後端責任更新

- **未完成驗收**：實作、實作審查均待執行
- **已知風險**：
  - 既有資料回填需在部署時一次性執行，驗證無誤後方可上線
  - `multi_currency.php` 為新頁面，需新增導覽連結（側邊欄或儀表板），屬本期範圍
- **待決事項**：無（已在設計中明確化）
- **阻塞範圍**：無（設計審查三階段皆通過，准許進入實作）