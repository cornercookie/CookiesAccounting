<?php
// 全家人記帳系統 — 後台：共同帳本管理（僅管理員可存取）
// 建立帳本、新增/更新存取、移除存取；所有寫入皆使用 PDO 預備陳述式，
// 每張表單皆含 CSRF 欄位並於 POST 時驗證。
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

// 僅管理員可存取後台頁面
requireAdmin();

$user = currentUser();
$pdo  = getPdo();

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msg     = $_GET['msg'] ?? '';

// ---------------- POST：處理所有動作（CSRF 驗證 + 預備陳述式）---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf_token'] ?? '')) {
        redirect('./ledgers.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
    }

    $action = $_POST['action'] ?? '';

    // ---------- 封存帳本（僅管理員；還原請至「已封存的帳本」頁面）----------
    if (($action ?? '') === 'archive_ledger') {
        $ledgerId = filter_var($_POST['ledger_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ledgerId === false || $ledgerId === null) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('無效的帳本。'));
        }
        $stmt = $pdo->prepare('UPDATE ledgers SET archived = 1 WHERE id = :id');
        $stmt->execute([':id' => $ledgerId]);
        logAudit('admin_ledger_archive', 'ledger_id=' . $ledgerId);
        redirect('./ledgers.php?success=1&msg=' . urlencode('帳本已封存，記錄完整保留；可至「已封存的帳本」頁面還原。'));
    }

    // ---------- 建立帳本 ----------
    if ($action === 'create_ledger') {
        $name    = trim($_POST['name'] ?? '');
        $ownerId = filter_var($_POST['owner_id'] ?? null, FILTER_VALIDATE_INT);

        if ($name === '' || $ownerId === false || $ownerId === null) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('帳本名稱與擁有者皆為必填。'));
        }
        if (strlen($name) > 100) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('帳本名稱過長（最多 100 個字元）。'));
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $ownerId]);
        if ($stmt->fetch() === false) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('指定的擁有者不存在。'));
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ledgers (name, owner_id) VALUES (:name, :owner_id)'
            );
            $stmt->execute([
                ':name'     => $name,
                ':owner_id' => $ownerId,
            ]);
            $ledgerId = (int) $pdo->lastInsertId();

            // 帳本擁有者自動取得該帳本的 owner 存取權限
            $stmt = $pdo->prepare(
                'INSERT INTO ledger_access (ledger_id, user_id, role) VALUES (:ledger_id, :user_id, :role)'
            );
            $stmt->execute([
                ':ledger_id' => $ledgerId,
                ':user_id'   => $ownerId,
                ':role'      => 'owner',
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            logError('admin_ledger_create failed (name=' . $name . '): ' . $e->getMessage());
            redirect('./ledgers.php?error=1&msg=' . urlencode('建立失敗：' . $e->getMessage()));
        }
        logAudit('admin_ledger_create', 'ledger_id=' . $ledgerId . ' name=' . $name . ' owner_id=' . $ownerId);
        redirect('./ledgers.php?success=1&msg=' . urlencode('帳本「' . $name . '」已建立。'));
    }

    // ---------- 新增/更新存取 ----------
    if ($action === 'add_access') {
        $ledgerId = filter_var($_POST['ledger_id'] ?? null, FILTER_VALIDATE_INT);
        $userId   = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role     = $_POST['role'] ?? '';

        if ($ledgerId === false || $ledgerId === null
            || $userId === false || $userId === null
            || !in_array($role, ['owner', 'member', 'viewer'], true)) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('請指定正確的帳本、使用者與身份。'));
        }

        $stmt = $pdo->prepare('SELECT id, owner_id FROM ledgers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $ledgerId]);
        $ledger = $stmt->fetch();
        if ($ledger === false) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('帳本不存在。'));
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        if ($stmt->fetch() === false) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('使用者不存在。'));
        }

        // 帳本擁有者的身份必須保持為 owner，不得變更
        if ($userId === (int) $ledger['owner_id'] && $role !== 'owner') {
            redirect('./ledgers.php?error=1&msg=' . urlencode('帳本擁有者的身份必須保持為 owner。'));
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM ledger_access WHERE ledger_id = :ledger_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
        $exists = $stmt->fetch() !== false;

        if ($exists) {
            $stmt = $pdo->prepare(
                'UPDATE ledger_access SET role = :role WHERE ledger_id = :ledger_id AND user_id = :user_id'
            );
            $stmt->execute([
                ':role'      => $role,
                ':ledger_id' => $ledgerId,
                ':user_id'   => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO ledger_access (ledger_id, user_id, role) VALUES (:ledger_id, :user_id, :role)'
            );
            $stmt->execute([
                ':ledger_id' => $ledgerId,
                ':user_id'   => $userId,
                ':role'      => $role,
            ]);
        }
        logAudit('admin_ledger_access_set', 'ledger_id=' . $ledgerId . ' user_id=' . $userId . ' role=' . $role . ($exists ? ' (更新)' : ' (新增)'));
        redirect('./ledgers.php?success=1&msg=' . urlencode('存取權限已更新。'));
    }

    // ---------- 移除存取 ----------
    if ($action === 'remove_access') {
        $ledgerId = filter_var($_POST['ledger_id'] ?? null, FILTER_VALIDATE_INT);
        $userId   = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);

        if ($ledgerId === false || $ledgerId === null || $userId === false || $userId === null) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('請指定正確的帳本與使用者。'));
        }

        $stmt = $pdo->prepare('SELECT owner_id FROM ledgers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $ledgerId]);
        $ledger = $stmt->fetch();
        if ($ledger === false) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('帳本不存在。'));
        }

        // 不得移除帳本擁有者本身的存取權限
        if ($userId === (int) $ledger['owner_id']) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('不可移除帳本擁有者本身的存取權限。'));
        }

        $stmt = $pdo->prepare(
            'DELETE FROM ledger_access WHERE ledger_id = :ledger_id AND user_id = :user_id'
        );
        $stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
        logAudit('admin_ledger_access_remove', 'ledger_id=' . $ledgerId . ' user_id=' . $userId);
        redirect('./ledgers.php?success=1&msg=' . urlencode('存取權限已移除。'));
    }

    redirect('./ledgers.php?error=1');
}

// ---------------- GET：讀取資料並顯示 ----------------
// 主頁只列「未封存且擁有者帳號仍存在」的帳本；
// 已封存的帳本 → archived_ledgers.php；擁有者已刪除的帳本 → orphaned_ledgers.php
$ledgers = $pdo->query('
    SELECT l.id, l.name, l.owner_id, l.archived, l.created_at, u.username AS owner_username
    FROM ledgers l
    INNER JOIN users u ON u.id = l.owner_id
    WHERE l.archived = 0
    ORDER BY l.id
')->fetchAll();

// 兩個子頁的帳本數量（頁首入口連結用；件數為 0 時不顯示）
$archivedCount = (int) $pdo->query('SELECT COUNT(*) FROM ledgers WHERE archived = 1')->fetchColumn();
$orphanedCount = (int) $pdo->query('
    SELECT COUNT(*)
    FROM ledgers l
    LEFT JOIN users u ON u.id = l.owner_id
    WHERE u.id IS NULL
')->fetchColumn();

$allUsers = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll();

// 每個帳本的目前存取清單（user_id, username, role）
$access = [];
foreach ($ledgers as $ledger) {
    $stmt = $pdo->prepare('
        SELECT la.user_id, u.username, la.role
        FROM ledger_access la
        INNER JOIN users u ON u.id = la.user_id
        WHERE la.ledger_id = :ledger_id
        ORDER BY u.username
    ');
    $stmt->execute([':ledger_id' => $ledger['id']]);
    $access[$ledger['id']] = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>後台 - 共同帳本管理 - 全家人記帳系統</title>
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
                <a class="nav-item" href="./users.php">使用者管理</a>
                <a class="nav-item" href="./category_management.php">分類管理</a>
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
                        <h1>共同帳本管理</h1>
                        <p class="sub">建立帳本、授權成員存取——只限管理員操作。</p>
                    </div>
                    <span class="actions" style="min-width: 0; flex-direction: row;">
                        <?php if ($archivedCount > 0): ?>
                            <a href="./archived_ledgers.php" class="btn secondary sm">已封存的帳本（<?php echo h((string) $archivedCount); ?>）→</a>
                        <?php endif; ?>
                        <?php if ($orphanedCount > 0): ?>
                            <a href="./orphaned_ledgers.php" class="btn secondary sm">擁有者已刪除的帳本（<?php echo h((string) $orphanedCount); ?>）→</a>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗，請檢查輸入後重試。'); ?></p>
                <?php endif; ?>

            <!-- 新增帳本 -->
            <h3>新增共同帳本</h3>
            <form method="POST" action="./ledgers.php" autocomplete="off" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                <input type="hidden" name="action" value="create_ledger">
                <div class="field"><label for="create_name" class="label">帳本名稱</label>
                <input type="text" name="name" id="create_name" required class="input" placeholder="例如 家庭共同帳本"></div>
                <div class="field"><label for="create_owner" class="label">擁有者</label>
                <select name="owner_id" id="create_owner" required class="select">
                    <?php foreach ($allUsers as $candidate): ?>
                        <option value="<?php echo h((string) $candidate['id']); ?>"><?php echo h($candidate['username']); ?></option>
                    <?php endforeach; ?>
                </select></div>
                <button type="submit" class="btn primary btn-block">建立帳本</button>
            </form>

            <!-- 未封存且擁有者仍存在的帳本與各帳本的存取管理 -->
            <h3>未封存的共同帳本</h3>
            <?php if ($ledgers): ?>
                <?php foreach ($ledgers as $ledger): ?>
                    <div style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--line);">
                        <h3 style="margin: 0 0 var(--space-2) 0; font-size: var(--text-lg);">
                            帳本：<?php echo h($ledger['name']); ?>
                            （ID：<?php echo h((string) $ledger['id']); ?>，擁有者：<?php echo h($ledger['owner_username']); ?>，建立於：<?php echo h($ledger['created_at']); ?>）
                        </h3>
                        <p class="meta" style="display: flex; gap: var(--space-4); align-items: center; flex-wrap: wrap;">
                            <a href="../ledger.php?ledger_id=<?php echo h((string) $ledger['id']); ?>">進入帳本詳情</a>
                            <form method="POST" action="./ledgers.php" style="margin: 0;" onsubmit="return confirm('封存此帳本？封存後移至「已封存的帳本」頁面，記錄完整保留。');">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                <input type="hidden" name="action" value="archive_ledger">
                                <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledger['id']); ?>">
                                <button type="submit" class="btn sm secondary">封存此帳本</button>
                            </form>
                        </p>

                        <!-- 該帳本目前的存取列表 -->
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>使用者</th>
                                        <th>身份</th>
                                        <th>備註</th>
                                        <th class="ctr">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $accessRows = $access[$ledger['id']] ?? []; ?>
                                    <?php if ($accessRows): ?>
                                        <?php foreach ($accessRows as $row): ?>
                                            <?php
                                                $isOwner   = (int) $row['user_id'] === (int) $ledger['owner_id'];
                                                $roleLabel = $row['role'] === 'owner' ? '擁有者'
                                                    : ($row['role'] === 'member' ? '成員' : '檢視者');
                                            ?>
                                            <tr>
                                                <td><?php echo h($row['username']); ?></td>
                                                <td><span class="role-tag <?php echo h($row['role']); ?>"><?php echo h($roleLabel); ?></span></td>
                                                <td><?php echo $isOwner ? '帳本擁有者（固定）' : '—'; ?></td>
                                                <td class="ctr">
                                                    <?php if (!$isOwner): ?>
                                                        <form method="POST" action="./ledgers.php" style="margin: 0;" onsubmit="return confirm('確定要移除此使用者對此帳本的存取權限嗎？');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                            <input type="hidden" name="action" value="remove_access">
                                                            <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledger['id']); ?>">
                                                            <input type="hidden" name="user_id" value="<?php echo h((string) $row['user_id']); ?>">
                                                            <button type="submit" class="btn btn-block sm danger">移除</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="meta">不可移除（擁有者）</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td class="ctr" colspan="4">尚無存取記錄。</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- 新增/更新該帳本的存取權限 -->
                        <?php
                            $editableUsers = [];
                            foreach ($allUsers as $candidate) {
                                if ((int) $candidate['id'] !== (int) $ledger['owner_id']) {
                                    $editableUsers[] = $candidate;
                                }
                            }
                        ?>
                        <?php if ($editableUsers): ?>
                            <form method="POST" action="./ledgers.php" style="margin: 0;" class="form">
                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                <input type="hidden" name="action" value="add_access">
                                <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledger['id']); ?>">
                                <label style="display: inline-block; margin-right: var(--space-2); font-size: var(--text-sm);">使用者</label>
                                <select name="user_id" style="display: inline-block; margin-right: var(--space-2); width: auto; padding: var(--space-2) var(--space-4); font-size: var(--text-sm);" class="input" required>
                                    <?php foreach ($editableUsers as $candidate): ?>
                                        <option value="<?php echo h((string) $candidate['id']); ?>"><?php echo h($candidate['username']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label style="display: inline-block; margin-right: var(--space-2); font-size: var(--text-sm);">身份</label>
                                <select name="role" style="display: inline-block; margin-right: var(--space-2); width: auto; padding: var(--space-2) var(--space-4); font-size: var(--text-sm);" class="input" required>
                                    <option value="owner">擁有者</option>
                                    <option value="member">成員</option>
                                    <option value="viewer">檢視者</option>
                                </select>
                                <button type="submit" class="btn primary sm">新增/更新存取</button>
                            </form>
                        <?php else: ?>
                            <p class="meta">尚無其他使用者可授權。</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>目前沒有未封存的共同帳本。</p>
            <?php endif; ?>

            <p class="meta" style="border-top: 1px solid var(--line); padding-top: var(--space-4); margin-top: var(--space-6);">
                規則：帳本擁有者的存取權限固定為 owner，不可變更亦不可移除；其他使用者可設為 owner / member / viewer。封存的帳本移至「已封存的帳本」頁面，不能記帳或作廢記錄，記錄完整保留，可隨時還原；擁有者帳號已刪除的帳本移至「擁有者已刪除的帳本」頁面，可重新指定擁有者。
            </p>
            </main>
        </div>
    </div>
</body>
</html>
