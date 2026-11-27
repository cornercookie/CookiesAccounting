<?php
// 全家人記帳系統 — 季度報表（多月份收支彙總）
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

// 取得使用者可存取的帳本列表（供下拉選單）
$stmt = $pdo->prepare('
    SELECT l.id, l.name, la.role
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.archived = 0 AND la.user_id = :user_id
    ORDER BY l.name
');
$stmt->execute([':user_id' => $userId]);
$accessibleLedgers = $stmt->fetchAll();

// 讀取 ledger_id，驗證為整數，並確認使用者有存取權
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);

// 若未提供 ledger_id 但使用者只有一個可存取帳本，自動選擇該帳本
if ($ledgerId === false || $ledgerId === null) {
    if (count($accessibleLedgers) === 1) {
        $ledgerId = (int) $accessibleLedgers[0]['id'];
    } else {
        redirect('./ledgers.php?error=1');
    }
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

// 讀取季度，驗證為 Q1-Q4 或 1-4，格式不符時回退至當季
$quarterRaw = $_GET['quarter'] ?? '';
$quarterMap = ['Q1' => 1, 'Q2' => 2, 'Q3' => 3, 'Q4' => 4, '1' => 1, '2' => 2, '3' => 3, '4' => 4];
$quarterNum = $quarterMap[$quarterRaw] ?? null;
if ($quarterNum === null) {
    $quarterNum = (int) $today->format('n');
    $quarterNum = (int) ceil($quarterNum / 3);
    if ($quarterNum < 1) $quarterNum = 1;
    if ($quarterNum > 4) $quarterNum = 4;
}

// 根據季度計算起迄日期（優化：只建立一次 DateTimeImmutable）
$startMonth = ($quarterNum - 1) * 3 + 1;
$endMonth   = $quarterNum * 3;
$startDate = $year . '-' . str_pad((string) $startMonth, 2, '0', STR_PAD_LEFT) . '-01';
$endDateObj = new DateTimeImmutable($year . '-' . str_pad((string) $endMonth, 2, '0', STR_PAD_LEFT) . '-01');
$endDate   = $endDateObj->format('Y-m-t');

// 年份下拉選單選項（當年 ±2 年）
$currentYear = (int) $today->format('Y');
$yearOptions = range($currentYear - 2, $currentYear + 2);

// 使用共用函數查詢彙總資料
[$entries, $monthlyStatsRaw, $queryError] = fetchEntriesWithStats($pdo, $userId, $ledgerId, $startDate, $endDate);

$monthlyStats = [];
$categoryStats = [];
$totalIncomeCents  = 0;
$totalExpenseCents = 0;
$totalTransferCents = 0;
$totalBalanceCents = 0;

if ($queryError !== '') {
    $error = true;
    $msg = $queryError;
} else {
    foreach ($monthlyStatsRaw as $row) {
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

    // 支出類別佔比（[#10]）：同區間非刪除支出，依類別群組，佔比採整數除法
    $categoryStmt = $pdo->prepare('
        SELECT e.category, SUM(e.amount_cents) AS expense_cents
        FROM entries e
        INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
        WHERE e.ledger_id = :ledger_id
          AND e.deleted = 0
          AND e.type = :type
          AND e.entry_date BETWEEN :startDate AND :endDate
        GROUP BY e.category
        ORDER BY expense_cents DESC, e.category ASC
    ');
    $categoryStmt->execute([
        ':user_id'   => $userId,
        ':ledger_id' => $ledgerId,
        ':type'      => 'expense',
        ':startDate' => $startDate,
        ':endDate'   => $endDate,
    ]);
    $categoryStats = [];
    foreach ($categoryStmt->fetchAll() as $row) {
        $cents = (int) $row['expense_cents'];
        $categoryStats[] = [
            'category' => $row['category'],
            'expense'  => $cents,
            'percent'  => $totalExpenseCents > 0 ? intdiv($cents * 100, $totalExpenseCents) : 0,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>季度報表 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
    <style>
        /* 季度報表列印樣式 */
        @media print {
            .side, .nav, .ctr-actions, .quick, .font-size-toggle, .filters {
                display: none !important;
            }
            .main { margin: 0; padding: 0; }
            .sheet { width: 100%; max-width: 100%; }
            body { background: #fff; color: #000; }
            .plate { border: 1px solid #000; }
            .v { color: #000; }
            .report-table, .report-table tfoot tr { page-break-inside: avoid; }
            .report-table tfoot tr td {
                background: var(--paper-2) !important;
                border-top: 2px solid #000 !important;
                font-weight: 700;
            }
        }
    </style>
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
                <a class="nav-item" href="./add_entry.php?ledger_id=<?php echo h((string) $ledgerId); ?>">新增記錄</a>
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
                        <h1>季度報表</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong>　·　年度：<strong><?php echo h($year); ?> 年</strong>　·　季度：<strong>第 <?php echo h((string) $quarterNum); ?> 季</strong>　·　<a href="./ledgers.php">← 返回帳本列表</a></p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗：請檢查輸入後重試。'); ?></p>
                <?php endif; ?>

                <!-- 年份、季度與帳本選擇表單 -->
                <form method="GET" action="./quarterly_report.php" class="filters" aria-label="選擇年份、季度與帳本">
                    <label class="filter">
                        <span class="filter-label">帳本</span>
                        <select name="ledger_id" class="select filter-input" required>
                            <?php foreach ($accessibleLedgers as $l): ?>
                                <option value="<?php echo h((string) $l['id']); ?>"<?php echo $l['id'] == $ledgerId ? ' selected' : ''; ?>>
                                    <?php echo h($l['name']); ?> (<?php echo h($l['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="filter">
                        <span class="filter-label">年份</span>
                        <select name="year" class="select mono filter-input">
                            <?php foreach ($yearOptions as $y): ?>
                                <option value="<?php echo h((string) $y); ?>"<?php echo $y == $year ? ' selected' : ''; ?>>
                                    <?php echo h((string) $y); ?> 年
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="filter">
                        <span class="filter-label">季度</span>
                        <select name="quarter" class="select filter-input">
                            <option value="1"<?php echo $quarterNum === 1 ? ' selected' : ''; ?>>第一季（1-3月）</option>
                            <option value="2"<?php echo $quarterNum === 2 ? ' selected' : ''; ?>>第二季（4-6月）</option>
                            <option value="3"<?php echo $quarterNum === 3 ? ' selected' : ''; ?>>第三季（7-9月）</option>
                            <option value="4"<?php echo $quarterNum === 4 ? ' selected' : ''; ?>>第四季（10-12月）</option>
                        </select>
                    </label>
                    <button type="submit" class="btn primary sm">查詢</button>
                </form>

                <?php if (empty($monthlyStats)): ?>
                    <p class="report-empty">該季度無記錄。</p>
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
                            </tbody>
                            <tfoot>
                                <tr class="report-total-row">
                                    <td><strong>季度總計</strong></td>
                                    <td class="num amt-in">+<?php echo h(formatCents($totalIncomeCents)); ?></td>
                                    <td class="num amt-out">-<?php echo h(formatCents($totalExpenseCents)); ?></td>
                                    <td class="num amt-neutral"><?php echo h(formatCents($totalTransferCents)); ?></td>
                                    <td class="num"><?php echo h($totalBalanceCents >= 0 ? '+' : '') . h(formatCents($totalBalanceCents)); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- 支出類別佔比（[#10]） -->
                    <div class="report-table">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>類別</th>
                                    <th class="num">支出</th>
                                    <th class="num">佔比</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categoryStats)): ?>
                                    <tr>
                                        <td colspan="3">尚無支出紀錄。</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categoryStats as $cat): ?>
                                    <tr>
                                        <td><?php echo h($cat['category']); ?></td>
                                        <td class="num amt-out"><?php echo h(formatCents($cat['expense'])); ?></td>
                                        <td class="num"><?php echo h((string) $cat['percent']); ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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