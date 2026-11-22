## [#7] CSV 匯入 - 實作階段複查報告

### 條件 1 (ATTR_EMULATE_PREPARES)：**通過**
- 驗證結果：
  - `db.php` line 39：`$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);` 已正確設定於 `getPdo()` 函式中。
  - 全專案僅有一處 `new PDO` 實例化（`db.php` line 36），位於 `getPdo()` 內部。
  - 搜尋全專案 `getPdo()` 呼叫共 63 處，所有資料庫連線皆透過此函式取得，**無其他連線路徑繞過此設定**。
- 備註：設定位置正確，且具備完整的防禦性檢查（`pdo_sqlite` 擴充檢查、目錄建立、WAL 模式、busy_timeout）。

### 條件 2 (最大行數限制)：**通過**
- 驗證結果：
  - `import_csv.php` line 101：`const MAX_CSV_ROWS = 10000;` 常數定義正確。
  - Line 100：`$dataRowCount = 0;` 資料列計數器初始化正確。
  - Lines 110-116：在 `while (($row = fgetcsv($handle)) !== false)` 迴圈中，`$headerSkipped` 為 `true` 時（即標題行之後）遞增 `$dataRowCount`，並檢查是否超過 `MAX_CSV_ROWS`。
  - 超過限制時：設定 `$error` 訊息、執行 `break` 中止迴圈、不繼續處理後續資料列。
  - 邏輯正確性驗證：
    - 標題行（第一列）不計入計數（`$headerSkipped` 為 `false` 時跳過 limit 檢查並 `continue`）。
    - 資料列從第二列開始正確計數（`$dataRowCount` 從 1 遞增至 10000）。
    - 第 10001 筆資料列時 `$dataRowCount` 變為 10001 > 10000，觸發錯誤並中止。
- 備註：實作符合防禦 DoS 需求，錯誤訊息明確告知上限與建議拆分檔案。

### 總結：**全部通過**