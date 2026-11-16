<?php
// 全家人記帳系統 — 已封存的帳本：封存帳本不出現在帳本列表，於此獨立頁面列示。
// 所有曾有存取權的人（owner / member / viewer）皆可看見；member / viewer 唯讀。
// 還原權限與帳本列表時期一致：帳本擁有者或管理員可還原。
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msg     = $_GET['msg'] ?? '';

// ---------------- POST：還原帳本（CSRF + 預備陳述式）---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'restore_ledger') {
        if (!checkCsrf($_POST['csrf_token'] ?? '')) {
            redirect('./archived_ledgers.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
        }
        $ledgerId = filter_var($_POST['ledger_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ledgerId === false || $ledgerId === null) {
            redirect('./archived_ledgers.php?error=1&msg=' . urlencode('無效的帳本。'));
        }
        $stmt = $pdo->prepare('
            SELECT l.owner_id, (SELECT role FROM ledger_access WHERE ledger_id = l.id AND user_id = :user_id) AS my_role
            FROM ledgers l WHERE l.id = :ledger_id LIMIT 1
        ');
        $stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            redirect('./archived_ledgers.php?error=1&msg=' . urlencode('帳本不存在。'));
        }
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $canRestore = $isAdmin || ($row['my_role'] === 'owner');
        if (!$canRestore) {
            redirect('./archived_ledgers.php?error=1&msg=' . urlencode('只有帳本擁有者或管理員可以還原帳本。'));
        }

        $stmt = $pdo->prepare('UPDATE ledgers SET archived = 0 WHERE id = :id');
        $stmt->execute([':id' => $ledgerId]);
        redirect('./archived_ledgers.php?success=1&msg=' . urlencode('帳本已還原，回到帳本列表，記錄完整保留。'));
    }
    redirect('./archived_ledgers.php?error=1');
}

// 列出目前使用者可存取的已封存帳本（owner / member / viewer 皆可見；member / viewer 唯讀）
$stmt = $pdo->prepare('
    SELECT
        l.id,
        l.name,
        la.role AS access_role,
        (
            SELECT COUNT(*)
            FROM entries e
            WHERE e.ledger_id = l.id
              AND e.deleted = 0
        ) AS entry_count
    FROM ledgers l
    INNER JOIN ledger_access la
        ON la.ledger_id = l.id
       AND la.user_id = :user_id
    WHERE l.archived = 1
    ORDER BY l.name
');
$stmt->execute([':user_id' => $userId]);
$archivedLedgers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>已封存的帳本 - 全家人記帳系統</title>
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
                <a class="nav-item active" href="./ledgers.php">帳本列表</a>
                <a class="nav-item" href="./change_password.php">變更密碼</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <p class="nav-h">後台</p>
                    <a class="nav-item" href="./admin/users.php">使用者管理</a>
                    <a class="nav-item" href="./admin/ledgers.php">帳本管理</a>
                <?php endif; ?>
            </nav>
            <div class="side-foot">
                <div class="who"><b><?php echo h($user['username']); ?></b><span class="role-chip"><?php echo h($user['role']); ?></span></div>
                <a class="logout" href="./logout.php">登出</a>
            </div>
        </aside>

        <div class="main">
            <main class="sheet">
                <div class="page-head">
                    <div>
                        <h1>已封存的帳本</h1>
                        <p class="sub">封存的帳本不出現在帳本列表，不能記帳或作廢記錄；記錄完整保留。</p>
                    </div>
                    <a href="./ledgers.php" class="btn secondary sm">← 返回帳本列表</a>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '無法還原指定帳本。'); ?></p>
                <?php endif; ?>

                <p class="meta">還原權限：帳本擁有者或管理員可還原；member／viewer 僅能檢視，還原後帳本會回到帳本列表。</p>

                <?php if ($archivedLedgers): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>帳本名稱</th>
                                    <th>您的身份</th>
                                    <th class="num hide-m">記錄數（未刪除）</th>
                                    <th class="ctr">還原</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivedLedgers as $ledger): ?>
                                    <tr>
                                        <td><strong><?php echo h($ledger['name']); ?></strong></td>
                                        <td><span class="role-tag <?php echo h($ledger['access_role']); ?>"><?php echo h($ledger['access_role']); ?></span></td>
                                        <td class="num hide-m"><?php echo h((string) $ledger['entry_count']); ?></td>
                                        <td class="ctr">
                                            <?php if ($user['role'] === 'admin' || $ledger['access_role'] === 'owner'): ?>
                                                <form method="POST" action="./archived_ledgers.php" onsubmit="return confirm('還原此帳本？還原後會回到帳本列表。');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                    <input type="hidden" name="action" value="restore_ledger">
                                                    <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledger['id']); ?>">
                                                    <button type="submit" class="btn sm secondary">還原</button>
                                                </form>
                                            <?php else: ?>
                                                <span aria-hidden="true">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty">目前沒有已封存的帳本。</p>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>
