# Planning: 分類管理頁面（集中維護常用分類）

## Description

新增「分類管理」頁面，讓站台 admin 集中維護全站常用分類清單，並支援將舊分類合併到新分類，解決長時間使用後「餐費、吃飯、餐點」等重複分類導致類別統計不準確的問題。

範圍限於分類清單的新增、編輯、停用與合併；不改變既有 `entries.category` 欄位儲存方式，不新增分類層級（父子分類）。

歸屬決定：**全站共用分類**。`categories` 表的 `ledger_id` 欄位固定為 `NULL`，所有分類由站台 admin 統一維護，降低實作複雜度，避免每個帳本重複設定。

## User Story

- 作為站台管理員，我希望在一個頁面管理全站常用分類，避免家人輸入重複或近似的分類名稱。
- 作為家庭成員，我希望新增記錄時下拉選單的分類清單乾淨，不會出現「餐費」與「吃飯」這種重複項目。

## To Reproduce

本項目非錯誤（bug）類需求，不適用重現步驟。

## Expected behavior

- **分類管理頁面**：新增 `admin/category_management.php`（後台頁面，僅站台 admin 可存取），列出所有啟用中與停用的分類，支援新增、編輯名稱、停用（軟刪除）。
- **分類合併**：選擇一個舊分類並合併到另一個分類時，系統將該舊分類下所有 `entries.category` 批次更新為目標分類，舊分類標記為停用。合併僅限站台 admin 執行，在 transaction 內完成，失敗則回滾。
- **權限控管**：僅站台 admin 可存取 `admin/category_management.php` 並新增/編輯/停用/合併分類。帳本 owner / member / viewer 無法存取此頁面。
- **新增記錄下拉選單**：**本期 `add_entry.php` 與 `edit_entry.php` 維持現有靜態預設分類下拉選單**（餐費、交通、娛樂、醫療、薪資、水電瓦斯、日用品、其他＋自訂輸入），不從 `categories` 表讀取。`categories` 僅供後台管理頁面使用，下拉選單資料來源從靜態清單遷移至 DB 列為後續版本。自訂分類僅寫入 `entries.category` 欄位，不寫入 `categories` 表。
- **既有資料不受影響**：停用分類不會刪除已記錄的 entries，僅影響下拉選單的可選項目。編輯既有記錄時，若其分類已停用，下拉選單仍能預選該舊分類文字（僅作為顯示，不列為可選新增項目）。
- **錯誤處理**：分類名稱重複、長度超過 50 字元、合併到自身、目標分類不存在等情形均應顯示友善錯誤訊息；所有寫入皆有 CSRF 與預備陳述式防護；合併失敗時 transaction 回滾。
- **時間戳寫入**：新增、編輯、停用、合併分類時，應用層需以 `datetime('now','localtime')`（PHP 端預設 Asia/Taipei）寫入 `updated_at`，不依賴 SQLite `datetime('now')`，確保與專案時區約束一致。

## Acceptance Criteria

1. 存在 `admin/category_management.php` 分類管理頁面，可新增、編輯、停用分類，並顯示啟用/停用狀態。
2. 僅站台 admin 可存取分類管理頁面；帳本 owner / member / viewer 存取時導回或顯示無權限。
3. 分類合併後，原舊分類下的所有 `entries` 記錄之 `category` 欄位已更新為目標分類，舊分類設為停用；合併失敗時資料庫不變更（transaction 回滾）。
4. 新增/編輯記錄頁面的分類下拉選單顯示靜態預設分類（餐費、交通、娛樂、醫療、薪資、水電瓦斯、日用品、其他）並保留自訂輸入選項。（本期不從 `categories` 表讀取，遷移列為後續版本。）
5. 分類名稱長度上限 50 字元，重複名稱拒絕新增，所有輸出經 `h()` 轉義。
6. 無權限使用者無法透過直接網址存取分類管理頁面（IDOR 防護）。

## Additional context

- **資料庫結構**：新增 `categories` 資料表，在 `db.php::ensureSchema()` 中使用 `CREATE TABLE IF NOT EXISTS` 建立，並加入索引：

  ```sql
  CREATE TABLE IF NOT EXISTS categories (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      name        TEXT    NOT NULL UNIQUE,                       -- 全站分類名稱唯一
      is_active   INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
      ledger_id   INTEGER DEFAULT NULL,                          -- 全站共用，固定為 NULL
      created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
      updated_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
  );
  CREATE INDEX IF NOT EXISTS idx_categories_is_active ON categories(is_active);
  CREATE INDEX IF NOT EXISTS idx_categories_name ON categories(name);
  ```

  舊資料庫首次執行 `ensureSchema` 時若表不存在即自動建立，不需手動 migration。

- **唯一性規則**：分類名稱在全站範圍內唯一（`UNIQUE(name)`）。因 `ledger_id` 固定為 NULL，不需要 per-ledger 唯一性。若需支援多帳本各自獨立分類，列為後續版本。

- **與既有預設分類的關係**：現有 `add_entry.php` 的靜態預設分類（餐費、交通、娛樂…）與 `categories` 表並存。**本期 `add_entry.php` / `edit_entry.php` 維持靜態清單，不從 `categories` 表讀取下拉選單項目**；後續可視需求將靜態清單遷移至 `categories` 表並從 DB 讀取，遞移時機另訂。

- **分類合併的跨帳本影響**：合併 SQL 必須加上 `AND e.ledger_id = :ledger_id` 條件僅在該帳本內更新，但由於分類為全站共用（`ledger_id` 為 NULL），合併時會更新所有帳本中使用該分類的 entries。此為預期行為（全站共用分類即表示所有帳本共用同一組分類名稱），合併前須向管理員確認。

- **軟刪除後的 entries 統計**：停用分類後，既有 entries 保留原分類文字不受影響，統計頁面仍會計入該分類的歷史記錄。若需從統計中排除停用分類，列為後續版本。

- **非本期範圍**：不新增分類層級（父子分類）、不新增分類預算、不新增分類匯出/匯入、不新增 per-ledger 獨立分類。

- **非功能性需求**：分類表預期資料量極小（每個站數十筆以內），查詢效能可忽略；合併操作在 transaction 內執行，避免併發問題。