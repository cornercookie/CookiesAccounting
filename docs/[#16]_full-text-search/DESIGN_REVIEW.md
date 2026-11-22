# Design.md 三階段設計審查報告

**審查對象**：`docs/features/01_full_text_search/Design.md` (v1.0)  
**對照基準**：
- `docs/features/01_full_text_search/planning.md` (Planning v1.0)
- `docs/csv-import/Design.md` (格式範例)
- `AGENTS.md` 硬性約束
- `Agents/` 架構文件（service-architecture、system-architecture、database-schema、business-logic、user-permissions、design-system）

**審查者**：獨立第三方審查者（非設計者、非實作者）  
**審查日期**：2026-11-22

---

## 一、Spec 審查（規格完整性、一致性、邊界條件）

| 檢查項目 | 結果 | 說明與具體建議 |
|----------|------|----------------|
| **1. 來源 Planning 完整覆蓋** | ✅ 通過 | Design.md 完整覆蓋 Planning 的 11 項業務驗收條件（登入保護、PDO 預備陳述式、權限檢查、關鍵字 LIKE、金額範圍、日期範圍、分頁、輸出轉義、audit_log、錯誤不外洩、並存）。 |
| **2. 使用者故事對應** | ✅ 通過 | 「家庭成員搜尋特定記錄」→ 關鍵字/金額/日期篩選、多帳本權限過濾、分頁、最近 50 筆預設。 |
| **3. 預期行為一致性** | ✅ 通過 | 所有 9 項預期行為（表單欄位、權限、排序、分頁、預設行為、整數分、XSS、CSRF、無結果提示）皆在 Design.md 對應章節明確定義。 |
| **4. 資料流一致性** | ✅ 通過 | Mermaid 流程圖與時序圖完整對應 Planning 資料流（第 62-79 行），含 requireLogin、ledger_access、參數驗證、PDO 查詢、h() 轉義、audit_log。 |
| **5. 錯誤處理邊界條件** | ✅ 通過 | 完整覆蓋 Planning 第 94-100 行：格式錯誤 400、CSRF 失敗通用錯誤、DB 失敗通用錯誤頁+error.log、分頁參數自動修正、無結果非錯誤。 |
| **6. 身分驗證與權限** | ✅ 通過 | 正確引用 requireLogin、ledger_access 三角色權限、owner/member/viewer 皆可搜尋（唯讀）。與 `Agents/user-permissions.md` 權限矩陣一致（第 50-58 行：檢視帳本詳情三角色皆可）。 |
| **7. 非本期範圍明確列出** | ✅ 通過 | FTS5、搜尋歷史、儲存搜尋條件、搜尋成員名稱、多帳本匯出皆明確列為 Out of Scope，與 Planning 一致。 |
| **8. 非功能性需求** | ✅ 通過 | 效能（≤3秒/萬筆內）、併發（WAL）、資料保留（audit_log 唯新增）、可用性（長輩友善、響應式）完整對應 Planning 第 114-119 行。 |
| **9. 待開發者確認事項** | ✅ 通過 | Design.md 第 188-194 行完整回應 Planning 第 121-126 行四項待確認事項，並標註已確認。 |
| **10. 格式規範符合 csv-import Design.md** | ✅ 通過 | 結構、Mermaid 圖表、程式修改路徑表、驗收條件表、非本期範圍、實作階段追蹤表等格式與 csv-import Design.md 高度一致。 |

---

## 二、Code 審查（程式修改路徑、SQL、硬性約束、Mermaid 語法）

| 檢查項目 | 結果 | 說明與具體建議 |
|----------|------|----------------|
| **1. 程式修改路徑完整性** | ✅ 通過 | 新增 `search.php`、修改 `assets/style.css`、建議導航入口、無資料庫變更。路徑表（第 106-113 行）清晰對應每項功能。 |
| **2. PDO 預備陳述式與具名佔位符** | ✅ 通過 | 設計概述第 5、23、35 行、程式修改路徑第 110 行、資料庫變更第 119 行均明確要求「具名佔位符預備陳述式」、`ATTR_EMULATE_PREPARES=false`。 |
| **3. ledger_access IDOR 防護** | ✅ 通過 | 設計概述第 3、25、35 行、權限模型第 165-170 行、Mermaid 流程圖第 52 行、時序圖第 92 行均明確：`INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id`。 |
| **4. CSRF 權杖** | ✅ 通過 | 設計概述第 4、26、33 行、程式修改路徑第 110 行（GET 含 csrfToken、POST checkCsrf）、前端變更第 128 行、驗收條件第 145 行。 |
| **5. h() 輸出轉義** | ✅ 通過 | 設計概述第 10、32 行、前端變更第 129、132 行、驗收條件第 151 行均要求所有輸出經 `h()` 轉義。 |
| **6. 整數分金額** | ✅ 通過 | 設計概述第 2、6、28 行、程式修改路徑第 110 行（toCents 解析）、資料庫變更第 119 行、驗收條件第 147 行均要求 `amount_cents` 整數分、BETWEEN 查詢。 |
| **7. 相對路徑 ./ 開頭** | ✅ 通過 | 設計概述第 12、36 行、程式修改路徑第 110 行、前端變更第 128、136 行、驗收條件第 142 行均要求 `./` 開頭。 |
| **8. Mermaid 圖表語法正確性** | ⚠️ 需修改 | **發現問題**：流程圖第 44 行 `requireLogin` 應為 `requireLogin()`；第 46 行 `csrf_token` 應為 `csrfToken()`；第 48 行 `checkCsrf()` 括號正確。時序圖第 79 行 `require_login()` 應為 `requireLogin()`；第 84 行 `checkCsrf()` 正確。建議統一函數命名大小寫。 |
| **9. 索引支援查詢** | ✅ 通過 | 資料庫變更第 119-122 行列出三個現有索引可支援：`idx_entries_entry_date_deleted`、`idx_ledger_access_user_id`、`idx_entries_ledger_id`。符合 `Agents/database-schema.md` 第 319-326 行關鍵查詢模式。 |
| **10. 分頁邏輯正確** | ✅ 通過 | 設計概述第 9、31 行：LIMIT 50 OFFSET (page-1)*50，頁碼無效自動修正為第 1 頁或最後一頁。符合 `Agents/business-logic.md` 第 337 行分頁總頁數公式。 |
| **11. 關鍵字 LIKE 逃脫處理** | ✅ 通過 | 設計概述第 7、27 行：使用 `addcslashes()` 逃脫 `%`、`_`、`\`，SQL 使用 `LIKE ... ESCAPE '\'`。正確防護萬用字元誤觸。 |
| **12. audit_log 記錄格式** | ✅ 通過 | 設計概述第 11、33 行：`logAudit('entry_search', 'keyword=... amountMin=... amountMax=... dateFrom=... dateTo=... resultCount=...')`。符合 `Agents/database-schema.md` 第 287-296 行 action 代碼慣例。 |

---

## 三、Security 審查（SQL Injection、XSS、CSRF、IDOR、資料外洩、錯誤不外洩）

| 檢查項目 | 結果 | 說明與具體建議 |
|----------|------|----------------|
| **1. SQL Injection 防護** | ✅ 通過 | 全程 PDO 預備陳述式具名佔位符、動態條件以參數綁定、關鍵字 LIKE 使用 `ESCAPE '\'` 並先逃脫、無字串拼接。符合 `AGENTS.md` 硬性約束、 `Agents/system-architecture.md` 第 175 行非功能性需求。 |
| **2. XSS 防護** | ✅ 通過 | 所有使用者可控輸出（關鍵字、類別、備註、金額、日期、錯誤訊息）經 `h()` 轉義，`htmlspecialchars` + `ENT_QUOTES | ENT_SUBSTITUTE`、UTF-8。符合 `AGENTS.md` 硬性約束、 `Agents/system-architecture.md` 第 176 行。 |
| **3. CSRF 防護** | ✅ 通過 | 搜尋表單 POST 含 `csrf_token` 隱藏欄位、伺服端 `checkCsrf()` 以 `hash_equals()` 恆時比較、失敗拒絕並顯示通用錯誤。符合 `AGENTS.md` 硬性約束、 `Agents/system-architecture.md` 第 177 行。注意：搜尋為 GET/POST 雙模式，POST 時才驗證 CSRF（設計概述第 23、26 行已說明）。 |
| **4. IDOR 權限繞過** | ✅ 通過 | 查詢 `entries` 強制 `INNER JOIN ledger_access` 檢查 `la.user_id = :user_id`，未授權帳本不出現。符合 `Agents/user-permissions.md` 第 86-110 行 IDOR 防護核心原則與實作模式。 |
| **5. 資料外洩風險** | ✅ 通過 | 無外部 API 呼叫、資料僅存自身 SQLite、HTTPS Session Cookie（secure、HttpOnly、SameSite=Lax）。設計概述第 172-174 行、非功能性需求第 181-182 行。 |
| **6. 錯誤訊息不外洩堆疊** | ✅ 通過 | DB 失敗顯示通用錯誤頁、詳細錯誤寫入 `private_data/error.log`、瀏覽器不見 SQL/堆疊。設計概述第 13、35 行、驗收條件第 154 行。符合 `Agents/system-architecture.md` 第 183 行「錯誤不外洩」。 |
| **7. 權限角色正確性** | ✅ 通過 | 搜尋為唯讀操作，owner/member/viewer 三角色皆可執行。與 `Agents/user-permissions.md` 第 52 行「檢視帳本詳情」權限矩陣一致，與 `Agents/database-schema.md` 第 154-166 行角色權限矩陣一致。 |
| **8. 審計日誌不可刪除** | ✅ 通過 | 僅呼叫 `logAudit()` 新增記錄，不提供刪除介面。符合 `AGENTS.md` 硬性約束「僅新增不刪除」、 `Agents/database-schema.md` 第 298 行索引、`Agents/business-logic.md` 第 185 行原則。 |

---

## 四、上一輪審查必修項與建議項修正追蹤

| 上一輪項目 | 狀態 | 驗證結果 |
|------------|------|----------|
| **必修項 #1**：補齊 Mermaid 圖表（流程圖 + 時序圖） | ✅ 已修正 | Design.md 第 38-104 行新增完整流程圖與時序圖，涵蓋登入、CSRF、參數驗證、權限檢查、查詢、分頁、輸出、audit_log 全流程。 |
| **建議項 #2**：明確列出專案函數確認狀態 | ✅ 已修正 | Design.md 第 190 行完整列出 9 個專案函數（`toCents`、`isValidDate`、`requireLogin`、`h`、`csrfToken`、`checkCsrf`、`logAudit`、`getPdo`、`formatCents`）並標註「已確認」。 |
| **建議項 #3**：明確權限模型 owner/member/viewer 差異引用 | ✅ 已修正 | Design.md 第 191 行引用 `Agents/user-permissions.md` 第 50-68 行權限矩陣，確認三角色皆可檢視帳本詳情。 |
| **建議項 #4**：明確 audit_log 結構與保留期間引用 | ✅ 已修正 | Design.md 第 192 行引用 `Agents/database-schema.md` 第 275-299 行 audit_log 結構、索引、保留規則。 |
| **建議項 #5**：效能目標標註待實測確認 | ✅ 已修正 | Design.md 第 193 行標註「待開發者確認，實作階段需於目標主機環境實測並回報」。 |

---

## 五、總體結論與建議

| 審查階段 | 結果 |
|----------|------|
| **Spec 審查** | ✅ 通過 — 完整覆蓋 Planning 需求，規格一致，邊界條件完善 |
| **Code 審查** | ⚠️ 需修改 — Mermaid 圖表中函數命名大小寫不一致（`requireLogin()` vs `require_login()`、`csrfToken()` vs `csrf_token`） |
| **Security 審查** | ✅ 通過 — 無 SQL Injection、XSS、CSRF、IDOR、資料外洩、錯誤外洩風險 |

### 必須修正項目（實作前需完成）

1. **Mermaid 流程圖第 44、46 行**：`requireLogin` → `requireLogin()`、`csrf_token` → `csrfToken()`
2. **Mermaid 時序圖第 79 行**：`require_login()` → `requireLogin()`
3. **建議同步修正**：時序圖第 89 行 `getPdo()` 呼叫後第 90 行 `PRAGMA WAL`、`busy_timeout` 為 `getPdo()` 內部行為，建議標註為內部處理而非顯式步驟（不影響正確性，僅圖表表達精確度）。

### 可進入實作判定

**結論：需修改後再實作**  
僅需修正上述 3 處 Mermaid 圖表函數命名一致性問題，不涉及實質設計邏輯變更。修正完成後可進入實作階段。

---

## 六、審查簽署

| 角色 | 簽署 | 日期 |
|------|------|------|
| 獨立第三方審查者 | ✅ 完成 | 2026-11-22 |

> **備註**：本審查僅針對設計文件（Design.md）進行靜態分析。實作階段完成後，仍需進行 Code 審查（程式碼品質、命名、錯誤處理、效能、測試覆蓋）與 Security 審查（實際程式碼掃描），由獨立第三方執行。