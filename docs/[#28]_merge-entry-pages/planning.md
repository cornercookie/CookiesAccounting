# 合併 add_entry.php 與 multi_currency.php — planning.md

## 文件基本資訊

- 文件名稱：合併新增記錄與多幣別記帳頁面規劃
- 版本：v1.0
- 建立日期：2026-11-25
- 更新日期：2026-11-25
- 章節變更摘要：
  - 功能描述：初版
  - 使用者故事：初版
  - 預期行為：初版
  - 業務驗收條件：初版

---

## 功能描述

目前專案存在兩個功能高度相似的頁面：
- `add_entry.php`：標準新增記錄頁面（預設 TWD，無幣別選擇）
- `multi_currency.php`：多幣別記帳專用頁面（含幣別下拉、匯率輸入）

兩者約 85% 代碼重複，維護成本高。本任務規劃將兩者合併為單一頁面，透過查詢參數或幣別選擇切換模式。

---

## 使用者故事

作為開發者/維護者，我想將新增記錄功能整合在單一頁面，減少代碼重複與維護負擔，同時保持使用者體驗一致。

---

## 預期行為

1. **單一入口**：`add_entry.php` 為唯一新增記錄頁面，`multi_currency.php` 重導向或移除
2. **模式切換**：
   - 預設模式：僅顯示金額欄位（隱含 TWD，匯率固定 1.0000）
   - 多幣別模式：用戶選擇非 TWD 幣別時，顯示幣別下拉、匯率輸入、原幣金額欄位
3. **URL 參數**：可選 `?currency=USD` 等參數預選幣別
4. **功能完整保留**：收據上傳、AI 解析、分類選擇、CSRF、權限檢查、審計日誌等既有功能不變
5. **導覽更新**：側邊欄「多幣別記帳」連結指向 `add_entry.php`（或移除，改由頁面內切換）

---

## 業務驗收條件

- [ ] `add_entry.php` 支援幣別切換（TWD/USD/JPY/CNY 等），切換時動態顯示/隱藏匯率輸入
- [ ] `multi_currency.php` 回應 302 重導向至 `add_entry.php`（或 410 Gone）
- [ ] 既有 `add_entry.php` 所有功能保留（收據、AI、分類、語音、CSRF、權限、審計）
- [ ] 既有 `multi_currency.php` 所有功能整合至 `add_entry.php`
- [ ] 側邊欄導覽更新：「多幣別記帳」連結指向 `add_entry.php` 或移除
- [ ] 既有連結（書籤、外部連結）導向 `multi_currency.php` 者能正確重導向
- [ ] 程式碼重複率降低：移除 `multi_currency.php` 檔案，`add_entry.php` 增加內容 < 200 行
- [ ] 現有測試/手動驗證通過：新增 TWD 記錄、新增外幣記錄、編輯記錄、導出匯出

---

## 非本期範圍

- 批次匯入/匯出功能變更
- AI 解析多幣別支援（現有 AI 僅處理 TWD）
- 歷史資料遷移（既有記錄已完成遷移）

---

## 待確認事項

- 是否保留 `multi_currency.php` 檔案（做 302 重導向）還是直接刪除（410 Gone）
- 側邊欄是否保留「多幣別記帳」連結，或改為「新增記錄」單一連結
- URL 參數格式：`add_entry.php?ledger_id=1&currency=USD` vs `add_entry.php?ledger_id=1&mode=foreign&currency=USD`
- 是否需支援從 `multi_currency.php` 帶參數重導向（如 `multi_currency.php?ledger_id=1&currency=USD&amount=100&rate=32.5`）

---

## 技術備註

- 合併策略：將 `multi_currency.php` 的幣別/匯率邏輯遷移至 `add_entry.php`，移除重複的表單渲染、POST 處理、權限檢查代碼
- `formatForeignAmount()`、`formatExchangeRate()`、`parseAmountCents()` 等核心函數已存在，直接複用
- `multi_currency.php` 現有 JS 互動（幣別切換顯示/隱藏匯率）直接移植
- 資料庫結構、核心函數、查詢函數、導出功能均已支援多幣別，無需變更