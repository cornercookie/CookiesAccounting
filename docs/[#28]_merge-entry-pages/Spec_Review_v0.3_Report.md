# [#28] 合併 add_entry.php 與 multi_currency.php — 設計階段 Spec 第 2 次複查報告

## 審查基本資訊

| 項目 | 內容 |
|------|------|
| **審查版本** | Design.md v0.3（針對 v0.2 → v0.3 的修正內容複查） |
| **審查日期** | 2026-11-25 |
| **審查者** | 獨立第三方審查者（子代理） |
| **Planning 版本** | `docs/[#28]_merge-entry-pages/planning.md` v1.0 |
| **前次審查結論** | Spec v0.1：需修正 → v0.2 已修正 S-01/S-02、同步 S-03/04/05 |
| **本次審查範圍** | v0.2 → v0.3 修正內容、Code/Security 審查發現的規格缺口是否在設計層面解決 |

---

## 逐項複查結果

### 1. S-01 匯率合理性驗證 —— **已完整納入設計** ✅

**Planning 依據**：AC-08「新增外幣記錄」、AC-08a「外幣匯率驗證失敗」

**Design v0.3 對應位置**：
- §「add_entry.php 參數處理契約」POST 表單欄位驗證規則表（第 132-139 行）
- §「AC-08a/08b」驗收條件表（第 224-225 行）

**具體內容**：
| 驗證項目 | 設計規範 |
|----------|----------|
| 匯率必填 | 非 TWD 時 `exchange_rate` **必填** |
| 數值範圍 | `parseAmountCents(rate, 8)` → `rateBasisPoints`；`isValidExchangeRateBasisPoints(rateBasisPoints)` 通過 |
| 邊界值 | > 0 且 ≤ 100,000,000（對應 0.0001 ~ 10000.0000） |
| 小數位數 | 前端 `min="0.0001"` `step="0.0001"`，後端 basis points 整數驗證隱含 4 位小數 |
| 驗收條件 | AC-08a：匯率 0、負數、超過 10000、小數超過 4 位 → 驗證失敗、顯示錯誤、不寫入 DB |

**結論**：設計已完整涵蓋匯率合理性驗證的前後端契約、邊界值、錯誤處理與可判定驗收條件。**通過**。

---

### 2. S-02 original_amount 非空驗證 —— **已完整納入設計** ✅

**Planning 依據**：AC-08「新增外幣記錄」、AC-08b「外幣原幣金額驗證失敗」、資料庫 `original_amount_cents` NOT NULL

**Design v0.3 對應位置**：
- §「add_entry.php 參數處理契約」POST 驗證規則表第 138 行：`original_amount_cents` 等同 `amount` 解析結果，**不得為 0**
- 第 142 行註記：後端 `parseAmountCents()` 回傳 false 或結果為 0 視為驗證失敗
- §「AC-08b」驗收條件（第 225 行）：金額空白、0、格式錯誤 → 驗證失敗

**結論**：設計已明確要求非 TWD 時 `original_amount_cents` > 0，前端既有 `required`、後端二次驗證、DB 既有 NOT NULL 三層防護。**通過**。

---

### 3. S-03/04/05 建議改善 —— **已同步處理** ✅

| 建議項目 | v0.1 狀態 | v0.3 處理情形 | 結論 |
|----------|-----------|----------------|------|
| **S-03 錯誤路徑補強** | 缺少 POST 驗證失敗分支 | §「頁面行為流程圖」已含 CSRF 失敗、權限不足、輸入驗證失敗三條錯誤路徑（第 100-103 行） | 已處理 |
| **S-04 AC-09 拆分細化** | AC-09 過寬 | §「驗收條件表」拆分為 AC-09a~09f 六項（收據、AI、分類、CSRF、權限、審計），各有獨立前置條件與可判定結果（第 226-231 行） | 已處理 |
| **S-05 PHP→JS 初始幣別傳遞契約** | 缺少契約 | §「前端互動規則」第 155-159 行：明確定義 PHP 端 `$initialCurrency` 計算、模板輸出 `<select value="<?= h($initialCurrency) ?>">`、JS 初始化讀取並綁定 `change` 事件 | 已處理 |

**結論**：三項建議改善均已在 v0.3 設計層面落實。**通過**。

---

### 4. Code 審查發現的規格缺口 —— **大部份已解決，C-13 需補強** ⚠️

| Code 發現項目 | v0.3 設計解決情形 | 判定 |
|----------------|---------------------|------|
| **C-08/C-10：受影響檔案 21→24、含 `functions.php::renderPdfExportPage()`** | §「導覽更新範圍」明確列出 24 個檔案（根目錄 18 + admin 6），並特別標註 `functions.php::renderPdfExportPage()` 為共用函數必須同步更新（第 194-198 行） | **已解決** |
| **C-09：導覽「新增記錄」連結的 `ledger_id` 策略** | §「導覽更新範圍」第 191-192 行：`ledger_id` 視各頁面上下文動態帶入；無 `ledger_id` 上下文者連往 `ledgers.php` | **已解決** |
| **C-13：`db.php` `ATTR_EMULATE_PREPARES=false` 確認需求** | §「實作待辦清單」T-07 列為待確認項（第 272 行），但**設計層面未明確規範此為本任務必須驗證/設定的規格** | **需補強** |

**C-13 詳細分析**：
- AGENTS.md「硬性約束」明確規定：「所有查詢必須使用 PDO Prepared Statements，具名佔位符，`ATTR_EMULATE_PREPARES=false`」
- `db.php::getPdo()` 是全專案唯一 PDO 連線取得點，若未設定 `ATTR_EMULATE_PREPARES=false`，違反專案核心安全規範
- Code 審查 C-13 指出此為設計階段需確認的規格項目，非單純實作細節
- **建議**：在設計文件「資料庫寫入契約」或新增「資料庫連線規格」小節中，明確要求 `getPdo()` 回傳的 PDO 實例已設定 `ATTR_EMULATE_PREPARES=false`，並列入驗收條件（如 AC-12：PHP 語法檢查通過且 `db.php` 連線設定符合專案硬性約束）

---

### 5. Security 審查發現的規格缺口 —— **已在設計層面解決** ✅

| Security 發現項目 | v0.3 設計解決情形 | 判定 |
|--------------------|---------------------|------|
| **S-01：`add_entry.php` 缺少 GET `currency` 參數讀取與白名單驗證** | §「add_entry.php 參數處理契約」參數表第 126 行：`currency` 經 `isSupportedCurrency()` 驗證，否則忽略回退 TWD | **已解決** |
| **S-02：GET 回填參數 (`amount`、`rate`/`exchange_rate`、`entry_date`) 未經 `h()` 轉義直接輸出** | §「實作待辦清單」T-01 第 258 行：**全部經 `h()` 轉義**後預填表單 | **已解決** |
| **S-03：重導向器用 `http_build_query($_GET)`** | §「重導向契約」第 118 行：實作方式採用 `header('Location: ' . $targetUrl, true, 302)`；§「實作待辦」T-03 第 263-265 行：明確要求 `$qs = http_build_query($_GET)` 防禦性編碼 | **已解決** |
| **S-04：`functions.php::renderPdfExportPage()` 同步更新** | §「導覽更新範圍」第 198 行特別標註；§「實作待辦」T-04 第 269 行列入 | **已解決** |

**結論**：Security 審查的四項規格缺口（兩項阻塞、兩項建議）均已在 v0.3 設計層面透過參數處理契約、實作待辦清單明確規範。**通過**。

---

## 發現的問題彙總

| 編號 | 位置 | 依據 | 影響 | 嚴重度 |
|------|------|------|------|--------|
| **R-01** | 設計文件缺少「資料庫連線規格」或在「資料庫寫入契約」中明確要求 `ATTR_EMULATE_PREPARES=false` | AGENTS.md 硬性約束、Code 審查 C-13 | 若 `db.php` 未正確設定，全專案所有 PDO 查詢將使用模擬預備陳述式，存在 SQL Injection 風險，違反專案核心安全規範 | **阻塞** |
| **R-02** | §「實作待辦清單」T-07 僅列「確認 `db.php` 設定」，未納入驗收條件表 | 專案規範要求可判定驗收 | 無法在驗收階段判定此項是否通過 | **建議**（納入 AC 表） |

---

## 結論

### **需修正**（Conditional Pass）

Design.md v0.3 在 S-01、S-02、S-03/04/05、Code C-08/09/10、Security S-01~04 均已完整解決，**僅剩一項阻塞項（R-01）需在設計層面補強**。

---

## 必須修正的項目與依據

| 項目 | 必須修正內容 | 依據 |
|------|--------------|------|
| **M-01** | 在 §「資料庫寫入契約」後或新增 §「資料庫連線規格」，明確規範：`db.php::getPdo()` 回傳的 PDO 實例**必須**設定 `PDO::ATTR_EMULATE_PREPARES => false`（以及 `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`、`PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`） | AGENTS.md 硬性約束第 21 行、Code 審查 C-13 |
| **M-02** | 在 §「驗收條件與驗證方式」新增 **AC-12**：`db.php` 連線設定符合專案硬性約束（`ATTR_EMULATE_PREPARES=false` 等），驗證方法：`php -r "require 'db.php'; $pdo=getPdo(); var_dump($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));"` 輸出 `bool(false)` | 專案驗收規範要求可判定、Code 審查 C-13 |
| **M-03** | §「實作待辦清單」T-07 改為：修改/確認 `db.php::getPdo()` 設定 `ATTR_EMULATE_PREPARES=false`，並執行 AC-12 驗證 | 實作與驗收一致性 |

---

## 供使用者決定項（不納入 Design，除非同意）

| 項目 | 說明 |
|------|------|
| **D-01** | 是否將「導覽共用函數 `renderNav()` 重構」納入本任務範圍（目前列為非本期範圍，Code 審查建議長期改善） | 設計文件 §「非本期範圍」第 207 行已排除，若使用者決定納入，需擴充範圍並增列 AC |

---

## 簽署

| 角色 | 簽署 |
|------|------|
| 獨立第三方審查者（子代理） | ✅ 已完成 Spec 第 2 次複查 |
| 日期 | 2026-11-25 |

> **後續流程**：設計者依上述 M-01~M-03 修正 Design.md 為 v0.4，再送 Spec 第 3 次複查。若無異議，Spec 階段視為通過，進入實作階段。