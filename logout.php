<?php
// 全家人記帳系統 — 登出

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 清除 session 與 cookie，導回登入頁
$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}
session_destroy();

redirect('./login.php');
