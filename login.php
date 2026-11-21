<?php
// 全家人記帳系統 — 登入頁（以 .env 管理員帳密驗證，帳號於 users 表種子匯入）
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 已登入則直接進入儀表板
if (!empty($_SESSION['user_id'])) {
    redirect('./dashboard.php');
}

$username = '';
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = '請輸入帳號與密碼';
    } else {
        // 登入失敗計數僅綁定於帳號，不納入 IP，避免同一公網 IP 下的家庭成員互相導致鎖定（FIND-001）
        $identifier = $username;

        if (isLoginLocked($identifier)) {
            // 鎖定期間內不進行密碼比對；前端顯示與一般失敗相同訊息，避免使用者列舉（FIND-005）
            logAudit('login_locked', 'identifier: ' . $identifier, null, $username);
            $error = '帳號或密碼錯誤';
        } else {
            $pdo = getPdo();
            $stmt = $pdo->prepare(
                'SELECT id, username, password_hash, role
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user !== false && password_verify($password, $user['password_hash'])) {
                clearLoginAttempts($identifier);
                // 重新產生 session id，防止 session fixation
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['authed']    = true;
                $_SESSION['authed_at'] = time();
                $_SESSION['last_activity'] = time(); // FIND-016：記錄最後活動時間
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                logAudit('login', '', (int) $user['id'], (string) $user['username']);

                // 登入異常通知：記錄登入來源並比對歷史 IP（Feature 14）
                try {
                    $ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                    $userAgentRaw = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
                    $userAgent = mb_substr($userAgentRaw, 0, 255);
                    $loginAt  = date('Y-m-d H:i:s');

                    // 若 IP 或 User-Agent 為空，不視為異常，不寫入紀錄也不顯示提醒
                    if ($ip !== '' && $userAgent !== '') {
                        // 90 天資料保留：清理過期紀錄（僅於登入成功後執行，不放入 getPdo()）
                        $cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
                        $pdo->prepare('DELETE FROM login_sessions WHERE login_at < :cutoff')
                            ->execute([':cutoff' => $cutoff]);

                        // 寫入本次登入來源
                        $pdo->prepare(
                            'INSERT INTO login_sessions (user_id, ip, user_agent, login_at, is_first_seen)
                             VALUES (:user_id, :ip, :user_agent, :login_at, 1)'
                        )->execute([
                            ':user_id'   => (int) $user['id'],
                            ':ip'        => $ip,
                            ':user_agent'=> $userAgent,
                            ':login_at'  => $loginAt,
                        ]);

                        // 比對歷史 IP，若為新來源則設定 session 提醒標記（僅以 IP 為主要判定）
                        $histStmt = $pdo->prepare(
                            'SELECT COUNT(*) AS cnt FROM login_sessions
                             WHERE user_id = :user_id AND ip = :ip AND id <> :cur_id'
                        );
                        $histStmt->execute([
                            ':user_id' => (int) $user['id'],
                            ':ip'      => $ip,
                            ':cur_id'  => (int) $pdo->lastInsertId(),
                        ]);
                        $histRow = $histStmt->fetch();
                        if ($histRow !== false && (int) $histRow['cnt'] === 0) {
                            $_SESSION['show_login_notice'] = 1;
                            $_SESSION['login_notice_ip']   = $ip;
                            $_SESSION['login_notice_at']   = $loginAt;
                        }
                    }
                } catch (Throwable $e) {
                    // 登入來源記錄失敗不阻擋登入，改寫 error.log
                    logError('login anomaly notice failed: ' . $e->getMessage());
                }

                redirect('./dashboard.php');
            }

            recordLoginFailure($identifier);
            logAudit('login_failed', 'identifier: ' . $identifier, null, $username);
            $error = '帳號或密碼錯誤';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
</head>
<body>
    <div class="login-page">
        <section class="login-hero">
            <div class="seal">全家<br>記帳</div>
            <h1>全家人記帳系統</h1>
            <p>收支各自記、帳共用看。一家人的每一筆錢，都有一張傳票對上號。</p>
            <div class="stamp-row"><i>收入聯・紅</i><i>支出聯・藍</i><i>月結・核對</i></div>
        </section>

        <section class="login-panel">
            <div class="login-card">
                <h2>登入</h2>
                <p class="sub">以管理員帳號進入共同帳本</p>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($error); ?></p>
                <?php endif; ?>

                <form method="POST" action="./login.php" autocomplete="off" class="form">
                    <div class="field">
                        <label for="username" class="label">管理員帳號</label>
                        <input
                            type="text"
                            name="username"
                            id="username"
                            required
                            autocapitalize="none"
                            spellcheck="false"
                            class="input"
                            value="<?php echo h($username); ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="password" class="label">管理員密碼</label>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            required
                            autocapitalize="none"
                            spellcheck="false"
                            class="input"
                        >
                    </div>

                    <button type="submit" class="btn primary btn-block">登入</button>
                </form>

                <p class="meta" style="margin-top: var(--space-4); font-size: var(--text-xs);">初始帳號請參見專案根目錄 .env（部署後務必變更）。</p>
            </div>
        </section>
    </div>
</body>
</html>
