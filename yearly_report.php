<?php
// 全家人記帳系統 — 年度報表（多月份收支彙總）
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

// 讀取 ledger_id，驗證為整數，並確認使用者有存取權
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證帳本存取權限（owner / member / viewer 均可檢視）
$stmt = $pdo->prepare('
    SELECT l.id, l.name, l.archived, la.role
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.id = :ledger_id
      AND la.user_id = :user_id
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$ledger = $stmt->fetch();
if ($ledger === false) {
    redirect('./ledgers.php?error=1');
}
if ((int) ($ledger['archived'] ?? 0) === 1) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法檢視報表。'));
}
$accessRole = $ledger['role'];

// 讀取年份，驗證為 4 位整數，格式不符時回退至當年
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
$yearRaw = $_GET['year'] ?? '';
if (!is_string($yearRaw) || !preg_match('/^\d{4}$/', $yearRaw)) {
    $year = $today->format('Y');
} else {
    $year = $yearRaw;
}
$startDate = $year . '-01-01';
$endDate   = $year . '-12-31';

// 查詢各月份收支彙總（使用 strftime 分組，CASE 聚合計算各類總額）
$stmt = $pdo->prepare('
    SELECT
        strftime(\'%Y-%m\', e.entry_date) AS ym,
        SUM(CASE WHEN e.type = \'income\' THEN e.amount_cents ELSE 0 END) AS income_cents,
        SUM(CASE WHEN e.type = \'expense\' THEN e.amount_cents ELSE 0 END) AS expense_cents,
        SUM(CASE WHEN e.type = \'transfer\' THEN e.amount_cents ELSE 0 END) AS transfer_cents
    FROM entries e
    INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
    WHERE e.ledger_id = :ledger_id
      AND e.deleted = 0
      AND e.entry_date BETWEEN :startDate AND :endDate
    GROUP BY ym
    ORDER BY ym
');
$monthlyStats = [];
$totalIncomeCents  = 0;
$totalExpenseCents = 0;
$totalTransferCents = 0;
$totalBalanceCents = 0;

try {
    $stmt->execute([
        ':user_id'      => $userId,
        ':ledger_id'    => $ledgerId,
        ':startDate'    => $startDate,
        ':endDate'      => $endDate,
    ]);
} catch (Throwable $e) {
    logError('yearly_report.php query failed: ' . $e->getMessage());
    $error = true;
    $msg = '報表查詢失敗，請稍後再試。';
}

while ($row = $stmt->fetch()) {
    $income  = (int) $row['income_cents'];
    $expense = (int) $row['expense_cents'];
    $transfer= (int) $row['transfer_cents'];
    $monthlyStats[] = [
        'ym'        => (string) $row['ym'],
        'income'    => $income,
        'expense'   => $expense,
        'transfer'  => $transfer,
        'balance'   => $income - $expense,
    ];
    $totalIncomeCents  += $income;
    $totalExpenseCents += $expense;
    $totalTransferCents += $transfer;
}
$totalBalanceCents = $totalIncomeCents - $totalExpenseCents;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>年度報表 - 全家人記帳系統</title>
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
                        <h1>年度報表</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong>　·　年度：<strong><?php echo h($year); ?> 年</strong>　·　<a href="./ledgers.php">← 返回帳本列表</a></p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗：請檢查輸入後重試。'); ?></p>
                <?php endif; ?>

                <!-- 年份選擇表單 -->
                <form method="GET" action="./yearly_report.php" class="filters" aria-label="選擇年份與帳本">
                    <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledgerId); ?>">
                    <label class="filter">
                        <span class="filter-label">年份</span>
                        <input type="text" name="year" class="input mono filter-input" value="<?php echo h($year); ?>" maxlength="4" placeholder="例如 2026">
                    </label>
                    <button type="submit" class="btn primary sm">查詢</button>
                </form>

                <?php if (empty($monthlyStats)): ?>
                    <p class="report-empty">該年度無記錄。</p>
                <?php else: ?>
                    <div class="report-table">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>月份</th>
                                    <th class="num">收入</th>
                                    <th class="num">支出</th>
                                    <th class="num">轉帳</th>
                                    <th class="num">結餘</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthlyStats as $stat): ?>
                                <tr>
                                    <td><?php echo h($stat['ym']); ?></td>
                                    <td class="num amt-in">+<?php echo h(formatCents($stat['income'])); ?></td>
                                    <td class="num amt-out">-<?php echo h(formatCents($stat['expense'])); ?></td>
                                    <td class="num amt-neutral"><?php echo h(formatCents($stat['transfer'])); ?></td>
                                    <td class="num"><?php echo h($stat['balance'] >= 0 ? '+' : '') . h(formatCents($stat['balance'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="report-total">
                                    <td><strong>年度總計</strong></td>
                                    <td class="num amt-in">+<?php echo h(formatCents($totalIncomeCents)); ?></td>
                                    <td class="num amt-out">-<?php echo h(formatCents($totalExpenseCents)); ?></td>
                                    <td class="num amt-neutral"><?php echo h(formatCents($totalTransferCents)); ?></td>
                                    <td class="num"><?php echo h($totalBalanceCents >= 0 ? '+' : '') . h(formatCents($totalBalanceCents)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

        <script src="./assets/app.js"></script>
</body>
</html>
