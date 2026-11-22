<?php
// 全家人記帳系統 — 後台：已封存的帳本（僅管理員可存取）
// 封存的帳本不出現在帳本管理主頁；資料完整保留，可於此還原。
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

// 僅管理員可存取後台頁面
requireAdmin();

$user = currentUser();
$pdo  = getPdo();

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msg     = $_GET['msg'] ?? '';

// ---------------- POST：還原帳本（CSRF 驗證 + 預備陳述式）---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'restore_ledger') {
        if (!checkCsrf($_POST['csrf_token'] ?? '')) {
            redirect('./archived_ledgers.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
        }
        $ledgerId = filter_var($_POST['ledger_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ledgerId === false || $ledgerId === null) {
            redirect('./archived_ledgers.php?error=1&msg=' . urlencode('無效的帳本。'));
        }
        $stmt = $pdo->prepare('UPDATE ledgers SET archived = 0 WHERE id = :id');
        $stmt->execute([':id' => $ledgerId]);
        logAudit('ledger_restore', 'ledger_id=' . $ledgerId);
        redirect('./archived_ledgers.php?success=1&msg=' . urlencode('帳本已還原，回到帳本管理主頁，記錄完整保留。'));
    }
    redirect('./archived_ledgers.php?error=1');
}

// ---------------- GET：列出所有已封存的帳本 ----------------
// LEFT JOIN：owner 帳號可能已刪除（顯示為「已刪除的擁有者」）
$stmt = $pdo->query('
    SELECT l.id, l.name, l.owner_id, l.created_at, u.username AS owner_username,
        (
            SELECT COUNT(*)
            FROM entries e
            WHERE e.ledger_id = l.id
              AND e.deleted = 0
        ) AS entry_count
    FROM ledgers l
    LEFT JOIN users u ON u.id = l.owner_id
    WHERE l.archived = 1
    ORDER BY l.id
');
$archivedLedgers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>後台 - 已封存的帳本 - 全家人記帳系統</title>
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
                <a class="nav-item" href="../search.php">全站搜尋</a>
                <a class="nav-item" href="../change_password.php">變更密碼</a>
                <p class="nav-h">後台</p>
                <a class="nav-item" href="./users.php">使用者管理</a>
                <a class="nav-item active" href="./ledgers.php">帳本管理</a>
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
                        <h1>已封存的帳本</h1>
                        <p class="sub">帳本管理主頁封存的帳本都在此；不能記帳或作廢記錄，記錄完整保留。</p>
                    </div>
                    <a href="./ledgers.php" class="btn secondary sm">← 返回帳本管理</a>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗，請檢查輸入後重試。'); ?></p>
                <?php endif; ?>

                <?php if ($archivedLedgers): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>帳本名稱</th>
                                    <th>擁有者</th>
                                    <th class="num hide-m">記錄數（未刪除）</th>
                                    <th class="hide-m">建立於</th>
                                    <th class="ctr">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archivedLedgers as $ledger): ?>
                                    <tr>
                                        <td><strong><?php echo h($ledger['name']); ?></strong>（ID：<?php echo h((string) $ledger['id']); ?>）</td>
                                        <td>
                                            <?php if ($ledger['owner_username'] !== null): ?>
                                                <?php echo h($ledger['owner_username']); ?>
                                            <?php else: ?>
                                                <span class="meta">已刪除的擁有者（原 ID：<?php echo h((string) $ledger['owner_id']); ?>）</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="num hide-m"><?php echo h((string) $ledger['entry_count']); ?></td>
                                        <td class="hide-m"><?php echo h($ledger['created_at']); ?></td>
                                        <td class="ctr">
                                            <form method="POST" action="./archived_ledgers.php" style="margin: 0;" onsubmit="return confirm('還原此帳本？還原後會回到帳本管理主頁。');">
                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                <input type="hidden" name="action" value="restore_ledger">
                                                <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledger['id']); ?>">
                                                <button type="submit" class="btn sm secondary">還原此帳本</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty">目前沒有已封存的帳本。</p>
                <?php endif; ?>

                <p class="meta" style="border-top: 1px solid var(--line); padding-top: var(--space-4); margin-top: var(--space-6);">
                    還原後帳本會回到帳本管理主頁；封存不會刪除任何資料，記錄永遠保留。
                </p>
            </main>
        </div>
    </div>
</body>
</html>
