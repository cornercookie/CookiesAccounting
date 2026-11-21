<?php
// 全家人記帳系統 — 自助變更密碼：任何登入者皆可變更自己的密碼（不需管理員）
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

requireLogin();

$user = currentUser();
$pdo  = getPdo();
$myId = (int) $user['id'];

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf_token'] ?? '')) {
        $error = '請求驗證失敗，請重新提交表單。';
    } else {
        // 舊密碼失敗次數以 user_id 為鍵獨立鎖定，避免暴力破解（FIND-003）
        $pwdChangeIdentifier = 'password_change:' . $myId;
        if (isLoginLocked($pwdChangeIdentifier)) {
            $error = '目前密碼錯誤。';
        } else {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $new === '' || $confirm === '') {
                $error = '請填寫所有欄位。';
            } else {
                $strength = validatePasswordStrength($new);
                if (!$strength['ok']) {
                    $error = $strength['reason'];
                } elseif ($new !== $confirm) {
                    $error = '新密碼與確認密碼不一致。';
                } else {
                    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $myId]);
                    $row = $stmt->fetch();
                    if ($row === false || !password_verify($current, $row['password_hash'])) {
                        // 記錄失敗，累計達 5 次將鎖定 15 分鐘；訊息不區分是否為密碼錯誤
                        recordLoginFailure($pwdChangeIdentifier);
                        logAudit('password_change_failed', 'user_id=' . $myId);
                        $error = '目前密碼錯誤。';
                    } else {
                        $hash = password_hash($new, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                        $stmt->execute([':hash' => $hash, ':id' => $myId]);
                        logAudit('password_change');
                        $success = true;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>變更密碼 - 全家人記帳系統</title>
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
                <a class="nav-item active" href="./change_password.php">變更密碼</a>
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
                        <h1>變更密碼</h1>
                        <p class="sub">變更自己的密碼，不需要管理員。</p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status">密碼已更新。下次登入請使用新密碼。</p>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <p class="alert error" role="alert"><?php echo h($error); ?></p>
                <?php endif; ?>

                <form method="POST" action="./change_password.php" autocomplete="off" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">

                    <div class="form-grid">
                        <div class="field field-full">
                            <label for="current_password" class="label">目前密碼</label>
                            <input type="password" name="current_password" id="current_password" required autocomplete="current-password" class="input">
                        </div>
                        <div class="field">
                            <label for="new_password" class="label">新密碼</label>
                            <input type="password" name="new_password" id="new_password" required autocomplete="new-password" class="input" placeholder="至少 6 個字元">
                        </div>
                        <div class="field">
                            <label for="confirm_password" class="label">確認新密碼</label>
                            <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password" class="input">
                        </div>
                    </div>

                    <button type="submit" class="btn primary btn-block">更新密碼</button>
                </form>
            </main>
        </div>
    </div>

    <script>
        // 字級切換：Cookie 讀寫與 .font-large 切換
        (function () {
            var toggle = document.getElementById('fontSizeToggle');
            if (!toggle) return;

            function getCookie(name) {
                var value = '; ' + document.cookie;
                var parts = value.split('; ' + name + '=');
                if (parts.length === 2) return parts.pop().split(';').shift();
                return null;
            }

            function setCookie(name, value, days) {
                var expires = new Date();
                expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000);
                document.cookie = name + '=' + value + '; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
            }

            var fontSize = getCookie('font_size');
            if (fontSize === 'large') {
                document.body.classList.add('font-large');
                toggle.textContent = '一般 A';
            }

            toggle.addEventListener('click', function () {
                if (document.body.classList.contains('font-large')) {
                    document.body.classList.remove('font-large');
                    setCookie('font_size', 'normal', 90);
                    toggle.textContent = '大字 A';
                } else {
                    document.body.classList.add('font-large');
                    setCookie('font_size', 'large', 90);
                    toggle.textContent = '一般 A';
                }
            });
        })();
    </script>
</body>
</html>
