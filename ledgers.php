<?php
// 全家人記帳系統 — 帳本列表：列出目前使用者可存取的未封存帳本；已封存的帳本見 archived_ledgers.php
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

// 自建帳本：任何登入者皆可建立屬於自己的帳本，建立者自動取得該帳本 owner 權限
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'create_ledger_self') {
        if (!checkCsrf($_POST['csrf_token'] ?? '')) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
        }
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            redirect('./ledgers.php?error=1&msg=' . urlencode('請輸入帳本名稱。'));
        }
        if (strlen($name) > 100) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('帳本名稱最多 100 個字元。'));
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO ledgers (name, owner_id) VALUES (:name, :owner_id)');
            $stmt->execute([':name' => $name, ':owner_id' => $userId]);
            $newLedgerId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO ledger_access (ledger_id, user_id, role) VALUES (:ledger_id, :user_id, :role)');
            $stmt->execute([':ledger_id' => $newLedgerId, ':user_id' => $userId, ':role' => 'owner']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            redirect('./ledgers.php?error=1&msg=' . urlencode('建立失敗，請稍後再試。'));
        }
        redirect('./ledger.php?ledger_id=' . $newLedgerId . '&success=1');
    }

    // 封存帳本：owner 可封存自己的帳本；管理員可封存任何帳本（還原請至「已封存的帳本」頁面）
    if (($_POST['action'] ?? '') === 'archive_ledger') {
        if (!checkCsrf($_POST['csrf_token'] ?? '')) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
        }
        $ledgerId = filter_var($_POST['ledger_id'] ?? null, FILTER_VALIDATE_INT);
        if ($ledgerId === false || $ledgerId === null) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('無效的帳本。'));
        }
        $stmt = $pdo->prepare('
            SELECT l.owner_id, (SELECT role FROM ledger_access WHERE ledger_id = l.id AND user_id = :user_id) AS my_role
            FROM ledgers l WHERE l.id = :ledger_id LIMIT 1
        ');
        $stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('帳本不存在。'));
        }
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $canArchive = $isAdmin || ($row['my_role'] === 'owner');
        if (!$canArchive) {
            redirect('./ledgers.php?error=1&msg=' . urlencode('只有帳本擁有者或管理員可以封存帳本。'));
        }

        $archived = 1;
        $stmt = $pdo->prepare('UPDATE ledgers SET archived = :archived WHERE id = :id');
        $stmt->execute([':archived' => $archived, ':id' => $ledgerId]);
        redirect('./ledgers.php?success=1&msg=' . urlencode('帳本已封存，記錄完整保留；可至「已封存的帳本」頁面還原。'));
    }
    redirect('./ledgers.php?error=1');
}

// 列出目前使用者可存取的未封存帳本：擁有者，或透過 ledger_access 授權（owner / member / viewer）
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
    WHERE l.archived = 0
    ORDER BY l.name
');
$stmt->execute([':user_id' => $userId]);
$ledgers = $stmt->fetchAll();

// 目前使用者可存取的已封存帳本數量（用於「已封存的帳本」入口連結；owner / member / viewer 皆計入）
$stmt = $pdo->prepare('
    SELECT COUNT(*) AS archived_count
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id AND la.user_id = :user_id
    WHERE l.archived = 1
');
$stmt->execute([':user_id' => $userId]);
$archivedCount = (int) ($stmt->fetch()['archived_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>帳本列表 - 全家人記帳系統</title>
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
                <a class="nav-item" href="./calendar.php">行事曆檢視</a>
                <a class="nav-item" href="./search.php">全站搜尋</a>
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
                        <h1>帳本列表</h1>
                        <p class="sub">您可存取的共同帳本，點進去看本月收支與記錄。</p>
                    </div>
                    <?php if ($archivedCount > 0): ?>
                        <a href="./archived_ledgers.php" class="btn secondary sm">已封存的帳本（<?php echo h((string) $archivedCount); ?>）→</a>
                    <?php endif; ?>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '無法存取指定帳本，或您沒有該帳本的權限。'); ?></p>
                <?php endif; ?>

                <form method="POST" action="./ledgers.php" class="filters" aria-label="新增我的帳本">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                    <input type="hidden" name="action" value="create_ledger_self">
                    <label class="filter" style="flex: 1 1 240px;">
                        <span class="filter-label">新增我的帳本</span>
                        <input type="text" name="name" class="input filter-input" required maxlength="100" placeholder="例如 旅遊基金、孝親金" style="width: 100%; min-width: 0;">
                    </label>
                    <button type="submit" class="btn primary sm">建立帳本</button>
                </form>

                <?php if ($ledgers): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>帳本名稱</th>
                                    <th>您的身份</th>
                                    <th class="num hide-m">記錄數（未刪除）</th>
                                    <th class="ctr">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ledgers as $ledger): ?>
                                    <tr>
                                        <td><strong><?php echo h($ledger['name']); ?></strong></td>
                                        <td><span class="role-tag <?php echo h($ledger['access_role']); ?>"><?php echo h($ledger['access_role']); ?></span></td>
                                        <td class="num hide-m"><?php echo h((string) $ledger['entry_count']); ?></td>
                                        <td class="ctr">
                                            <span class="actions">
                                                <a href="./ledger.php?ledger_id=<?php echo h((string) $ledger['id']); ?>" class="btn sm">進入帳本</a>
                                                <a href="./yearly_report.php?ledger_id=<?php echo h((string) $ledger['id']); ?>" class="btn sm secondary">年度報表</a>
                                                <a href="./quarterly_report.php?ledger_id=<?php echo h((string) $ledger['id']); ?>" class="btn sm secondary">季度報表</a>
                                                <a href="./comparison_report.php?ledger_id=<?php echo h((string) $ledger['id']); ?>" class="btn sm secondary">收支比較報表</a>
                                                <?php if ($ledger['access_role'] !== 'viewer'): ?>
                                                    <a href="./add_entry.php?ledger_id=<?php echo h((string) $ledger['id']); ?>" class="btn sm secondary">新增記錄</a>
                                                <?php endif; ?>
                                                <?php if ($user['role'] === 'admin' || $ledger['access_role'] === 'owner'): ?>
                                                    <form method="POST" action="./ledgers.php" onsubmit="return confirm('封存此帳本？封存後從列表隱藏，記錄完整保留，可隨時還原。');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                        <input type="hidden" name="action" value="archive_ledger">
                                                        <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledger['id']); ?>">
                                                        <button type="submit" class="btn sm secondary">封存</button>
                                                    </form>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty">您目前沒有可存取的帳本。在上方建立一本自己的，或洽管理員授權。</p>
                <?php endif; ?>

            </main>
        </div>
    </div>

        <script src="./assets/app.js"></script>
</body>
</html>
