<?php
// 全家人記帳系統 — 後台：操作紀錄（審計日誌，僅管理員可存取）
// 顯示 audit_log 表內容：誰、何時、做了什麼、從哪個 IP。
// 本頁唯讀；不提供刪除入口（審計紀錄不應由網頁介面抹除）。
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

// 僅管理員可存取後台頁面
requireAdmin();

$user = currentUser();
$pdo  = getPdo();

// 動作代碼 → 顯示用中文標籤（未收錄的代碼仍以原字串顯示）
$actionLabels = [
    'login'                       => '登入',
    'login_failed'                => '登入失敗',
    'login_locked'                => '登入遭鎖定',
    'logout'                      => '登出',
    'entry_create'                => '新增記錄',
    'entry_delete'                => '作廢記錄',
    'password_change'             => '變更密碼',
    'admin_user_create'           => '建立使用者',
    'admin_user_reset_password'   => '重設使用者密碼',
    'admin_user_change_role'      => '變更使用者角色',
    'admin_user_delete'           => '刪除使用者',
    'admin_ledger_create'         => '建立帳本',
    'admin_ledger_archive'        => '封存帳本',
    'ledger_restore'              => '還原帳本',
    'admin_ledger_access_set'     => '設定帳本存取',
    'admin_ledger_access_remove'  => '移除帳本存取',
    'admin_ledger_reassign_owner' => '重新指定帳本擁有者',
];

// ---------------- 分頁與搜尋 ----------------
$perPage = 50;
$pageRaw = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page    = ($pageRaw === null || $pageRaw === false || $pageRaw < 1) ? 1 : $pageRaw;
$q       = trim((string) ($_GET['q'] ?? ''));

$where  = '';
$params = [];
if ($q !== '') {
    // 逃脫 LIKE 萬用字元，避免使用者輸入 % / _ 影響查詢語意
    $esc = strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    $where = " WHERE username LIKE :q1 ESCAPE '\\'
                 OR action   LIKE :q2 ESCAPE '\\'
                 OR detail   LIKE :q3 ESCAPE '\\'";
    $like = '%' . $esc . '%';
    $params = [':q1' => $like, ':q2' => $like, ':q3' => $like];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log' . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT id, user_id, username, action, detail, ip, created_at
     FROM audit_log' . $where . '
     ORDER BY id DESC
     LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$qs = $q !== '' ? '&q=' . urlencode($q) : '';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>後台 - 操作紀錄 - 全家人記帳系統</title>
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
                <a class="nav-item" href="./category_management.php">分類管理</a>
                <a class="nav-item" href="./ledgers.php">帳本管理</a>
                <a class="nav-item active" href="./audit_log.php">操作紀錄</a>
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
                        <h1>操作紀錄</h1>
                        <p class="sub">登入、記帳與後台操作的審計紀錄——僅管理員可見，唯讀。</p>
                    </div>
                </div>

                <form method="GET" action="./audit_log.php" class="form" style="display: flex; gap: var(--space-2); align-items: center; flex-wrap: wrap; margin-bottom: var(--space-4);">
                    <input type="text" name="q" class="input" value="<?php echo h($q); ?>" placeholder="搜尋帳號 / 動作 / 細節…" style="max-width: 320px;" autocomplete="off">
                    <button type="submit" class="btn sm secondary">搜尋</button>
                    <?php if ($q !== ''): ?><a href="./audit_log.php" class="btn sm secondary">清除</a><?php endif; ?>
                    <span class="meta" style="margin-left: auto;">共 <?php echo h((string) $total); ?> 筆</span>
                </form>

                <?php if ($rows): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>時間</th>
                                    <th>使用者</th>
                                    <th>IP</th>
                                    <th>動作</th>
                                    <th>細節</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php $label = $actionLabels[$r['action']] ?? ''; ?>
                                    <tr>
                                        <td><?php echo h($r['created_at']); ?></td>
                                        <td><?php echo h((string) ($r['username'] !== '' ? $r['username'] : '—')); ?><?php if ($r['user_id'] !== null): ?><span class="meta"> #<?php echo h((string) $r['user_id']); ?></span><?php endif; ?></td>
                                        <td><?php echo h((string) ($r['ip'] !== '' ? $r['ip'] : '—')); ?></td>
                                        <td>
                                            <code><?php echo h($r['action']); ?></code>
                                            <?php if ($label !== ''): ?><span class="meta"><?php echo h($label); ?></span><?php endif; ?>
                                        </td>
                                        <td><?php echo h($r['detail']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <p class="meta" style="display: flex; gap: var(--space-3); align-items: center; margin-top: var(--space-4);">
                            <?php if ($page > 1): ?><a class="btn sm secondary" href="./audit_log.php?page=<?php echo h((string) ($page - 1)) . h($qs); ?>">← 上一頁</a><?php endif; ?>
                            第 <?php echo h((string) $page); ?> / <?php echo h((string) $totalPages); ?> 頁
                            <?php if ($page < $totalPages): ?><a class="btn sm secondary" href="./audit_log.php?page=<?php echo h((string) ($page + 1)) . h($qs); ?>">下一頁 →</a><?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p><?php echo $q !== '' ? '沒有符合的紀錄。' : '目前沒有任何操作紀錄。'; ?></p>
                <?php endif; ?>

                <p class="meta" style="border-top: 1px solid var(--line); padding-top: var(--space-4); margin-top: var(--space-5);">
                    說明：紀錄只會新增、不會自動刪除；本頁不提供刪除入口。金額以整數分入帳，此處顯示為元（格式化顯示，不影響帳務精確度）。如需清理舊紀錄，請由主機以 SQL 手動處理（例如 <code>DELETE FROM audit_log WHERE created_at &lt; '2026-01-01'</code>）。
                </p>
            </main>
        </div>
    </div>
</body>
</html>
