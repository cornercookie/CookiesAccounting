<?php
// 全家人記帳系統 — 前端路由

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入 -> 登入頁；已登入 -> 內頁儀表板
if (empty($_SESSION['user_id'])) {
    redirect('./login.php');
}
redirect('./dashboard.php');
