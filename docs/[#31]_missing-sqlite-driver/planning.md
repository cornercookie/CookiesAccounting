<!-- 本文件為規劃文件，不完全是實作依據。 -->
# [#31] 缺少 PDO SQLite 時提供登入診斷

## Description
補足 `docs/FINAL_NOTES.md` 驗收第 9 項：未啟用 `pdo_sqlite` 時，使用者應看到明確訊息而非一般 HTTP 錯誤頁。

## User Story
作為安裝者，我希望登入入口直接說明主機缺少的擴充，以便完成 cPanel 設定。

## To Reproduce
隔離副本以 `php -n`（確定不載入 `pdo_sqlite`）及 `display_errors=0` 啟動。登入頁 GET 回 200；使用有效 CSRF 送出後回 500，未顯示 PDO SQLite 診斷。

## Expected behavior
登入入口在缺少擴充時顯示繁中設定提示，不提供無法使用的登入表單。擴充正常時登入流程不變。

## Acceptance Criteria
- [ ] 缺少擴充時，登入 GET／POST 均回 HTTP 503 並顯示啟用 `pdo_sqlite` 的提示。
- [ ] 不顯示堆疊、主機路徑、密碼表單，不嘗試認證或建立資料庫。
- [ ] 啟用擴充後，既有正常與錯誤登入行為保留。

## Additional context
僅驗證安裝登入入口，不在正式 cPanel 停用擴充；不修改相依 API 的輸出格式。
