# [#27] 類別預算頁面開放 member 與 viewer 檢視

> 本文件為規劃文件，不完全是實作依據。

## Description

將類別預算設定頁面（`category_budget.php`）的檢視權限從僅帳本擁有者（owner）開放給帳本成員（member）與檢視者（viewer），使其可檢視類別預算列表，但維持僅 owner 可新增/編輯/停用。

## User Story

作為帳本成員（member）或檢視者（viewer），我希望能檢視帳本的類別預算設定與使用情況，了解各類別預算進度。

## To Reproduce

不適用（非 bug）

## Expected behavior

指向需求與驗收章節。

## Acceptance Criteria

- [ ] member 與 viewer 可開啟 `category_budget.php` 檢視類別預算列表
- [ ] member 與 viewer 不顯示新增/編輯/停用按鈕與表單（僅檢視）
- [ ] member 與 viewer 的 POST 寫入請求被拒絕（redirect 錯誤訊息，不寫入）
- [ ] owner 維持完整新增/編輯/停用權限

## Additional context

- Planning [#19] 權限段原文「member 與 viewer 僅可檢視」，與既有程式「僅 owner 可存取」存在差異（已於 [#19] Design §差異與待確認記錄）
- 待確認：member/viewer 檢視時是否顯示預算金額與使用率（資料裁切範圍）
- 非本期範圍：開放 member/viewer 編輯或新增權限
