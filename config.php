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
   4. 時區
   ----------------------------------------------------------------- */
date_default_timezone_set('Asia/Taipei');

/* ----------------------------------------------------------------
   5. Session 基礎設定（登入狀態以 cookie + session 維持）
   ----------------------------------------------------------------- */
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');
session_name('cookies_accounting');
session_start();
