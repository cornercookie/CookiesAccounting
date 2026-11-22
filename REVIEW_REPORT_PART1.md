# 第三方專家審查報告（Part 1 / 4）

## 總體評語：需修正

四份 planning.md 範圍皆未與現有核心約束衝突，大致可執行，但 **recurring-entries 與 login-anomaly-notice 的 db.php migration 描述模糊、缺乏舊資料庫遷移邏輯細節，列為阻塞項；quick-entry-assist 與 ledger-statistics 尚有權限邊界與 audit_log 欠缺之處，列為 Major**。全部修正後可進入實作。

審查維度：Spec / Code / Security，基於現有 PHP + SQLite 架構與九項硬性約束進行推斷式審查，未讀取實際程式碼差異（規劃文件為審查對象）。

---

## 1. docs/quick-entry-assist/planning.md

### 發現 Q-1（Major）：字級切換偏好儲存機制未定義
- 檔案位置：`docs/quick-entry-assist/planning.md`
- 章節：Additional context / 待確認（第 41 行）
- 影響：planning 僅說明「偏好於 session 或 Cookie 中保留」，未決定哪一種。若用 session，瀏覽器關閉後字級偏好遺失，與「下次登入仍維持」的 Expected behavior（第 27 行）矛盾；若用 Cookie，需定義 Cookie 名稱、路徑、過期時間、HttpOnly/SameSite 屬性，否則可能洩漏偏好或被 CSRF 利用。
- 嚴重度：Major
- 修正建議：明確指定儲存方式（建議 Cookie，設定 HttpOnly、SameSite=Lax、合理過期），並在 planning 的待確認與資料流章節補上具體實作細節；若採 session，需說明「下次登入維持」如何達成（例如寫入 users 表或獨立偏好表）。
- 阻塞項：否（可列為建議，需開發者決定）

### 發現 Q-2（Minor）：語音回饋播報時機與重複播放邏輯未定義
- 檔案位置：`docs/quick-entry-assist/planning.md`
- 章節：Additional context / 待確認（第 41 行）與 Expected behavior（第 28 行）
- 影響：Expected behavior 說明「導回帳本詳情頁後自動播報」與「使用者點擊儲存按鈕後立即播放」兩種時機並存；且「靜音或關閉標籤後不重複播放」需 session 標記，planning 未說明如何追蹤已播放狀態，可能導致重複播報或完全不播報。
- 嚴重度：Minor
- 修正建議：敲定播報時機（建議：導回 ledger.php 後，透過 session 標記 `voice_played=1` 僅播放一次，避免重複刷新重複播報），並補入 planning。
- 阻塞項：否

### 發現 Q-3（Minor）：預設分類寫入 entries.category 無前端/後端白名單驗證
- 檔案位置：`docs/quick-entry-assist/planning.md`
- 章節：Expected behavior（第 25 行）、非本期範圍（第 42 行）
- 影響：下拉選單預設分類與自訂輸入共用同一 `category` 欄位，後端未列明對自訂分類的長度上限、非法字元過濾，可能導致統計圖表（ledger-statistics Feature 10）出現異常類別標籤或 XSS 雖經 h() 轉義但資料髒污。
- 嚴重度：Minor
- 修正建議：在 planning 資料流章節補上 category 欄位長度上限（建議 50 字元）與後端 trim 驗證；預設分類清單以常數陣列定義，自訂輸入同樣經 h() 輸出與 toCents 無關（文字欄位）。
- 阻塞項：否

### 發現 Q-4（Minor）：CSRF 權限檢查路徑未說明新增頁面修改點
- 檔案位置：`docs/quick-entry-assist/planning.md`
- 章節：資料流（第 43 行）、身分驗證與權限（第 44 行）
- 影響：planning 說明「後端驗證 CSRF 與權限」但未指明需修改 `add_entry.php` 與 `edit_entry.php` 的哪些區塊（例如類別欄位由 `<input type="text">` 改為 `<select>` + 自訂輸入，需保留 csrf_token 隱藏欄位），實作時易遺漏。
- 嚴重度：Minor
- 修正建議：在 planning 新增「程式修改路徑」小節，列明 `add_entry.php`、`edit_entry.php` 需調整的表單 HTML 與後端驗證邏輯。
- 阻塞項：否

---

（續於 Part 2：ledger-statistics 與 recurring-entries 發現）
