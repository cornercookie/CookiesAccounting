<?php
// 全家人記帳系統 — 新架構設定（多使用者 + 管理員 + 共同帳本）
// 請先建立 .env 並設定 ADMIN_USERNAME 與 ADMIN_PASSWORD，再部署至 cPanel 主機。

declare(strict_types=1);

/* ----------------------------------------------------------------
   1. 載入 .env（純 PHP，不依賴 Composer）
      cPanel 主機可將 ADMIN_* 設為環境變數，或放置 .env 於專案根目錄
   ----------------------------------------------------------------- */
$envFile = __DIR__ . '/.env';
$envFromFile = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        // 去掉雙引號/單引號包圍
        if (preg_match('/^"(.*)"$/', $value, $m)) {
            $value = $m[1];
        } elseif (preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }
        $envFromFile[$key] = $value;
    }
}

/**
 * 讀取環境變數：優先使用 PHP 環境變數，否則退回 .env 檔案內容。
 */
function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        global $envFromFile;
        $value = $envFromFile[$key] ?? $default;
    }
    return (string) $value;
}

/* ----------------------------------------------------------------
   2. 管理員帳號（從 .env，取代舊版單一家庭密碼）
   ----------------------------------------------------------------- */
define('ADMIN_USERNAME', env('ADMIN_USERNAME'));
define('ADMIN_PASSWORD', env('ADMIN_PASSWORD'));

/* ----------------------------------------------------------------
   3. 資料庫（SQLite 唯一，位於私密目錄下）
   ----------------------------------------------------------------- */
define('DB_PATH', __DIR__ . '/private_data/cookies_xxxx.db');

/* ----------------------------------------------------------------
   3.5 錯誤記錄（PHP errors / warnings + 未捕捉例外 → private_data/error.log）

   - private_data/ 已被私密 .htaccess 封鎖，error.log 不會被瀏覽器下載
   - *.log 已被 .gitignore 忽略，不會進入版控
   - display_errors 關閉：錯誤細節（路徑、SQL、堆疊）不會洩漏給使用者，
     一律改寫到 error.log；畫面只顯示通用訊息（見下方例外處理器）
   ----------------------------------------------------------------- */
define('ERROR_LOG_PATH', dirname(DB_PATH) . '/error.log');
// 防禦：private_data 尚不存在時先建立（與 getPdo() 的 0770 一致），
// 否則 error.log 會因目錄不存在而靜默寫不到
if (!is_dir(dirname(ERROR_LOG_PATH))) {
    @mkdir(dirname(ERROR_LOG_PATH), 0770, true);
}
ini_set('log_errors', '1');
ini_set('error_log', ERROR_LOG_PATH);
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e): void {
    // 完整細節只進 error.log，不外洩給瀏覽器
    error_log(sprintf(
        "Uncaught %s: %s in %s:%d\n%s",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8">'
        . '<title>系統錯誤 - 全家人記帳系統</title></head><body>'
        . '<h1>系統發生錯誤</h1>'
        . '<p>很抱歉，系統暫時無法處理您的請求。此錯誤已記錄於伺服器日誌，請稍後再試；若持續發生，請聯絡管理員。</p>'
        . '</body></html>';
    exit;
});

/* ----------------------------------------------------------------
   4. 時區
   ----------------------------------------------------------------- */
date_default_timezone_set('Asia/Taipei');

/* ----------------------------------------------------------------
   5. Session 基礎設定（登入狀態以 cookie + session 維持）
   ----------------------------------------------------------------- */
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');
// Session Cookie 僅透過 HTTPS 傳輸（FIND-010）。部署前請先確認主機已啟用 HTTPS，
// 否則 Cookie 無法傳送而導致登入失敗。
ini_set('session.cookie_secure', '1');
// Session 伺服器端最長存活 30 分鐘（FIND-016），超過即視為過期需重新登入。
ini_set('session.gc_maxlifetime', 1800);
// Cookie 於瀏覽器關閉時即過期，避免共享電腦殘留登入狀態。
ini_set('session.cookie_lifetime', '0');
session_name('cookies_accounting');
session_start();

// Content Security Policy（FIND-011）：預設僅允許同源資源，防止 XSS 與資料外洩。
// 現有頁面內聯大量 <script>（字級切換、AI 語音等），短期內需保留 'unsafe-inline'，
// 長期建議將內聯腳本移至外部 JS 檔案並改用 nonce。
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self';");

// 點擊劫持防護（FIND-012）：禁止頁面被 <iframe> 嵌入。
header('X-Frame-Options: DENY');

// 參照策略（FIND-015）：避免從內部頁面連結外部網站時洩漏完整 URL。
header('Referrer-Policy: strict-origin-when-cross-origin');
