# 設計審查報告：年度／季度報表（Task [#10]）

**審查角色**：獨立第三方審查者  
**審查日期**：2026-11-23  
**審查版本**：Design.md v1.1，實作檔 yearly_report.php、quarterly_report.php  

---

## 📋 審查摘要

| 審查項目 | 狀態 | 備註 |
|----------|------|------|
| **Spec 審查（規格一致性）** | ❌ **不通過** | 多項 Planning/Design 需求未在實作中體現 |
| **Code 審查（程式碼品質）** | ⚠️ **需修正** | 重複 SQL、CSS 類別錯誤、缺乏進入點 |
| **Security 審查（資安）** | ✅ **通過** | PDO 預備陳述式、IDOR 防護、XSS 轉義皆正確 |

---

## ❌ Spec 審查：重大缺口（必須修正）

| # | 缺口描述 | Planning/Design 參考 | 實際實作 | 嚴重度 |
|---|----------|---------------------|----------|--------|
| 1 | **缺乏進入點/導覽連結** | Design.md §前端變更：「帳本選擇（若使用者有多個帳本）」 | yearly/quarterly_report.php 只接受 `ledger_id` 參數，無下拉選單；ledgers.php、ledger.php、dashboard.php 皆無連結 | **Critical** |
| 2 | **報表總計列 CSS 類別錯誤** | Design.md §CSS：`.report-total` 為區塊元素樣式 | 使用 `<tr class="report-total">`，表格列不會套用 padding/background/border-radius | **High** |
| 3 | **重複 SQL 邏輯（DRY 違反）** | functions.php 已有 `fetchEntriesWithStats()` (L452-513) | yearly/quarterly_report.php 各自重寫相同查詢 | **High** |
| 4 | **缺少類別統計** | Planning §驗收條件 #4：「類別統計可選擇顯示該期間內各類別支出佔比」 | 完全未實作 | **Medium** |
| 5 | **年份輸入應為下拉選單** | Design.md §前端：「年份下拉選單（預設為當年）」 | 使用 `<input type="text" maxlength="4">` | **Medium** |

---

## ⚠️ Code 審查：程式碼品質問題

| # | 問題 | 位置 | 建議修正 |
|---|------|------|----------|
| 1 | 季度結束日期計算建立兩次 DateTimeImmutable | quarterly_report.php L68 | 改為建立一次並重用 |
| 2 | 缺乏統一的報表查詢函數 | 兩檔案各自寫 SQL | 搬移至 functions.php 或複用既有 `fetchEntriesWithStats()` |
| 3 | 列印樣式依賴外部 CSS 但無 inline 保障 | 兩檔案皆無 `@media print` | 在頁面加入 inline print styles 或確認 CSS 載入順序 |
| 4 | 錯誤處理只記錄錯誤訊息，未記錄 SQL/參數 | 兩檔案 catch 區塊 | 依專案慣例可接受（不外洩敏感資訊） |
| 5 | 程式碼結構與 ledger.php 差異大，維護困難 | 整體架構 | 參考 ledger.php 模式：共用查詢函數、統一錯誤處理 |

---

## ✅ Security 審查：通過項目

| 檢查項目 | 結果 | 備註 |
|----------|------|------|
| PDO 預備陳述式（具名佔位符） | ✅ | 所有查詢正確使用 `:user_id`、`:ledger_id` 等 |
| ATTR_EMULATE_PREPARES=false | ✅ | db.php L39 設定 |
| 整數分金額（無 FLOAT） | ✅ | 使用 `formatCents()`、`(int)` 強制轉型 |
| 輸出轉義 `h()` | ✅ | 所有 `echo` 皆經 `h()` |
| IDOR 防護（ledger_access JOIN） | ✅ | 查詢皆 `INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id` |
| Session 登入檢查 | ✅ | `requireLogin()` 在檔案開頭 |
| 帳本封存檢查 | ✅ | `archived = 1` 時導向 |
| CSRF（唯讀 GET 無需） | ✅ | Design.md 確認不需 CSRF |
| 相對路徑 | ✅ | 所有連結 `./` 開頭 |
| 審計日誌 | ⚠️ | 唯讀查詢可選擇性記錄，非阻塞 |

---

## 🔧 修正計畫（優先序）

### Phase 1：關鍵阻塞項（必須先完成）
1. **新增帳本下拉選單** - 在兩個報表頁面加入帳本選擇器（複用 ledgers.php 查詢邏輯）
2. **修正 `.report-total` CSS/HTML 不匹配** - 改為 `<tfoot>` + 專用 CSS 或調整 CSS 選擇器
3. **在 ledgers.php、ledger.php、dashboard.php 加入報表連結** - 讓使用者能發現並進入

### Phase 2：程式碼品質
4. **重構查詢邏輯** - 複用 `fetchEntriesWithStats()` 或建立共用函數
5. **年份改為下拉選單** - 當年 ±2 年
6. **優化 quarterly_report.php 日期計算**

### Phase 3：功能完善（可選，視範圍）
7. **加入類別統計** - 參考 ledger.php SVG 圖表邏輯
8. **加入審計日誌記錄** - `logAudit('report_view', ...)`

---

## 📝 具體修正建議

### 1. 年度報表頁面（yearly_report.php）修正重點
```php
// 新增：取得使用者可存取的帳本列表（供下拉選單）
$stmt = $pdo->prepare('
    SELECT l.id, l.name, la.role
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.archived = 0 AND la.user_id = :user_id
    ORDER BY l.name
');
$stmt->execute([':user_id' => $userId]);
$accessibleLedgers = $stmt->fetchAll();

// 修正：年份下拉選單（當年 ±2）
$yearOptions = range((int)$today->format('Y') - 2, (int)$today->format('Y') + 2);

// 修正：總計列使用 <tfoot> 或調整 CSS
```

### 2. 季度報表頁面（quarterly_report.php）修正重點
- 同樣新增帳本下拉選單
- 修正結束日期計算（避免建立兩次 DateTimeImmutable）
- 總計列 CSS 同樣修正

### 3. 導覽連結新增位置
- **ledgers.php**：每個帳本列加入「年度報表」、「季度報表」連結
- **ledger.php**：頁面頂端動作區加入報表連結
- **dashboard.php**：各帳本卡片加入報表連結

### 4. CSS 修正（assets/style.css）
```css
/* 修正：讓 .report-total 在 <tr> 上也能運作 */
.report-table tfoot tr.report-total,
.report-table tr.report-total {
    background: var(--paper-2);
}
.report-table tr.report-total td {
    font-weight: 700;
    border-top: 2px solid var(--ink);
}
```

---

## ✅ 驗收標準（修正完成後需通過）

| # | 驗收條件 | 驗證方式 |
|---|----------|----------|
| 1 | 從 ledgers.php 能點進年度/季度報表 | 手動點擊連結 |
| 2 | 報告表有帳本下拉選單，切換帳本正常 | 選擇不同帳本送出 |
| 3 | 年份為下拉選單，預設當年 | 檢查 HTML select |
| 4 | 總計列樣式正確（背景色、粗體、邊框） | 目視確認、列印預覽 |
| 5 | 季度結束日期計算正確（Q1=3/31, Q2=6/30...） | 測試各季度 |
| 6 | 查詢失敗不中斷頁面，寫入 error.log | 模擬 DB 錯誤 |
| 7 | 無記錄顯示「該期間無記錄」 | 測試空帳本 |
| 8 | 所有金額經 formatCents()、輸出經 h() | 檢查原始碼 |
| 9 | IDOR 防護生效（他人帳本不可存取） | 多使用者測試 |
| 10 | 與既有功能並存（ledger.php 正常） | 回歸測試 |

---

## 🎯 結論

**目前狀態：不可交付**  
需完成 Phase 1 所有項目後，再進行二次審查。

預估修正工時：2-3 小時（含測試）