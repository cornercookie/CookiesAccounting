# [#25] ledger.php 側欄加入類別預算設定連結

> 本文件為規劃文件，不完全是實作依據。

## Description

在 `ledger.php` 帳本詳情頁的側欄「這本帳」區塊，新增「類別預算設定」連結，僅帳本擁有者（owner）可見，讓使用者能從帳本詳情頁直接進入 `category_budget.php` 類別預算設定頁面，進行類別預算的新增/編輯/停用。

## User Story

作為帳本擁有者，我希望在帳本詳情頁側欄能直接點擊進入類別預算設定頁面，方便管理各類別每月預算。

## To Reproduce

不適用（非 bug）

## Expected behavior

指向需求與驗收章節。

## Acceptance Criteria

- [ ] `ledger.php` 側欄「這本帳」區塊顯示「類別預算設定」連結，僅帳本擁有者（owner）可見
- [ ] 連結指向 `./category_budget.php?ledger_id=<該帳本 ID>`
- [ ] 帳本成員（member）與檢視者（viewer）不顯示該連結
- [ ] 點擊連結可正確開啟 `category_budget.php` 類別預算設定頁面

## Additional context

- 相關現有程式：`category_budget.php`（已存在，僅 owner 可存取）
- 待確認：連結文字最終中文名稱，以及是否放置於側欄「這本帳」區塊內或其他位置
- 非本期範圍：`category_budget.php` 本身（已在 [#19] 完成）、資料庫唯一索引、開放 member/viewer 檢視
