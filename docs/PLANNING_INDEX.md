# 專案規劃文件索引

> 列出所有功能規劃文件，對應狀態與位置。依「開發流程」：Planning → Design（合併 Implementation）→ 三階段審查 → 實作 → 三階段審查 → 更新 Design.md。
>
> **狀態表已過時。** 下表仍把多項已提交功能標成未實作。是否可用以程式與 git 提交為準，不要用本表判斷。

## 任務編號規範

> **新任務請以流水號創建資料，如：`[#1]增加某個功能`**

- 所有新功能、Bug 修復、技術債務任務皆需在 Planning.md、Design.md、審查報告、Git commit 等文件中標註任務編號
- 流水號依專案全局遞增，不重置
- 任務編號格式：`[#<數字>]`，例如 `[#1]`、`[#2]`、`[#15]`
- 資料夾命名：`[#n]_feature-name`（kebab-case）

---

## 規劃文件狀態總覽

| 編號 | 功能名稱 | 資料夾 | Planning | Design | 審查狀態 | 實作狀態 | 備註 |
|------|----------|--------|----------|--------|----------|----------|------|
| **[#1]** | **重複記錄/固定開銷** | `[#1]_recurring-entries/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ **已實作** | ledger.php 整合 |
| **[#2]** | **類別管理** | `[#2]_category-management/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ **已實作** | admin/category_management.php |
| **[#3]** | **轉帳記錄** | `[#3]_transfer-entry/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ **已實作** | entries.type 包含 transfer |
| **[#4]** | **登入異常通知** | `[#4]_login-anomaly-notice/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ **已實作** | login_sessions 表、儀表板提醒 |
| **[#5]** | **預算警示** | `[#5]_budget-alerts/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ **已實作** | ledger.php、dashboard.php 整合 |
| **[#6]** | **資安修復** | `[#6]_security-fix/` | ✅ | ✅ | - | ✅ **已完成** | SEC-002~005 已修復 |
| **[#7]** | **匯入 CSV 明細** | `[#7]_csv-import/` | ✅ v1.2 | ✅ v1.0 | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **部分實作** | import_csv.php 存在 |
| **[#8]** | **PDF 匯出月結單** | `[#8]_pdf-export/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **部分實作** | export_pdf.php 存在 |
| **[#9]** | **Excel 匯出** | `[#9]_excel-export/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **待實作** | |
| **[#10]** | **年/季報表** | `[#10]_yearly-quarterly-report/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **部分實作** | yearly/quarterly_report.php 存在 |
| **[#11]** | **跨帳本彙總** | `[#11]_cross-ledger-summary/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **待實作** | |
| **[#12]** | **收據上傳/OCR** | `[#12]_receipt-upload/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **部分實作** | view_receipt.php、ai_parse.php 存在 |
| **[#13]** | **帳本統計圖表** | `[#13]_ledger-statistics/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **部分實作** | ledger.php 類別統計 SVG |
| **[#14]** | **AI 語音記帳** | `[#14]_ai-voice-entry/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **待實作** | |
| **[#15]** | **快速輸入輔助** | `[#15]_quick-entry-assist/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ **待實作** | |
| **[#16]** | **全文搜尋** | `[#16]_full-text-search/` | ✅ | ✅ | 設計階段：Spec ⏳ / Code ⏳ / Security ⏳ | ⏳ **待實作** | search.php 待建立，含 DESIGN_REVIEW.md |
| **[#17]** | **行事曆視圖** | `[#17]_calendar-view/` | ✅ | ❌ | - | ⏳ **待實作** | 含 review_report_part1-3.md |
| **[#18]** | **比較報表** | `[#18]_comparison-report/` | ✅ | ❌ | - | ⏳ **待實作** | |
| **[#19]** | **分類預算** | `[#19]_category-budget/` | ✅ | ✅ | 設計階段：Spec ⏳ / Code ⏳ / Security ⏳ | ⏳ **待實作** | 已有 Design.md |
| **[#20]** | **記帳確認回饋** | `[#20]_entry-acknowledgement/` | ✅ | ❌ | - | ⏳ **待實作** | |
| **[#21]** | **家庭備忘錄** | `[#21]_family-notes/` | ✅ | ❌ | - | ⏳ **待實作** | |
| **[#22]** | **轉帳對帳** | `[#22]_transfer-reconciliation/` | ✅ | ❌ | - | ⏳ **待實作** | |
| **[#23]** | **匯出更多格式** | `[#23]_export-more-formats/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ✅ / Security ✅<br/>實作階段：Spec ✅ / Code ✅ / Security ✅ | ✅ **已完成** | 匯出 CSV/JSON/單筆傳票 PDF |
| **[#24]** | **多幣別** | `[#24]_multi-currency/` | ✅ | ❌ | - | ⏳ **待實作** | |
| **[#34]** | **全專案審查** | `[#34]_full-project-review/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ✅ / Security ✅<br/>實作階段：Spec ✅ / Code ✅ / Security ✅ | ✅ **已完成** | `7cdcfb1`、`7d48cf0`；Hermes 獨立唯讀審查 |

---

## 狀態圖例

| 符號 | 含義 |
|------|------|
| ✅ | 已完成 / 已實作 |
| ⏳ | 進行中 / 待執行 / 部分實作 |
| ❌ | 尚未開始 / 缺失 |

---

## 實作完成度分組

### ✅ 已完成（7 項）
- [#1] 重複記錄/固定開銷
- [#2] 類別管理
- [#3] 轉帳記錄
- [#4] 登入異常通知
- [#5] 預算警示
- [#6] 資安修復
- [#23] 匯出更多格式

### ⏳ 部分實作 / 審查中（7 項）
- [#7] 匯入 CSV 明細
- [#8] PDF 匯出月結單
- [#9] Excel 匯出
- [#10] 年/季報表
- [#11] 跨帳本彙總
- [#12] 收據上傳/OCR
- [#13] 帳本統計圖表

### ⏳ 僅規劃/設計階段（10 項）
- [#14] AI 語音記帳
- [#15] 快速輸入輔助
- [#16] 全文搜尋
- [#17] 行事曆視圖
- [#18] 比較報表
- [#19] 分類預算
- [#20] 記帳確認回饋
- [#21] 家庭備忘錄
- [#22] 轉帳對帳
- [#24] 多幣別

---

## 開發流程檢查清單（每個功能）

### 設計階段
- [ ] Planning.md 完成並鎖定需求
- [ ] Design.md 完成（含 Mermaid 圖表、程式修改路徑、驗收條件）
- [ ] **Spec 審查** - 獨立第三方審查需求完整性、規格一致性
- [ ] **Code 審查（設計階段）** - 獨立第三方審查設計可行性、資料庫變更、API 設計
- [ ] **Security 審查（設計階段）** - 獨立第三方審查威脅模型、權限邊界、資料流

### 實作階段
- [ ] 依 Design.md 程式修改路徑實作
- [ ] 所有 SQL 使用 PDO 預備陳述式
- [ ] 所有金額整數分、所有輸出 `h()` 轉義、所有 POST 有 CSRF
- [ ] 關鍵操作 `logAudit()`、權限檢查 `ledger_access`

### 實作後審查
- [ ] **Spec 審查** - 實作是否符合 Planning/Design
- [ ] **Code 審查** - 程式碼品質、命名、錯誤處理、效能
- [ ] **Security 審查** - SQLi、XSS、CSRF、IDOR、權限繞過

---

## 優先級建議（待實作項目）

### P0（核心缺口，建議優先）
1. **[#16] 全文搜尋** (`[#16]_full-text-search/`) — 使用者高頻需求，Planning 完整，Design 已完成，需通過審查
2. **[#8] PDF 匯出月結單** (`[#8]_pdf-export/`) — 實際部署需求，檔案存在但功能未完整
3. **[#9] Excel 匯出** (`[#9]_excel-export/`) — 實際部署需求

### P1（重要增強）
4. **[#17] 行事曆視圖** (`[#17]_calendar-view/`) — 長輩友善、視覺化需求
5. **[#18] 比較報表** (`[#18]_comparison-report/`) — 跨月/跨帳本分析
6. **[#19] 分類預算** (`[#19]_category-budget/`) — 資料品質，已有 Design.md

### P2（進階功能）
7. **[#12] 收據 OCR** (`[#12]_receipt-upload/`) — AI 整合，部分實作
8. **[#14] AI 語音記帳** (`[#14]_ai-voice-entry/`) — 語音輸入
9. **[#24] 多幣別** (`[#24]_multi-currency/`) — 匯率、換算邏輯

### P3（特定場景）
10. **[#21] 家庭備忘錄** (`[#21]_family-notes/`) — 輕量便利貼
11. **[#22] 轉帳對帳** (`[#22]_transfer-reconciliation/`) — 進階帳務
12. **[#23] 匯出更多格式** (`[#23]_export-more-formats/`) — 多格式匯出

---

## 目錄結構規範

```
docs/
├── PLANNING_INDEX.md          # 本檔案
├── DESIGN.md                  # 設計系統主文件
├── PRODUCT.md                 # 產品規格
├── FINAL_NOTES.md             # 部署與最終說明
├── historical/                # 歷史任務交付說明（歸檔）
│   ├── NOTES.md
│   ├── TASK3_NOTES.md
│   └── TASK5_NOTES.md
├── [#1]_recurring-entries/    # 各功能資料夾（[#n]_kebab-case）
│   ├── planning.md
│   ├── Design.md
│   └── REVIEW_REPORT_PART4.md
├── [#2]_category-management/
│   ├── planning.md
│   └── Design.md
...
├── [#24]_multi-currency/
│   ├── planning.md
│   └── Design.md
```

> **命名慣例**：功能資料夾使用 `[#n]_kebab-case`（如 `[#1]_recurring-entries`、`[#16]_full-text-search`），不再使用 `features/` 子資料夾。

---

## 相關文件

- [AGENTS.md](../AGENTS.md) — 通用開發規則、流程規範
- [Agents/service-architecture.md](../Agents/service-architecture.md) — 服務架構、頁面對應
- [Agents/system-architecture.md](../Agents/system-architecture.md) — 系統架構、非功能性需求
- [Agents/business-logic.md](../Agents/business-logic.md) — 業務規則、狀態機
- [Agents/user-permissions.md](../Agents/user-permissions.md) — 權限矩陣、IDOR 防護
- [Agents/design-system.md](../Agents/design-system.md) — 設計系統速查