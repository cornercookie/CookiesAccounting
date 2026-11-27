<?php
// 全家人記帳系統 — 登出

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (empty($_SESSION['user_id'])) {
        redirect('./login.php');
    }
    ?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登出 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
</head>
<body>
    <main class="login-page">
        <section class="login-panel">
            <div class="login-card">
                <h1>登出</h1>
                <p>確定要登出嗎？</p>
                <form method="POST" action="./logout.php" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                    <button type="submit" class="btn primary">登出</button>
                    <a href="./dashboard.php" class="btn secondary">取消</a>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
    <?php
    exit;
}

if (!checkCsrf(inputString($_POST, 'csrf_token'))) {
    redirect('./logout.php');
}

// 清除 session 與 cookie 前，先記錄登出（session 尚未銷毀時才拿得到使用者）
$logoutUser = currentUser();
if ($logoutUser !== null) {
    logAudit('logout', '', (int) $logoutUser['id'], (string) $logoutUser['username']);
}

// 清除 session 與 cookie，導回登入頁
$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}
session_destroy();

redirect('./login.php');
