# 專案規劃文件索引

> 列出所有功能規劃文件，對應狀態與位置。依「開發流程」：Planning → Design（合併 Implementation）→ 三階段審查 → 實作 → 三階段審查。

---

## 規劃文件狀態總覽

| 功能名稱 | 資料夾 | Planning | Design | 審查狀態 | 實作狀態 | 備註 |
|----------|--------|----------|--------|----------|----------|------|
| **匯入 CSV 明細** | `csv-import/` | ✅ v1.2 | ✅ v1.0 | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 待實作 | 已有 import_csv.php 檔案 |
| **PDF 匯出月結單** | `pdf-export/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 待實作 | export_pdf.php 存在 |
| **Excel 匯出** | `excel-export/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 待實作 | |
| **年/季報表** | `yearly-quarterly-report/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 待實作 | yearly/quarterly_report.php 存在 |
| **跨帳本彙總** | `cross-ledger-summary/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 待實作 | |
| **重複記錄/固定開銷** | `recurring-entries/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ 已實作 | ledger.php 整合 |
| **類別管理** | `category-management/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ 已實作 | admin/category_management.php |
| **收據上傳/OCR** | `receipt-upload/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 部分實作 | view_receipt.php、ai_parse.php 存在 |
| **AI 語音記帳** | `ai-voice-entry/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 待實作 | |
| **快速輸入輔助** | `quick-entry-assist/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 待實作 | |
| **轉帳記錄** | `transfer-entry/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ 已實作 | entries.type 包含 transfer |
| **登入異常通知** | `login-anomaly-notice/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ 已實作 | login_sessions 表、儀表板提醒 |
| **預算警示** | `budget-alerts/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ✅ 已實作 | ledger.php、dashboard.php 整合 |
| **帳本統計圖表** | `ledger-statistics/` | ✅ | ✅ | 設計階段：Spec ✅ / Code ⏳ / Security ⏳ | ⏳ 部分實作 | ledger.php 類別統計 SVG |
| **資安修復** | `security-fix/` | ✅ | ❌ | - | ✅ 已完成 | SEC-002~005 已修復 |
| **全文搜尋** | `features/01_full_text_search/` | ✅ | ❌ | - | ⏳ 待實作 | search.php 待建立 |
| **行事曆視圖** | `features/02_calendar_view/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **比較報表** | `features/03_comparison_report/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **重複偵測** | `features/04_duplicate_entry/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **網頁備份** | `features/05_web_backup/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **分類預算** | `features/06_category_budget/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **記帳確認回饋** | `features/07_entry_acknowledgement/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **家庭備忘錄** | `features/08_family_notes/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **轉帳對帳** | `features/09_transfer_reconciliation/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **匯出更多格式** | `features/10_export_more_formats/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **收據 OCR** | `features/11_receipt_ocr/` | ✅ | ❌ | - | ⏳ 待實作 | ai_parse.php 相關 |
| **推播通知** | `features/12_push_notifications/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **多幣別** | `features/13_multi_currency/` | ✅ | ❌ | - | ⏳ 待實作 | |
| **統一發票兌獎** | `features/14_unified_invoice_lottery/` | ✅ | ❌ | - | ⏳ 待實作 | |

---

## 狀態圖例

| 符號 | 含義 |
|------|------|
| ✅ | 已完成 |
| ⏳ | 進行中 / 待執行 |
| ❌ | 尚未開始 / 缺失 |

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

## 優先級建議

### P0（核心缺口，建議優先）
1. **全文搜尋** (`features/01_full_text_search/`) — 使用者高頻需求，Planning 完整，缺 Design
2. **PDF/Excel 匯出** (`pdf-export/`、`excel-export/`) — 實際部署需求，檔案存在但功能未完整

### P1（重要增強）
3. **行事曆視圖** (`features/02_calendar_view/`) — 長輩友善、視覺化需求
4. **比較報表** (`features/03_comparison_report/`) — 跨月/跨帳本分析
5. **重複偵測** (`features/04_duplicate_entry/`) — 資料品質

### P2（進階功能）
6. **收據 OCR** (`features/11_receipt_ocr/`、`receipt-upload/`) — AI 整合
7. **推播通知** (`features/12_push_notifications/`) — 需 PWA/Service Worker
8. **多幣別** (`features/13_multi_currency/`) — 匯率、換算邏輯

### P3（特定場景）
9. **統一發票兌獎** (`features/14_unified_invoice_lottery/`) — 台閩地區特有
10. **家庭備忘錄** (`features/08_family_notes/`) — 輕量便利貼
11. **轉帳對帳** (`features/09_transfer_reconciliation/`) — 進階帳務

---

## 目錄結構規範

```
docs/
├── PLANNING_INDEX.md          # 本檔案
├── DESIGN.md                  # 設計系統主文件
├── PRODUCT.md                 # 產品規格
├── FINAL_NOTES.md             # 部署與最終說明
├── <feature-name>/            # 各功能資料夾（kebab-case）
│   ├── planning.md            # 規劃文件（必填）
│   └── Design.md              # 設計文件（合併 Implementation，選填但建議有）
└── features/                  # 編號功能專區
    └── XX_feature_name/
        ├── planning.md
        └── Design.md
```

> **命名慣例**：功能資料夾使用 kebab-case（如 `csv-import`、`budget-alerts`），features 區使用 `XX_feature_name`（如 `01_full_text_search`）。

---

## 相關文件

- [AGENTS.md](../AGENTS.md) — 通用開發規則、流程規範
- [Agents/service-architecture.md](../Agents/service-architecture.md) — 服務架構、頁面對應
- [Agents/system-architecture.md](../Agents/system-architecture.md) — 系統架構、非功能性需求
- [Agents/business-logic.md](../Agents/business-logic.md) — 業務規則、狀態機
- [Agents/user-permissions.md](../Agents/user-permissions.md) — 權限矩陣、IDOR 防護
- [Agents/design-system.md](../Agents/design-system.md) — 設計系統速查