# Planning: AI 一句話記帳 (Gemini API 整合)

## Description

讓使用者（特別是不擅長點選繁複表單欄位的長輩與家庭成員）能透過手機語音說出一句話或直接輸入一段文字，系統透過後端 PHP 安全呼叫 Gemini API 解析出記帳欄位（金額、收入/支出、分類、日期、備註），並自動填入新增記錄表單中，實現極速隨手記。

## User Story

- 作為家庭成員，我希望只要說「今天中午吃麵 120 元」，系統就能自動幫我填好金額與分類，不用手動按一堆選單。
- 作為管理者/成員，我希望即使語音辨識有誤，也能在表單確認並手動修正後才正式送出，確保帳目準確。

## To Reproduce

1. 點擊新增記錄頁面的「AI 語音記帳」按鈕。
2. 對著手機說出收支內容（瀏覽器 Web Speech API 轉成文字），或直接在文字框輸入文字。
3. 提交至後端 PHP API，由後端帶上金鑰安全呼叫 Gemini API 進行結構化解析。
4. 解析成功後，JSON 資料回傳前端並自動填入表單欄位，使用者確認無誤後點擊儲存。

## Expected behavior

- **安全架構 (SEC-01)**：API Key 僅存於後端 `config.php` 或環境變數，前端絕不接觸金鑰，所有 Gemini 請求由後端 PHP 代理中繼。
- 支援 Web Speech API 語音轉文字；若瀏覽器不支援，平滑降級（Graceful Degradation）僅保留文字輸入框 (SPEC-01)。
- 後端接收到 Gemini 回應後，透過正規表達式清除 Markdown Code Block（` ```json ... ``` `）再進行 `json_decode` (CODE-01)。
- 解析失敗或欄位缺漏時，提供友善的提示，不影響一般手動記帳。

## Acceptance Criteria

1. 頁面提供「AI 語音記帳」功能入口與操作介面（支援語音識別與文字輸入）。
2. 後端安全代理呼叫 Gemini API 將自然語言轉換為結構化記帳 JSON（包含 `type`, `amount`, `category`, `date`, `description`）。
3. 自動填入表單後必須經由使用者確認並點擊儲存，不可直接無感自動寫入資料庫。
4. 具備完整的錯誤處理與 fallback 機制（API Key 未設定、網路失敗或 JSON 解析失敗時顯示提示）。

## Additional context

- 需在 `config.php` 新增 `GEMINI_API_KEY` 設定選項。
- 本功能為非強制擴充，不影響既有純手動記帳流程。
