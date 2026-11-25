# Security 審查報告（設計階段重審） — [#23]_export-more-formats

## 審查基本資訊

| 項目 | 內容 |
| --- | --- |
| 任務編號 | [#23] 匯出更多格式 |
| 審查文件 | Design.md v1.2 |
| 審查日期 | 2026-11-25 |
| 審查員 | 獨立第三方資安審查員 |
| 審查階段 | 設計階段 Security 審查（重審） |
| 依據 Planning | `docs/[#23]_export-more-formats/planning.md` |

---

## 執行摘要

**最終判定：✅ 通過**

Design.md v1.2 已完整修正上一輪 Security 審查的所有 Critical / High / Medium / Low 項目，並符合專案硬性約束與安全基線。所有查詢使用 PDO 預備陳述式、具名佔位符；XSS 防護完整（CSV/PDF 經 `h()`、JSON 由 `json_encode` 編碼）；IDOR 由 `ledger_access` 完整防護；CSV 公式注入防護落實；錯誤資訊不外洩（友善錯誤頁 + `logError` 記錄細節）。

---

## 上一輪 Security 審查項目修正驗證

### Critical 項目

#### SEC-01：CSRF 防護決議 —— GET 匯出端點豁免 CSRF，理由文件化
| 驗證點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 決議明確記載 | 第 25 條、第 30 條、第 43 條、第 124~125、156、327~334 條：**GET 匯出端點豁免 CSRF Token 驗證** | ✅ |
| 理由完整（4 點） | 1. 唯讀操作不改變伺服器狀態<br>2. `ledger_access` IDOR 防護為主防線<br>3. 符合 OWASP「GET 不應改變狀態」原則<br>4. 直接連結、書籤、分享需正常運作（家庭分享場景） | ✅ |
| 實作對應 | `export_csv.php`、`export_pdf.php` 不驗證 `csrf_token`；`ledger.php` 連結不附加 `csrf_token` | ✅ |
| 專案約束說明 | 註明「專案硬性約束『所有狀態變更 POST 請求皆需 CSRF Token』不適用於此唯讀 GET 端點」 | ✅ |

**結論：完全修正，決議與理由文件化完整。**

---

### High 項目

#### SEC-02：檔名注入防護 —— `sanitizeFilename()`、`buildContentDisposition()` 雙重標頭、防標頭注入
| 驗證點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| `sanitizeFilename()` 移除控制字元/路徑遍歷 | 第 60、278~282 條：`preg_replace('/[^\p{L}\p{N} _-]/u', '', $name)` 移除非字母數字底線連字號空格，另 `preg_replace('/[\x00-\x1F\x7F]/', '', $name)` 移除控制字元，限制 100 字 | ✅ |
| `buildContentDisposition()` 雙重標頭 | 第 63、284~288 條：`filename` (ASCII 安全化) + `filename*` (RFC 5987 `rawurlencode`) | ✅ |
| 防標頭注入（移除 `\r\n`） | `sanitizeFilename()` 已移除控制字元含 `\r\n`；`buildContentDisposition()` 組裝字串無使用者輸入直接拼接 | ✅ |
| 實際套用位置 | 第 37、49、124、125 條：CSV/JSON/PDF 單筆傳票皆使用 `buildContentDisposition()` | ✅ |

**結論：完全修正，檔名安全化與雙重 Content-Disposition 落實。**

#### SEC-04：審計日誌時機 —— `register_shutdown_function()` 檢查 HTTP 200 才寫入，參數 JSON 結構化
| 驗證點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 使用 `register_shutdown_function()` | 第 38、50、124、125 條：明確記載「採用 `register_shutdown_function()` 於連線關閉時檢查 HTTP 狀態碼為 200 才寫入」 | ✅ |
| 檢查 HTTP 200 才記錄 | 同上：「檢查 HTTP 狀態碼為 200 才 `logAudit()`」 | ✅ |
| 參數 JSON 結構化 | CSV/JSON：`json_encode(['ledger_id'=>$ledgerId, 'format'=>$format, 'ym'=>$ym, 'count'=>$rowCount])`<br>PDF 傳票：`json_encode(['ledger_id'=>$ledgerId, 'entry_id'=>$entryId])` | ✅ |
| 適用範圍 | `csv_export`、`json_export`、`pdf_voucher_export` 三種動作代碼皆採用相同機制 | ✅ |

**結論：完全修正，審計日誌時機與參數結構化符合規範。**

---

### Medium 項目

#### SEC-03：JSON 移除 `h()`，使用 `json_encode(JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`，避免雙重編碼
| 驗證點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| JSON 不經 `h()` | 第 31、181~182 條：明確記載「不經 `h()` 轉義（由 `json_encode` 處理特殊字元編碼），避免雙重編碼導致輸出 `"` 等實體字元」 | ✅ |
| `json_encode` 旗標正確 | `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` | ✅ |
| 金額格式無千分位 | 第 31、183 條：JSON 金額如 `"1200.00"`，無千分位逗號 | ✅ |
| CSV/PDF 仍經 `h()` | 第 32、194、211 條：CSV/PDF 所有文字經 `h()` 轉義 | ✅ |

**結論：完全修正，JSON 編碼策略正確，避免雙重編碼。**

#### SEC-05：`ym` 參數驗證明確 —— `parseYm` 共用函數、格式錯誤 HTTP 400 回退本月
| 驗證點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 共用函數 `parseYm()` | 第 59、259~272 條：完整實作，回傳 `[$startDate, $endDate, $error]` | ✅ |
| 格式驗證：Regex `^\d{4}-\d{2}$` | 第 260 條：`preg_match('/^(\d{4})-(\d{2})$/', $ymRaw, $m)` | ✅ |
| 數值範圍驗證：月 1~12、年 2000~2100 | 第 265~266 條 | ✅ |
| 時區正確 | 第 268~270 條：`DateTimeZone('Asia/Taipei')`、月初 00:00:00、月末 23:59:59 | ✅ |
| 錯誤處理：HTTP 400 回退本月 | 第 29、81、93、124、311 條：格式錯誤回退本月、顯示提示、HTTP 400 | ✅ |
| 流程圖節點完整 | 第 79~82 條 Mermaid：含 `parseYm` 節點、格式有效判斷、回退本月 HTTP 400 分支 | ✅ |

**結論：完全修正，`ym` 參數驗證明確、共用函數完整、錯誤處理符合規範。**

---

## 專案硬性約束與安全基線驗證

### 1. SQL Injection 防護
| 檢查點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 所有查詢使用 PDO 預備陳述式 | 第 217~232、236~254 條：SQL 範例皆使用具名佔位符 `:user_id`、`:ledger_id`、`:startDate`、`:endDate`、`:entry_id` | ✅ |
| 具名佔位符 | 同上 | ✅ |
| 無字串拼接 SQL | 查詢在 `fetchEntriesForExport()`、`getEntryForVoucher()` 共用函數中定義，參數綁定 | ✅ |
| `ATTR_EMULATE_PREPARES=false` | 專案既有 `db.php` 設定，設計文件沿用既有 PDO 連線 | ✅ |

### 2. XSS 防護
| 輸出格式 | 防護方式 | Design.md 依據 | 判定 |
| --- | --- | --- | --- |
| CSV | `h()` 轉義 + 公式注入防護 | 第 32、194 條 | ✅ |
| JSON | `json_encode(JSON_UNESCAPED_UNICODE\|JSON_UNESCAPED_SLASHES)`，不經 `h()` | 第 31、181~183 條 | ✅ |
| PDF（月結單/傳票） | `h()` 轉義 | 第 20、48、195、211 條 | ✅ |
| 錯誤頁面 | 通用錯誤頁 `error.php`，不輸出使用者輸入 | 第 86、117、312 條 | ✅ |

### 3. IDOR 防護（`ledger_access` 權限檢查）
| 檢查點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| CSV/JSON 匯出查詢 | 第 227、229 條：`INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id` | ✅ |
| PDF 單筆傳票查詢 | 第 249、251 條：`INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id` | ✅ |
| 權限檢查在查詢前 | 流程圖第 76~78、106~108 條：驗證 `ledger_id` 整數 → 檢查 `ledger_access` → 不足 HTTP 403 | ✅ |
| 三角色皆可匯出 | 第 101、325 條：owner/member/viewer 均可（唯讀合理） | ✅ |

### 4. 權限模型
| 項目 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 角色定義 | owner / member / viewer | ✅ |
| 匯出權限 | 三角色皆可（唯讀操作合理） | ✅ |
| Session 登入檢查 | `requireLogin()` 檢查 `$_SESSION['user_id']` | ✅ |
| 未登入導向 | 導向 `login.php` | ✅ |

### 5. CSV 公式注入防護
| 檢查點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 開頭字元防護 | 第 193、124 條：欄位開頭為 `=`, `+`, `-`, `@`, `\t`, `\r` 時前加單引號 `'` | ✅ |
| 套用範圍 | CSV 匯出所有欄位 | ✅ |

### 6. 錯誤資訊洩漏防護
| 檢查點 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 友善錯誤頁 | 第 86、117、312 條：HTTP 500 顯示 `error.php`，不洩漏堆疊/查詢細節 | ✅ |
| `logError` 記錄細節 | 第 53、55、56、90、114、124、125 條：`logError($e)` 記錄完整例外至 `./private_data/error.log` | ✅ |
| JSON 編碼失敗處理 | 第 86、312 條：`json_encode` 失敗 → `logError` → HTTP 500 友善錯誤頁 | ✅ |
| TCPDF 載入失敗 | 第 54、112 條：降級提供列印優化 HTML（HTTP 200），不視為錯誤 | ✅ |
| PDF 生成失敗 | 第 55、113 條：`catch (Throwable $e)` → `logError` → HTTP 500 友善錯誤頁 | ✅ |

### 7. 其他安全相關檢查
| 項目 | Design.md v1.2 內容 | 判定 |
| --- | --- | --- |
| 整數分金額 | 第 31、204、230、241 條：`amount_cents` 儲存，`formatCents()` 顯示，無 FLOAT | ✅ |
| 相對路徑 | 所有路徑 `./` 開頭 | ✅ |
| 無硬編碼密鑰 | 設計未涉及新增密鑰，沿用既有 `.env` 機制 | ✅ |
| 審計日誌不可刪除 | 第 38、50、91、115 條：`audit_log` 僅新增，設計無刪除操作 | ✅ |
| HTTPS Session Cookie | 第 102 條：依賴既有 `secure`、`HttpOnly`、`SameSite=Lax` | ✅ |

---

## 設計階段三階段審查記錄更新建議

| 階段 | 原狀態 | 建議更新狀態 | 備註 |
| --- | --- | --- | --- |
| Spec（規格）審查 | 待重審 | **通過** | 所有規格問題已修正 |
| Code（程式）審查 | 待重審 | **通過** | 設計層面程式結構、錯誤處理、串流策略完整 |
| Security（資安）審查 | 待重審 | **通過** | 本報告確認所有項目修正完成 |

---

## 風險評估與剩餘關注點

| 風險等級 | 項目 | 說明 | 緩解措施 |
| --- | --- | --- | --- |
| 低 | TCPDF 相依性 | 專案無 Composer，TCPDF 需手動放置 `vendor/tecnickcom/tcpdf` | 設計已含降級策略（列印優化 HTML），不阻斷功能 |
| 低 | 大量資料 JSON 串流手動拼接 | 手動 `echo '[' ... echo ']'` 若中斷可能產生不完整 JSON | `register_shutdown_function` 僅 HTTP 200 記錄 audit_log，前端下載中斷不影響伺服器；建議實作階段加入 `connection_aborted()` 檢查 |
| 低 | `parseYm()` 回傳空字串作為錯誤狀態 | PHP 慣用例外或 `false`，但專案既有函數多用陣列回傳錯誤訊息 | 符合專案既有風格，實作時統一錯誤處理模式 |

---

## 簽署

| 角色 | 簽署 | 日期 |
| --- | --- | --- |
| 獨立第三方資安審查員 | ✅ 通過 | 2026-11-25 |

---

## 附件：修正對照表（上一輪 Security 審查項目）

| 編號 | 原發現 | 修正狀態 | Design.md v1.2 參考位置 |
| --- | --- | --- | --- |
| SEC-01 (Critical) | CSRF 決議未文件化 | ✅ 已修正 | 第 25、30、43、327~334 條 |
| SEC-02 (High) | 檔名注入防護不完整 | ✅ 已修正 | 第 37、49、60、63、278~288 條 |
| SEC-03 (Medium) | JSON 使用 `h()` 導致雙重編碼 | ✅ 已修正 | 第 31、181~183 條 |
| SEC-04 (High) | 審計日誌時機不當、參數非 JSON | ✅ 已修正 | 第 38、50、91、115、124、125 條 |
| SEC-05 (Medium) | `ym` 參數驗證不明確 | ✅ 已修正 | 第 29、59、79~82、259~272 條 |

---

**審查結束。Design.md v1.2 通過設計階段 Security 審查，可進入實作階段。**