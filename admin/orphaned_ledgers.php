<?php
// 全家人記帳系統 — 後台：擁有者已刪除的帳本（僅管理員可存取）
// 使用者被刪除時，其帳本的 owner_id 不會被清除，但對應帳號已不存在；
// 此頁列出這些帳本，可重新指定一位現有使用者為擁有者（新擁有者取得 owner 權限）。
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

// 僅管理員可存取後台頁面
requireAdmin();

$user = currentUser();
$pdo  = getPdo();

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msg     = signedNotice();

// ---------------- POST：重新指定擁有者（CSRF 驗證 + 預備陳述式 + 交易）---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'reassign_owner') {
        if (!checkCsrf($_POST['csrf_token'] ?? '')) {
            redirect('./orphaned_ledgers.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
        }
        $ledgerId    = filter_var($_POST['ledger_id'] ?? null, FILTER_VALIDATE_INT);
        $newOwnerId  = filter_var($_POST['new_owner_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ledgerId === false || $ledgerId === null || $newOwnerId === false || $newOwnerId === null) {
            redirect('./orphaned_ledgers.php?error=1&msg=' . urlencode('請指定正確的帳本與新擁有者。'));
        }

        // 帳本必須存在，且原本的擁有者真的已不存在（避免誤操作正常帳本）
        $stmt = $pdo->prepare('
            SELECT l.id FROM ledgers l
            LEFT JOIN users u ON u.id = l.owner_id
            WHERE l.id = :id AND u.id IS NULL
            LIMIT 1
        ');
        $stmt->execute([':id' => $ledgerId]);
        if ($stmt->fetch() === false) {
            redirect('./orphaned_ledgers.php?error=1&msg=' . urlencode('此帳本不存在，或其擁有者尚未刪除。'));
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $newOwnerId]);
        if ($stmt->fetch() === false) {
            redirect('./orphaned_ledgers.php?error=1&msg=' . urlencode('指定的新擁有者不存在。'));
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE ledgers SET owner_id = :owner_id WHERE id = :id');
            $stmt->execute([':owner_id' => $newOwnerId, ':id' => $ledgerId]);

            // 新擁有者若已有存取列則升級為 owner，否則新增 owner 存取列
            $stmt = $pdo->prepare('SELECT id FROM ledger_access WHERE ledger_id = :ledger_id AND user_id = :user_id LIMIT 1');
            $stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $newOwnerId]);
            if ($stmt->fetch() !== false) {
                $stmt = $pdo->prepare('UPDATE ledger_access SET role = :role WHERE ledger_id = :ledger_id AND user_id = :user_id');
                $stmt->execute([':role' => 'owner', ':ledger_id' => $ledgerId, ':user_id' => $newOwnerId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO ledger_access (ledger_id, user_id, role) VALUES (:ledger_id, :user_id, :role)');
                $stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $newOwnerId, ':role' => 'owner']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            logError('admin_ledger_reassign_owner failed (ledger_id=' . $ledgerId . ', new_owner_id=' . $newOwnerId . '): ' . $e->getMessage());
            redirect('./orphaned_ledgers.php?error=1&msg=' . urlencode('重新指定失敗：' . $e->getMessage()));
        }
        logAudit('admin_ledger_reassign_owner', 'ledger_id=' . $ledgerId . ' new_owner_id=' . $newOwnerId);
        redirect('./orphaned_ledgers.php?success=1&msg=' . urlencode('已重新指定擁有者，新擁有者已取得 owner 權限。'));
    }
    redirect('./orphaned_ledgers.php?error=1');
}

// ---------------- GET：列出擁有者帳號已刪除的帳本 ----------------
$stmt = $pdo->query('
    SELECT l.id, l.name, l.owner_id, l.archived, l.created_at,
        (
            SELECT COUNT(*)
            FROM entries e
            WHERE e.ledger_id = l.id
              AND e.deleted = 0
        ) AS entry_count
    FROM ledgers l
    LEFT JOIN users u ON u.id = l.owner_id
    WHERE u.id IS NULL
    ORDER BY l.id
');
$orphanedLedgers = $stmt->fetchAll();

$allUsers = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>後台 - 擁有者已刪除的帳本 - 全家人記帳系統</title>
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
                <a class="nav-item" href="../ledgers.php">新增記錄</a>
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
                        <h1>擁有者已刪除的帳本</h1>
                        <p class="sub">這些帳本的擁有者帳號已被刪除（原本的記帳記錄亦已隨帳號移除）。可重新指定擁有者，讓帳本恢復管理。</p>
                    </div>
                    <a href="./ledgers.php" class="btn secondary sm">← 返回帳本管理</a>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗，請檢查輸入後重試。'); ?></p>
                <?php endif; ?>

                <p class="meta">重新指定後，新擁有者會取得 owner 權限並回到帳本管理主頁（若已封存則出現在「已封存的帳本」）。其他成員原有的存取權限不受影響。</p>

                <?php if ($orphanedLedgers): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>帳本名稱</th>
                                    <th>原擁有者</th>
                                    <th class="hide-m">狀態</th>
                                    <th class="num hide-m">記錄數（未刪除）</th>
                                    <th class="hide-m">建立於</th>
                                    <th class="ctr">重新指定擁有者</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orphanedLedgers as $ledger): ?>
                                    <tr>
                                        <td><strong><?php echo h($ledger['name']); ?></strong>（ID：<?php echo h((string) $ledger['id']); ?>）</td>
                                        <td><span class="meta">已刪除的帳號（原 ID：<?php echo h((string) $ledger['owner_id']); ?>）</span></td>
                                        <td class="hide-m">
                                            <?php if ((int) ($ledger['archived'] ?? 0) === 1): ?>
                                                <span class="role-tag viewer">已封存</span>
                                            <?php else: ?>
                                                <span class="role-tag member">使用中</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="num hide-m"><?php echo h((string) $ledger['entry_count']); ?></td>
                                        <td class="hide-m"><?php echo h($ledger['created_at']); ?></td>
                                        <td class="ctr">
                                            <?php if ($allUsers): ?>
                                                <form method="POST" action="./orphaned_ledgers.php" style="margin: 0;" data-confirm="重新指定擁有者？新擁有者會取得 owner 權限。">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                    <input type="hidden" name="action" value="reassign_owner">
                                                    <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledger['id']); ?>">
                                                    <select name="new_owner_id" class="input" required aria-label="新擁有者" style="width: auto; display: inline-block; padding: var(--space-2) var(--space-4); font-size: var(--text-sm);">
                                                        <?php foreach ($allUsers as $candidate): ?>
                                                            <option value="<?php echo h((string) $candidate['id']); ?>"><?php echo h($candidate['username']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn primary sm">指定</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="meta">尚無使用者可指定。</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty">目前沒有擁有者已刪除的帳本。</p>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <script src="../assets/app.js?v=30"></script>
</body>
</html>
