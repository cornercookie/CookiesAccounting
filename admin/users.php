<?php
// 全家人記帳系統 — 後台：使用者管理（僅管理員可存取）
// 建立使用者、重設密碼、變更角色、刪除使用者；所有寫入皆使用 PDO 預備陳述式，
// 每張表單皆含 CSRF 欄位並於 POST 時驗證。
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

// 僅管理員可存取後台頁面
requireAdmin();

$user = currentUser();
$pdo  = getPdo();
$myId = (int) $user['id'];

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msg     = $_GET['msg'] ?? '';

// ---------------- POST：處理所有動作（CSRF 驗證 + 預備陳述式）---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf_token'] ?? '')) {
        redirect('./users.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
    }

    $action = $_POST['action'] ?? '';

    // ---------- 建立使用者 ----------
    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '';

        if ($username === '' || $password === '' || $role === '') {
            redirect('./users.php?error=1&msg=' . urlencode('帳號、密碼與角色皆為必填。'));
        }
        if (!preg_match('/^[A-Za-z0-9_]{2,32}$/', $username)) {
            redirect('./users.php?error=1&msg=' . urlencode('帳號格式不正確（2–32 個字元，僅限英數字與底線）。'));
        }
        $strength = validatePasswordStrength($password);
        if (!$strength['ok']) {
            redirect('./users.php?error=1&msg=' . urlencode($strength['reason']));
        }
        if (!in_array($role, ['admin', 'member'], true)) {
            redirect('./users.php?error=1&msg=' . urlencode('角色必須為管理員或成員。'));
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch() !== false) {
            redirect('./users.php?error=1&msg=' . urlencode('該帳號已經存在，請使用其他帳號。'));
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, :role)'
        );
        $stmt->execute([
            ':username' => $username,
            ':hash'     => $hash,
            ':role'     => $role,
        ]);
        $newUserId = (int) $pdo->lastInsertId();
        logAudit('admin_user_create', 'user_id=' . $newUserId . ' username=' . $username . ' role=' . $role);
        redirect('./users.php?success=1&msg=' . urlencode('使用者「' . $username . '」已建立。'));
    }

    // ---------- 重設密碼 ----------
    if ($action === 'reset_password') {
        $targetId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT);
        $password = $_POST['password'] ?? '';

        if ($targetId === false || $targetId === null) {
            redirect('./users.php?error=1&msg=' . urlencode('指定的使用者不存在。'));
        }
        $strength = validatePasswordStrength($password);
        if (!$strength['ok']) {
            redirect('./users.php?error=1&msg=' . urlencode($strength['reason']));
        }

        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $targetId]);
        $target = $stmt->fetch();
        if ($target === false) {
            redirect('./users.php?error=1&msg=' . urlencode('指定的使用者不存在。'));
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, password_changed_at = datetime(\'now\',\'localtime\') WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $targetId]);
        logAudit('admin_user_reset_password', 'target_user_id=' . $targetId . ' username=' . (string) $target['username']);
        redirect('./users.php?success=1&msg=' . urlencode('該使用者的密碼已重設。'));
    }

    // ---------- 變更角色 ----------
    if ($action === 'change_role') {
        $targetId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT);
        $role     = $_POST['role'] ?? '';

        if ($targetId === false || $targetId === null || !in_array($role, ['admin', 'member'], true)) {
            redirect('./users.php?error=1&msg=' . urlencode('請指定正確的使用者與角色。'));
        }
        if ($targetId === $myId) {
            redirect('./users.php?error=1&msg=' . urlencode('不可變更您自己的角色。'));
        }

        $stmt = $pdo->prepare('SELECT id, role, username FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $targetId]);
        $target = $stmt->fetch();
        if ($target === false) {
            redirect('./users.php?error=1&msg=' . urlencode('指定的使用者不存在。'));
        }

        // 系統至少需保留一位管理員
        if ($target['role'] === 'admin' && $role !== 'admin') {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM users WHERE role = :role');
            $stmt->execute([':role' => 'admin']);
            $adminCount = (int) $stmt->fetchColumn();
            if ($adminCount <= 1) {
                redirect('./users.php?error=1&msg=' . urlencode('不能移除最後一位管理員，系統至少需保留一位管理員。'));
            }
        }

        $stmt = $pdo->prepare('UPDATE users SET role = :role, password_changed_at = datetime(\'now\',\'localtime\') WHERE id = :id');
        $stmt->execute([':role' => $role, ':id' => $targetId]);
        logAudit('admin_user_change_role', 'target_user_id=' . $targetId . ' username=' . (string) $target['username'] . ' ' . $target['role'] . '→' . $role);
        redirect('./users.php?success=1&msg=' . urlencode('角色已更新。'));
    }

    // ---------- 刪除使用者 ----------
    if ($action === 'delete_user') {
        $targetId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT);

        if ($targetId === false || $targetId === null) {
            redirect('./users.php?error=1&msg=' . urlencode('指定的使用者不存在。'));
        }
        if ($targetId === $myId) {
            redirect('./users.php?error=1&msg=' . urlencode('不可刪除您自己的帳號。'));
        }

        $stmt = $pdo->prepare('SELECT id, role, username FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $targetId]);
        $target = $stmt->fetch();
        if ($target === false) {
            redirect('./users.php?error=1&msg=' . urlencode('指定的使用者不存在。'));
        }

        if ($target['role'] === 'admin') {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM users WHERE role = :role');
            $stmt->execute([':role' => 'admin']);
            $adminCount = (int) $stmt->fetchColumn();
            if ($adminCount <= 1) {
                redirect('./users.php?error=1&msg=' . urlencode('不能刪除最後一位管理員，系統至少需保留一位管理員。'));
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM ledger_access WHERE user_id = :id');
            $stmt->execute([':id' => $targetId]);
            // 軟刪除該使用者的帳務記錄，保留資料與審計可追溯性（FIND-004）
            $stmt = $pdo->prepare('UPDATE entries SET deleted = 1 WHERE user_id = :id');
            $stmt->execute([':id' => $targetId]);
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => $targetId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            logError('admin_user_delete failed for user_id=' . $targetId . ': ' . $e->getMessage());
            redirect('./users.php?error=1&msg=' . urlencode('刪除失敗，請稍後再試。'));
        }
        logAudit('admin_user_delete', 'target_user_id=' . $targetId . ' username=' . (string) $target['username']);
        redirect('./users.php?success=1&msg=' . urlencode('使用者「' . $target['username'] . '」已刪除。其帳本存取權與記帳記錄已一併移除；若該使用者名下帳本因此失去擁有者，請至帳本管理的「擁有者已刪除的帳本」頁面重新指定。'));
    }

    redirect('./users.php?error=1');
}

// ---------------- GET：顯示使用者列表 ----------------
$users = $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>後台 - 使用者管理 - 全家人記帳系統</title>
    <link rel="stylesheet" href="../assets/style.css?v=20261115-8">
</head>
<body>
    <div class="app">
        <aside class="side">
            <a class="brand" href="../dashboard.php">
                <span class="brand-kicker">FAMILY VOUCHER</span>
                <b>全家人記帳系統</b>
                <span class="brand-mark" aria-hidden="true"><i class="in"></i><i class="out"></i></span>
            </a>
            <nav class="nav" aria-label="主要導覽">
                <p class="nav-h">帳務</p>
                <a class="nav-item" href="../dashboard.php">儀表板</a>
                <a class="nav-item" href="../ledgers.php">帳本列表</a>
                <a class="nav-item" href="../multi_currency.php">多幣別記帳</a>
                <a class="nav-item" href="../search.php">全站搜尋</a>
                <a class="nav-item" href="../change_password.php">變更密碼</a>
                <p class="nav-h">後台</p>
                <a class="nav-item active" href="./users.php">使用者管理</a>
                <a class="nav-item" href="./category_management.php">分類管理</a>
                <a class="nav-item" href="./ledgers.php">帳本管理</a>
                <a class="nav-item" href="./audit_log.php">操作紀錄</a>
            </nav>
            <div class="side-foot">
                <div class="who"><b><?php echo h($user['username']); ?></b><span class="role-chip"><?php echo h($user['role']); ?></span></div>
                <a class="logout" href="../logout.php">登出</a>
            </div>
        </aside>

        <div class="main">
            <main class="sheet">
                <div class="page-head">
                    <div>
                        <h1>使用者管理</h1>
                        <p class="sub">建立、重設密碼、變更角色、刪除——只限管理員操作。</p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗，請檢查輸入後重試。'); ?></p>
                <?php endif; ?>

            <!-- 新增使用者 -->
            <h3>新增使用者</h3>
            <form method="POST" action="./users.php" autocomplete="off" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="field"><label for="create_username" class="label">使用者帳號</label>
                <input
                    type="text"
                    name="username"
                    id="create_username"
                    required
                    autocapitalize="none"
                    spellcheck="false"
                    class="input"
                    placeholder="例如 alice"
                >
                </div>
                <div class="field"><label for="create_password" class="label">密碼</label>
                <input type="password" name="password" id="create_password" required class="input" placeholder="至少 10 個字元">
                </div>
                <div class="field"><label for="create_role" class="label">角色</label>
                <select name="role" id="create_role" class="input">
                    <option value="member">成員</option>
                    <option value="admin">管理員</option>
                </select>
                </div>
                <button type="submit" class="btn primary btn-block">建立使用者</button>
            </form>
            <!-- 使用者列表 -->
            <h3>使用者列表</h3>
            <?php if ($users): ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>帳號</th>
                                <th>角色</th>
                                <th>建立時間</th>
                                <th class="ctr">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <?php
                                    $isSelf    = (int) $u['id'] === $myId;
                                    $roleLabel = $u['role'] === 'admin' ? '管理員' : '成員';
                                ?>
                                <tr>
                                    <td><?php echo h((string) $u['id']); ?></td>
                                    <td><?php echo h($u['username']); ?></td>
                                    <td><span class="role-tag <?php echo $u['role'] === 'admin' ? 'admin' : 'member'; ?>"><?php echo h($roleLabel); ?></span></td>
                                    <td><?php echo h($u['created_at']); ?></td>
                                    <td class="ctr">
                                        <!-- 重設密碼 -->
                                        <form method="POST" action="./users.php" style="margin: 0 0 var(--space-2) 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="target_user_id" value="<?php echo h((string) $u['id']); ?>">
                                            <input type="password" name="password" required placeholder="新密碼" class="input">
                                            <button type="submit" class="btn sm secondary" onclick="return confirm('確定要重設此使用者的密碼嗎？');">重設密碼</button>
                                        </form>
                                        <!-- 變更角色 -->
                                        <form method="POST" action="./users.php" style="margin: 0 0 var(--space-2) 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="target_user_id" value="<?php echo h((string) $u['id']); ?>">
                                            <select name="role" class="input">
                                                <option value="member"<?php echo $u['role'] === 'member' ? ' selected' : ''; ?>>成員</option>
                                                <option value="admin"<?php echo $u['role'] === 'admin' ? ' selected' : ''; ?>>管理員</option>
                                            </select>
                                            <button type="submit" class="btn sm secondary">變更角色</button>
                                        </form>
                                        <!-- 刪除使用者 -->
                                        <form method="POST" action="./users.php" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="target_user_id" value="<?php echo h((string) $u['id']); ?>">
                                            <button type="submit" class="btn btn-block sm danger"<?php if ($isSelf): ?> disabled<?php endif; ?> onclick="return confirm('確定要刪除此使用者嗎？其帳本存取權與記帳記錄將一併移除，且無法復原。');">刪除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>目前沒有任何使用者。</p>
            <?php endif; ?>

            <p class="meta" style="border-top: 1px solid var(--line); padding-top: var(--space-4); margin-top: var(--space-5);">
                限制：不可刪除自己的帳號，也不可刪除最後一位管理員（系統至少需保留一位管理員）。刪除使用者會一併移除其帳本存取權與記帳記錄。
            </p>
            </main>
        </div>
    </div>
</body>
</html>
