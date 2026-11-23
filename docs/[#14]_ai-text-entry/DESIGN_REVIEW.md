# Design Review Report: [#14] AI 自然語言記帳 (Gemini API 整合)

**審查日期**：2026-11-23  
**審查者**：獨立第三方審查者  
**審查對象**：`docs/[#14]_ai-text-entry/Design.md` (v1.1)  
**關聯實作**：`ai_parse.php`、`add_entry.php`、`assets/ai-parse.js`、`assets/style.css`、`config.php`、`.env.example`  
**Planning**：`docs/[#14]_ai-text-entry/planning.md`

---

## 一、總結評估

| 維度 | 評級 | 說明 |
|------|------|------|
| **規格一致性** | ⚠️ 需修正 | Design.md 與實作碼有多處不一致（語音按鈕未移除、CSRF 處理差異、Prompt 欄位名稱不一致） |
| **安全性** | ⚠️ 需修正 | API Key 洩漏風險（error_log）、Rate Limit 表建立權限、Gemini 回應欄位驗證不完整 |
| **代碼品質** | ⚠️ 需修正 | 硬編碼模型名稱、缺乏錯誤碼區分、前端填入邏輯與後端回傳欄位名不符 |
| **文件完整性** | ✅ 通過 | Design.md 結構完整，涵蓋架構、資料流、安全控管、測試計畫 |

**整體建議**：**Request Changes** — 必須修正 Critical/High 級問題後才能交付。

---

## 二、Critical / High 級問題（必須修正）

### 1. 【Critical】Design.md 聲稱已移除語音功能，但實作碼仍保留完整語音輸入邏輯

**位置**：
- `Design.md` 第 85 行：`確認移除語音輸入相關程式碼與樣式，簡化前端實作`
- `Design.md` 第 93 行：`移除 .ai-voice-btn 語音按鈕樣式`
- `add_entry.php` 第 192-194 行：仍有 `<button id="aiVoiceBtn" class="ai-voice-btn">🎤</button>`
- `assets/ai-parse.js` 第 30-61 行：完整的 Web Speech API 語音辨識邏輯
- `assets/style.css` 第 575-576, 587 行：`.ai-voice-btn` 樣式仍存在

**影響**：
- Planning 明確要求「不支援語音輸入」
- Design.md 聲稱已移除，實際未移除 → 文件與代碼不一致，誤導審查者
- 額外攻擊面（麥克風權限、語音資料處理）

**修正建議**：
1. 從 `add_entry.php` 移除語音按鈕 HTML
2. 從 `assets/ai-parse.js` 移除第 30-61 行語音相關邏輯
3. 從 `assets/style.css` 移除 `.ai-voice-btn` 相關樣式（第 575-576, 587 行）
4. 更新 Design.md 第 85、93 行變更摘要為實際狀態

---

### 2. 【Critical】CSRF 防護實作與 Design.md 不符，且有安全風險

**位置**：
- `Design.md` 第 28 行：`ai_parse.php 作為 JSON API 端點，採用 session 驗證（requireLogin 已綁定 session），不另外發 CSRF token`
- `ai_parse.php` 第 13-17 行：**實際有檢查 CSRF Token**（`checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')`）

**問題**：
1. **文件與代碼不符**：Design.md 說不檢查 CSRF，實際有檢查
2. **前端未送 CSRF Token**：`assets/ai-parse.js` 第 77-80 行的 `fetch` 僅送 `Content-Type` 和 `body`，**未包含 `X-CSRF-Token` header**
3. **導致所有請求都會被 403 擋下**：`checkCsrf()` 會因 token 為空字串而回傳 false

**修正建議**：
```javascript
// assets/ai-parse.js 第 77-80 行修正為：
fetch('./ai_parse.php', {
  method: 'POST',
  headers: { 
    'Content-Type': 'application/json; charset=utf-8',
    'X-CSRF-Token': document.querySelector('input[name="csrf_token"]')?.value || ''
  },
  body: JSON.stringify({ text: text })
})
```
同時更新 Design.md 第 28 行說明實際做法。

---

### 3. 【High】Gemini API Key 可能洩漏至 error.log

**位置**：`ai_parse.php` 第 139 行：
```php
error_log('Gemini API error (http=' . $httpCode . '): ' . $curlError . ' response=' . (string) $response);
```

**風險**：
- `$response` 可能包含 API Key（若 Gemini 回傳錯誤訊息含金鑰相關資訊）
- `$geminiUrl` 第 112 行直接拼接 `$apiKey` 到 URL，若 curl 記錄完整 URL 會洩漏
- `private_data/error.log` 雖被 .htaccess 封鎖，但若伺服器配置錯誤或日誌被備份到不安全位置會外洩

**修正建議**：
1. 移除 `$response` 記錄，或先過濾敏感資訊
2. 不要記錄完整 URL，改記錄「Gemini API 呼叫失敗」等通用訊息
3. 參考 `functions.php` 第 276-279 行 `logError()` 的做法

---

### 4. 【High】Rate Limit 表 (`api_rate_limits`) 在請求時動態建表，權限不足會失敗

**位置**：`ai_parse.php` 第 61-68 行：
```php
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS api_rate_limits (
    identifier TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_api_rate_limits_identifier ON api_rate_limits(identifier);
CREATE INDEX IF NOT EXISTS idx_api_rate_limits_created_at ON api_rate_limits(created_at);
SQL);
```

**問題**：
- 一般 cPanel 部署中，PHP 執行身份通常**無 CREATE TABLE 權限**（資料庫結構變更需由遷移腳本或管理員執行）
- 首次呼叫時會拋出 PDOException，導致 500 錯誤
- 違反專案「資料庫 schema 變更由 `ensureSchema()` 統一管理」原則（見 `db.php`）

**修正建議**：
1. 將 `api_rate_limits` 表建立移至 `db.php` 的 `ensureSchema()` 中
2. `ai_parse.php` 僅做查詢與插入，不做 DDL
3. 若表不存在，記錄錯誤並跳過 rate limit（fail-open）或回傳友善錯誤

---

### 5. 【High】前端填入邏輯與後端回傳欄位名不一致

**位置**：
- `ai_parse.php` 第 177-178 行：回傳 `amount_cents`（整數分）
- `assets/ai-parse.js` 第 96-98 行：期望 `d.amount` 並直接塞入 `#amount`（預期元為單位字串）

**現況**：
```php
// 後端回傳
'amount_cents' => 12000  // 120.00 元
```

```javascript
// 前端填入
document.getElementById('amount').value = d.amount; // undefined！
```

**修正建議**：
1. 後端改回傳 `amount`（元，字串格式，如 `"120.00"`）供前端直接使用
2. 或前端改用 `d.amount_cents / 100` 並格式化
3. 建議採方案 1，符合前端表單預期格式

---

### 6. 【High】Prompt 指定 `amount` 為「元」，但 `toCents()` 驗證接受「元」字串

**位置**：
- `ai_parse.php` 第 101 行 Prompt：`- amount：金額（元），數字...`
- `ai_parse.php` 第 177 行：`toCents((string) $parsed['amount'])`

**問題**：`toCents()` 接受 `"120"` 或 `"120.50"`（元），這部分沒問題。但 Prompt 說「至多小數點後兩位」，若 Gemini 回傳 `"120.555"`，`toCents()` 會回傳 false（正則只匹配 1-2 位小數），導致欄位變 null。這雖然會被驗證擋下，但 Prompt 應更精確。

**建議**：Prompt 加上「嚴格至多兩位小數，不得超過」。

---

## 三、Major 級問題（應修正）

### 7. 【Major】Gemini 模型名稱硬編碼，難以維護

**位置**：`ai_parse.php` 第 112 行：
```php
$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;
```

**問題**：
- 模型名稱 `gemini-1.5-flash` 硬編碼
- 未來模型版本更新（如 `gemini-1.5-flash-latest`、`gemini-2.0-flash`）需改代碼
- 違反設定與代碼分離原則

**修正建議**：
```php
// config.php 新增
define('GEMINI_MODEL', env('GEMINI_MODEL', 'gemini-1.5-flash'));

// ai_parse.php 使用
$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . $apiKey;
```

---

### 8. 【Major】缺乏 API 回應錯誤碼區分，前端無法針對性處理

**位置**：`ai_parse.php` 多處回傳 `http_response_code(502)` 但 error 訊息相似

**現況**：
- API Key 未設定 → 500
- Gemini HTTP 非 200 → 502
- JSON 解析失敗 → 502
- 欄位驗證失敗 → 200 + `ok: false`

**問題**：前端只能顯示通用錯誤，無法區分「暫時性錯誤（可重試）」vs「永久性錯誤（需管理員介入）」

**修正建議**：
- 回傳結構化錯誤碼：`{ "ok": false, "error": "...", "code": "GEMINI_UNAVAILABLE" }`
- 代碼建議：`API_KEY_MISSING`、`GEMINI_UNAVAILABLE`、`PARSE_FAILED`、`VALIDATION_FAILED`、`RATE_LIMITED`
- 前端依 code 顯示不同提示（如 rate limited 顯示倒數計時）

---

### 9. 【Major】Prompt 要求日期格式 `Y-m-dd` 有誤，應為 `Y-m-d`

**位置**：`ai_parse.php` 第 103 行：
```
- date：日期 Y-m-dd，若未提及則用今天（Asia/Taipei）
```

**問題**：`Y-m-dd` 不是標準 PHP 日期格式，正確為 `Y-m-d`。雖然 Gemini 可能理解意圖，但不精確。

---

### 10. 【Major】前端 `ai-parse.js` 無處理 `transfer` 類型

**位置**：`assets/ai-parse.js` 第 108-110 行：
```javascript
if (d.type !== null) {
  document.getElementById('type').value = d.type;
  filled.push('類型 ' + (d.type === 'income' ? '收入' : '支出'));
}
```

**問題**：
- `add_entry.php` 表單支援 `transfer`（轉帳）選項
- 後端 Prompt 要求 `type` 為 `income` 或 `expense`，**未包含 `transfer`**
- 前端顯示邏輯將非 `income` 視為 `支出`，若未來支援 `transfer` 會顯示錯誤

**修正建議**：
1. Prompt 加入 `transfer` 選項（若業務需要）
2. 前端顯示邏輯改用對照表：
```javascript
const typeLabels = { income: '收入', expense: '支出', transfer: '轉帳' };
filled.push('類型 ' + (typeLabels[d.type] || d.type));
```

---

### 11. 【Major】`config.php` 第 62 行讀取 `GEMINI_API_KEY` 但未定義常數，僅用於偵測 placeholder

**位置**：`config.php` 第 62 行：
```php
$geminiApiKey  = (string) env('GEMINI_API_KEY');
```

**問題**：
- 讀取後僅用於第 66 行 placeholder 檢查，未定義為常數供全專案使用
- `ai_parse.php` 第 49 行重複呼叫 `env('GEMINI_API_KEY')`
- 不符專案慣例（如 `ADMIN_USERNAME`/`ADMIN_PASSWORD` 定義為常數）

**修正建議**：
```php
// config.php 第 51-52 行後新增
define('GEMINI_API_KEY', env('GEMINI_API_KEY'));
// 移除第 62 行區域變數，第 66 行改用常數
```

---

## 四、Minor 級問題（建議改善）

### 12. 【Minor】Design.md 第 51 行 Prompt 範例與實際代碼不符

**Design.md**：
```
4. 呼叫 Gemini API（HTTP POST 至 `https://generativelanguage.googleapis.com/v1beta/models/gemini-...:generateContent?key=...`，key 來自 `GEMINI_API_KEY`）。
```

**實際代碼**：`gemini-1.5-flash` 具體模型名稱

**建議**：Design.md 更新為具體模型名稱，或說明可由設定調整。

---

### 13. 【Minor】Design.md 第 53 行「清除 Markdown 代碼區塊」正規表達式過於簡單

**Design.md**：`以正規表達式清除 Markdown 代碼區塊（```json ... ```）`

**實際代碼**：`ai_parse.php` 第 160-163 行：
```php
$cleaned = preg_replace('/```(?:json)?\s*\n?/i', '', $geminiText);
$cleaned = trim($cleaned);
$cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);
```

**問題**：
- 無法處理 ````json ````（4 個反引號）
- 無法處理代碼區塊中包含巢狀反引號的情況
- 建議改用更健壯的解析（如尋找第一個 `{` 到最後一個 `}`）

---

### 14. 【Minor】缺乏對 `category` 欄位的驗證（長度、字元集）

**位置**：`ai_parse.php` 第 179-180 行：
```php
$result['category'] = isset($parsed['category']) && is_string($parsed['category']) && $parsed['category'] !== ''
    ? trim($parsed['category']) : null;
```

**問題**：
- `add_entry.php` 第 77-78 行限制 category 長度 ≤ 50 字元
- 後端未驗證長度，若 Gemini 回傳超長分類，前端填入後提交會被擋下
- 建議後端同步驗證 `mb_strlen($category, 'UTF-8') <= 50`

---

### 15. 【Minor】`.env.example` 第 16 行 placeholder 值 `your_gemini_api_key_here` 被 `config.php` 視為未設定

**位置**：
- `.env.example`：`GEMINI_API_KEY=your_gemini_api_key_here`
- `config.php` 第 58 行：`'your_gemini_api_key_here'` 在 placeholder 列表中

**現象**：複製 `.env.example` 為 `.env` 但未改金鑰時，系統視為未設定（正確行為）。但錯誤訊息「系統尚未設定 Gemini API 金鑰」可能讓使用者以為完全未設定，而非「請替換預設值」。

**建議**：錯誤訊息改為「請於 .env 設定有效的 Gemini API 金鑰（目前為預設佔位符）」，或保持現狀亦可接受。

---

### 16. 【Minor】Design.md 第 46 行 Mermaid 圖表中 `GEMINI_API_KEY` 顯示於流程圖

**位置**：Design.md 第 39 行：
```
G["後端以 GEMINI_API_KEY 呼叫 Gemini API"]
```

**建議**：流程圖不應顯示金鑰名稱，改為「後端以金鑰呼叫 Gemini API」即可。

---

## 五、正面回饋（做得好的地方）

1. ✅ **架構設計正確**：後端代理模式，API Key 僅存在後端，前端不直接呼叫 Gemini，符合 SEC-01
2. ✅ **輸入長度限制**：前後端皆限制 500 字，防止 prompt injection
3. ✅ **不自動寫入資料庫**：解析結果僅填表單，使用者確認後才儲存，避免 AI 幻覺造成髒資料
4. ✅ **整數分金額處理**：使用 `toCents()` / `formatCents()`，禁用 FLOAT，符合專案硬性約束
5. ✅ **審計日誌**：`add_entry.php` 儲存時呼叫 `logAudit('entry_create')`，完整記錄
6. ✅ **錯誤處理不中斷主流程**：AI 失敗時友善提示，手動記帳完全不受影響
7. ✅ **CSP 相容**：JS 外部化至 `assets/ai-parse.js`，無 `unsafe-inline`
8. ✅ **無障礙設計**：`aria-label`、`aria-expanded`、`role="status"` 等語意標記完整

---

## 六、給開發者的問題

| # | 問題 | 優先級 |
|---|------|--------|
| Q1 | 是否規劃支援 `transfer`（轉帳）類型？目前 Prompt 與前端皆未處理。 | High |
| Q2 | Rate Limit 20 次/分鐘是否經過產品端確認？Gemini 免費額度通常較高，是否過於保守？ | Medium |
| Q3 | `ai_parse.php` 是否需要支援批次解析（多筆文字一次送）？ | Low |
| Q4 | Prompt 中的分類範例（餐費、薪資、交通、娛樂）是否應從資料庫 `categories` 表動態取得？ | Medium |

---

## 七、審查結論

### Verdict: **Request Changes**

**必須修正項目（Critical/High）**：
1. 移除語音功能殘留代碼（add_entry.php、ai-parse.js、style.css）並同步更新 Design.md
2. 修正 CSRF Token 傳遞（前端補上 header，Design.md 更新說明）
3. 修正 Gemini API Key 洩漏風險（error_log 過濾敏感資訊）
4. 將 `api_rate_limits` 建表移至 `db.php::ensureSchema()`
5. 修正前後端 `amount`/`amount_cents` 欄位名不一致
6. Prompt 日期格式修正 `Y-m-dd` → `Y-m-d`

**建議修正項目（Major）**：
7-11. 模型名稱設定化、錯誤碼結構化、transfer 類型支援、GEMINI_API_KEY 常數化

---

## 八、後續流程

1. 開發者修正上述問題
2. 更新 `Design.md` 版本為 v1.2，記錄修正內容
3. 由獨立第三方審查者**重新審查**（不可由設計/實作同一人自審）
4. 審查通過後，更新 `Design.md` 同步實作細節與驗收結果
5. 進入實作階段三階段審查（Spec / Code / Security）

---

**審查者簽名**：獨立第三方審查者  
**日期**：2026-11-23