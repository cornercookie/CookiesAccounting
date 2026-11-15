<?php
// 全家人記帳系統 — 登入後儀表板（Task 1 骨架頁）
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

$user = currentUser();
$pdo = getPdo();

$userCount   = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$ledgerCount = (int) $pdo->query('SELECT COUNT(*) FROM ledgers')->fetchColumn();
$entryCount  = (int) $pdo->query('SELECT COUNT(*) FROM entries WHERE deleted = 0')->fetchColumn();

// 各帳本加總：不把不同帳本加在一起——每本帳各自呈現本月收支
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
$monthStart = $today->format('Y-m-01');
$monthEnd   = $today->format('Y-m-t');

$stmt = $pdo->prepare('
    SELECT
        l.id,
        l.name,
        la.role AS access_role,
        (SELECT COUNT(*) FROM entries e WHERE e.ledger_id = l.id AND e.deleted = 0) AS entry_count,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM entries e
            WHERE e.ledger_id = l.id AND e.deleted = 0 AND e.type = \'income\'
              AND e.entry_date BETWEEN :msA AND :meA) AS month_income,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM entries e
            WHERE e.ledger_id = l.id AND e.deleted = 0 AND e.type = \'expense\'
              AND e.entry_date BETWEEN :msB AND :meB) AS month_expense,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM entries e
            WHERE e.ledger_id = l.id AND e.deleted = 0 AND e.type = \'income\') AS total_income,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM entries e
            WHERE e.ledger_id = l.id AND e.deleted = 0 AND e.type = \'expense\') AS total_expense
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
        AND la.user_id = :user_id
    WHERE l.archived = 0
    ORDER BY l.name
');
$stmt->execute([':user_id' => (int) $user['id'], ':msA' => $monthStart, ':meA' => $monthEnd, ':msB' => $monthStart, ':meB' => $monthEnd]);
$ledgerSummaries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>儀表板 - 全家人記帳系統</title>
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
                <a class="nav-item active" href="./dashboard.php">儀表板</a>
                <a class="nav-item" href="./ledgers.php">帳本列表</a>
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
                        <h1>儀表板</h1>
                        <p class="sub">歡迎回來，<?php echo h($user['username']); ?>；這是本家的傳票總帳。</p>
                    </div>
                    <a class="btn primary" href="./ledgers.php">前往帳本</a>
                </div>

                <p class="summary-h">各帳本 · 本月收支</p>
                <?php if ($ledgerSummaries): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th rowspan="2">帳本</th>
                                    <th rowspan="2">您的身份</th>
                                    <th colspan="3" class="group-h">本月</th>
                                    <th colspan="3" class="group-h group-start">總表</th>
                                    <th class="num hide-m" rowspan="2">記錄數</th>
                                    <th class="ctr" rowspan="2">進入</th>
                                </tr>
                                <tr>
                                    <th class="num">收入</th>
                                    <th class="num">支出</th>
                                    <th class="num">結餘</th>
                                    <th class="num group-start">總收入</th>
                                    <th class="num">總支出</th>
                                    <th class="num">總損益</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ledgerSummaries as $ls): ?>
                                    <?php
                                        $lsBalance = (int) $ls['month_income'] - (int) $ls['month_expense'];
                                        $lsTotalBalance = (int) $ls['total_income'] - (int) $ls['total_expense'];
                                    ?>
                                    <tr>
                                        <td><a class="ledger-link" href="./ledger.php?ledger_id=<?php echo h((string) $ls['id']); ?>"><strong><?php echo h($ls['name']); ?></strong></a></td>
                                        <td><span class="role-tag <?php echo h($ls['access_role']); ?>"><?php echo h($ls['access_role']); ?></span></td>
                                        <td class="num amt-in">+<?php echo h(formatCents((int) $ls['month_income'])); ?></td>
                                        <td class="num amt-out">-<?php echo h(formatCents((int) $ls['month_expense'])); ?></td>
                                        <td class="num"><?php echo h($lsBalance >= 0 ? '+' : '') . h(formatCents($lsBalance)); ?></td>
                                        <td class="num amt-in">+<?php echo h(formatCents((int) $ls['total_income'])); ?></td>
                                        <td class="num amt-out">-<?php echo h(formatCents((int) $ls['total_expense'])); ?></td>
                                        <td class="num"><?php echo h($lsTotalBalance >= 0 ? '+' : '') . h(formatCents($lsTotalBalance)); ?></td>
                                        <td class="num hide-m"><?php echo h((string) $ls['entry_count']); ?></td>
                                        <td class="ctr"><a href="./ledger.php?ledger_id=<?php echo h((string) $ls['id']); ?>" class="btn sm">進入</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty">目前沒有可存取的帳本。<a href="./ledgers.php">前往帳本列表</a>建立一本。</p>
                <?php endif; ?>

                <hr class="tear">

                <p class="summary-h">記帳規模</p>
                <div class="plates">
                    <div class="plate"><small>使用者</small><div class="v"><?php echo h((string) $userCount); ?><small> 人</small></div></div>
                    <div class="plate"><small>共同帳本</small><div class="v"><?php echo h((string) $ledgerCount); ?><small> 本</small></div></div>
                    <div class="plate"><small>記帳記錄（未刪除）</small><div class="v"><?php echo h((string) $entryCount); ?><small> 筆</small></div></div>
                </div>

                <hr class="tear">

                <h3>最近的安排</h3>
                <div class="quick-grid">
                    <a class="quick" href="./ledgers.php">
                        <b>帳本列表 →</b>
                        <span>進入共同帳本，查看收支彙總、查帳篩選與分頁記錄。</span>
                    </a>
                    <?php if ($user['role'] === 'admin'): ?>
                        <a class="quick" href="./admin/users.php">
                            <b>使用者管理 →</b>
                            <span>建立成員、重設密碼、變更角色。</span>
                        </a>
                        <a class="quick" href="./admin/ledgers.php">
                            <b>帳本管理 →</b>
                            <span>建立共同帳本、授權成員存取。</span>
                        </a>
                    <?php else: ?>
                        <a class="quick" href="./ledgers.php">
                            <b>立傳票 →</b>
                            <span>選一本帳本，10 秒內記一筆收支。</span>
                        </a>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
