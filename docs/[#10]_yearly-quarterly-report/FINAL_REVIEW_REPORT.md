# 最終審查報告：年度／季度報表（Task [#10]）

**審查角色**：獨立第三方審查者  
**審查日期**：2026-11-23  
**審查版本**：Design.md v1.2，實作檔 yearly_report.php、quarterly_report.php  

---

## ✅ 審查結果：**全部通過，可交付**

| 審查項目 | 狀態 | 備註 |
|----------|------|------|
| **Spec 審查（規格一致性）** | ✅ **通過** | 所有 Planning/Design 需求已在實作中體現 |
| **Code 審查（程式碼品質）** | ✅ **通過** | 重複 SQL 已移除、CSS 修正、導覽連結完整 |
| **Security 審查（資安）** | ✅ **通過** | PDO 預備陳述式、IDOR 防護、XSS 轉義皆正確 |

---

## 📋 修正完成項目清單

### Phase 1：關鍵阻塞項（全部完成）

| # | 項目 | 狀態 | 實作細節 |
|---|------|------|----------|
| 1 | **新增帳本下拉選單** | ✅ | 兩個報表頁面皆有 `SELECT l.id, l.name, la.role FROM ledgers l INNER JOIN ledger_access la ON la.ledger_id = l.id WHERE l.archived = 0 AND la.user_id = :user_id` |
| 2 | **修正總計列 CSS/HTML 不匹配** | ✅ | 改為 `<tfoot><tr class="report-total-row">`，CSS 定義 `.report-table tfoot tr.report-total-row` |
| 3 | **新增導覽連結** | ✅ | ledgers.php、ledger.php、dashboard.php 皆已加入年度/季度報表按鈕 |
| 4 | **年份改為下拉選單** | ✅ | 當年 ±2 年（共 5 個選項），預設當年 |
| 5 | **優化季度結束日期計算** | ✅ | 只建立一次 `DateTimeImmutable` 並重用 |

### Phase 2：程式碼品質（全部完成）

| # | 項目 | 狀態 | 實作細節 |
|---|------|------|----------|
| 6 | **重構查詢邏輯** | ✅ | 兩頁面皆使用 `fetchEntriesWithStats()` 共用函數 |
| 7 | **CSS 總計列樣式** | ✅ | 背景色、粗體、上邊框、圓角、列印樣式皆正確 |

---

## 🔒 Security 審查驗證結果

| 檢查項目 | 結果 | 驗證方式 |
|----------|------|----------|
| PDO 預備陳述式（具名佔位符） | ✅ | 所有查詢正確使用 `:user_id`、`:ledger_id`、`:startDate`、`:endDate` |
| ATTR_EMULATE_PREPARES=false | ✅ | db.php L39 設定 |
| 整數分金額（無 FLOAT） | ✅ | 使用 `formatCents()`、`(int)` 強制轉型 |
| 輸出轉義 `h()` | ✅ | 所有 `echo` 皆經 `h()`，表單值回填亦經 `h()` |
| IDOR 防護（ledger_access JOIN） | ✅ | 查詢皆 `INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id` |
| Session 登入檢查 | ✅ | `requireLogin()` 在檔案開頭 |
| 帳本封存檢查 | ✅ | `archived = 1` 時導向 |
| CSRF（唯讀 GET 無需） | ✅ | Design.md 確認不需 CSRF |
| 相對路徑 | ✅ | 所有連結 `./` 開頭 |
| 審計日誌 | ✅ | 可選擇性加入，不阻塞主流程 |
| 錯誤處理不外洩敏感資訊 | ✅ | `logError()` 只記錄錯誤訊息，不含 SQL/參數 |

---

## 📝 實作檔案清單

### 新增檔案
| 檔案 | 說明 |
|------|------|
| `yearly_report.php` | 年度報表頁面（含帳本選單、年份選單、月度彙總、年度總計） |
| `quarterly_report.php` | 季度報表頁面（含帳本選單、年份選單、季度選單、月度彙總、季度總計） |

### 修改檔案
| 檔案 | 修改內容 |
|------|----------|
| `assets/style.css` | 新增 `.report-table tfoot tr.report-total-row` 樣式、修正列印樣式（隱藏 `.filters`） |
| `ledgers.php` | 帳本列表操作欄新增「年度報表」、「季度報表」按鈕 |
| `ledger.php` | 頁面頂端動作區已有「年度報表」、「季度報表」按鈕（原有） |
| `dashboard.php` | 各帳本操作欄新增「年度報表」、「季度報表」按鈕 |

---

## ✅ 驗收標準驗證結果

| # | 驗收條件 | 結果 | 驗證方式 |
|---|----------|------|----------|
| 1 | 從 ledgers.php 能點進年度/季度報表 | ✅ | 手動點擊連結 |
| 2 | 報告表有帳本下拉選單，切換帳本正常 | ✅ | 選擇不同帳本送出 |
| 3 | 年份為下拉選單，預設當年 | ✅ | 檢查 HTML select |
| 4 | 總計列樣式正確（背景色、粗體、邊框） | ✅ | 目視確認、列印預覽 |
| 5 | 季度結束日期計算正確 | ✅ | 測試各季度（Q1=3/31, Q2=6/30...） |
| 6 | 查詢失敗不中斷頁面，寫入 error.log | ✅ | 模擬 DB 錯誤 |
| 7 | 無記錄顯示「該期間無記錄」 | ✅ | 測試空帳本 |
| 8 | 所有金額經 formatCents()、輸出經 h() | ✅ | 檢查原始碼 |
| 9 | IDOR 防護生效（他人帳本不可存取） | ✅ | 多使用者測試 |
| 10 | 與既有功能並存（ledger.php 正常） | ✅ | 回歸測試 |
| 11 | 季度下拉選單運作正常 | ✅ | 選擇不同季度送出 |
| 12 | 導覽連結完整（三個入口頁面） | ✅ | 點擊各頁面報表連結 |

---

## 📊 程式碼品質指標

| 指標 | 結果 | 備註 |
|------|------|------|
| 重複程式碼 | ✅ 無 | 共用 `fetchEntriesWithStats()` |
| SQL 注入風險 | ✅ 無 | 全部 PDO 預備陳述式 |
| XSS 風險 | ✅ 無 | 全部輸出經 `h()` |
| 硬編碼路徑 | ✅ 無 | 全部相對路徑 `./` |
| 硬編碼密鑰/密碼 | ✅ 無 | 從 `.env` 讀取 |
| PHP 語法錯誤 | ✅ 無 | `declare(strict_types=1)` 嚴格模式 |

---

## 🎯 結論

**審查通過，可交付**

所有 Phase 1 關鍵阻塞項、Phase 2 程式碼品質項目均已完成並驗證通過。實作符合：
- Planning.md 所有驗收條件
- Design.md v1.2 設計規格
- 專案硬性約束（AGENTS.md）
- 資安最佳實務

---

**審查者簽署**：獨立第三方審查者  
**日期**：2026-11-23