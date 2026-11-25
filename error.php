<?php
// 全家人記帳系統 — 通用錯誤頁面
require_once __DIR__ . '/functions.php';

$user = currentUser();
$code = http_response_code();
$message = '';

switch ($code) {
    case 400:
        $message = '請求參數錯誤。請確認匯出格式（csv/json）與年月參數（YYYY-MM）正確。';
        break;
    case 403:
        $message = '您無權存取此功能或資源。';
        break;
    case 404:
        $message = '找不到指定的資料。';
        break;
    case 500:
    default:
        $message = '系統發生錯誤，請稍後再試。';
        break;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>錯誤 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
</head>
<body>
    <div class="app">
        <aside class="side">
            <a class="brand" href="./dashboard.php">
                <span class="brand-kicker">FAMILY VOUCHER</span>
                <b>全家人記帳系統</b>
                <span class="brand-mark" aria-hidden="true"><i class="in"></i><i class="out"></i></span>
            </a>
            <nav class="nav" aria-label="主要導覽">
                <p class="nav-h">帳務</p>
                <a class="nav-item" href="./dashboard.php">儀表板</a>
                <a class="nav-item" href="./ledgers.php">帳本列表</a>
                <a class="nav-item" href="./calendar.php">行事曆檢視</a>
                <a class="nav-item" href="./change_password.php">變更密碼</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <p class="nav-h">後台</p>
                    <a class="nav-item" href="./admin/users.php">使用者管理</a>
                    <a class="nav-item" href="./admin/ledgers.php">帳本管理</a>
                    <a class="nav-item" href="./admin/audit_log.php">操作紀錄</a>
                <?php endif; ?>
            </nav>
            <div class="side-foot">
                <div class="who"><b><?php echo h($user['username']); ?></b><span class="role-chip"><?php echo h($user['role']); ?></span></div>
                <button type="button" class="font-size-toggle" id="fontSizeToggle" title="切換大字模式">大字 A</button>
                <a class="logout" href="./logout.php">登出</a>
            </div>
        </aside>

        <div class="main">
            <main class="sheet">
                <div class="page-head">
                    <div>
                        <h1>錯誤</h1>
                        <p class="sub">HTTP <?php echo h((string) $code); ?></p>
                    </div>
                </div>

                <div class="alert error" role="alert" style="text-align: center; padding: var(--space-8);">
                    <p style="font-size: var(--text-lg); margin-bottom: var(--space-4);"><?php echo h($message); ?></p>
                    <a href="./ledgers.php" class="btn primary">返回帳本列表</a>
                </div>
            </main>
        </div>
    </div>

    <script src="./assets/app.js"></script>
</body>
</html>