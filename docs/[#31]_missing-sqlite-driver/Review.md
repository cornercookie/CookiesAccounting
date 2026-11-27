# [#31] 審查：登入安裝診斷

| 階段 | 面向 | 獨立審查結論 | 證據 |
| --- | --- | --- | --- |
| 設計 | Spec | 通過 | `Design.md` 將 `[FINAL_NOTES]` 缺擴充驗收限定在登入入口的 GET／POST。 |
| 設計 | Code | 通過 | 於 Session 轉址與認證之前判斷，保留既有模板、API 與路由。 |
| 設計 | Security | 通過 | 不開 `display_errors`，不回傳原始例外。 |
| 實作 | Spec | 通過 | `login.php` 缺擴充時回 503、`no-store` 及繁中提示，不顯示登入表單。 |
| 實作 | Code | 通過 | 擴充存在時原轉址與登入分支不變；審查未發現阻塞。 |
| 實作 | Security | 通過 | 缺擴充分支無帳密處理；提示經 `h()` 轉義。 |

靜態結論來自 Codex 唯讀審查，不等同 HTTP 證據。隔離 PHP／SQLite 與 Apache 實跑結果另見 [Design.md](./Design.md)。

永久回歸另經獨立靜態審查，確認正常收集 1 項、測試不改共用 fixture，而且僅複製 PHP 至獨立測試目錄、不接觸營運 SQLite 或 `.env`。獨立紅綠驗證及 34 項整合回歸通過。
