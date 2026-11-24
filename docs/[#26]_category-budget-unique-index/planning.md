# [#26] category_budgets 新增資料庫唯一索引

> 本文件為規劃文件，不完全是實作依據。

## Description

為 `category_budgets` 表新增資料庫層級唯一索引 `(ledger_id, category, is_active)`，避免高併發下應用層 INSERT 前檢查重複失敗而寫入重複的類別預算記錄，確保同一帳本同一類別不會有多筆啟用中的預算並存。

## User Story

作為系統，我希望類別預算在資料庫層級防止同一帳本同一類別的重複啟用預算，確保資料完整性。

## To Reproduce

不適用（非 bug）

## Expected behavior

指向需求與驗收章節。

## Acceptance Criteria

- [ ] `db.php` `ensureSchema()` 中為 `category_budgets` 新增唯一索引 `(ledger_id, category, is_active)`（或等效約束）
- [ ] 新舊資料庫皆適用（`CREATE TABLE` 與 `ALTER TABLE` 的雙路徑遷移，比照現有 migration 慣例）
- [ ] 應用層 INSERT 前檢查重複邏輯維持，與 DB 索引互補不衝突
- [ ] 併發寫入重複類別預算時，資料庫不允許重複記錄寫入

## Additional context

- 待確認：唯一索引是否包含 `is_active`（目前應用層僅檢查 `is_active=1` 的重複）
- 待確認：發生唯一衝突時的錯誤導向訊息與行為（Planning [#19] 接受「最後寫入勝出」，是否變更需使用者決定）
- 非本期範圍：硬刪除、開放 member/viewer 檢視
