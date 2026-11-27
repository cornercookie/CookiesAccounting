<?php
// 全家人記帳系統 — 月結單 HTML 列印預覽（保留舊檔名相容性）。
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 讀取 ledger_id，驗證為整數
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（IDOR 防護）
$ledger = getLedgerWithAccess($pdo, $ledgerId, $userId);
if ($ledger === false) {
    redirect('./ledgers.php?error=1');
}
if ((int) ($ledger['archived'] ?? 0) === 1) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法列印報表。'));
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
[$startDate, $endDate, $dateError] = parseDateRange($_GET['start_date'] ?? '', $_GET['end_date'] ?? '', $_GET['ym'] ?? '');

if ($dateError !== '') {
    // 日期錯誤：顯示友善錯誤頁面，不 redirect
    $content = '<p class="alert error" role="alert">' . h($dateError) . '</p>';
    renderPdfExportPage($user, '列印月結單', $ledger['name'], '', $content);
    exit;
}

// 查詢明細與彙總
[$entries, $monthlyStats, $queryError] = fetchEntriesWithStats($pdo, $userId, $ledgerId, $startDate, $endDate);

if ($queryError !== '') {
    // 查詢失敗：顯示友善錯誤頁面
    $content = '<p class="alert error" role="alert">' . h($queryError) . '</p>';
    renderPdfExportPage($user, '列印月結單', $ledger['name'], '', $content);
    exit;
}

// 計算總計（從 monthlyStats 計算）
$totalIncomeCents  = 0;
$totalExpenseCents = 0;
$totalTransferCents = 0;
foreach ($monthlyStats as $row) {
    $income  = (int) $row['income_cents'];
    $expense = (int) $row['expense_cents'];
    $transfer= (int) $row['transfer_cents'];
    $totalIncomeCents  += $income;
    $totalExpenseCents += $expense;
    $totalTransferCents += $transfer;
}
$totalBalanceCents = $totalIncomeCents - $totalExpenseCents;
logAudit('statement_print_view', json_encode(['ledger_id' => $ledgerId, 'start_date' => $startDate, 'end_date' => $endDate]));
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>列印月結單 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261127-print">
</head>
<body class="statement-print-page">
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
                        <h1>列印月結單</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong>　·　期間：<strong><?php echo h($startDate); ?> ～ <?php echo h($endDate); ?></strong></p>
                    </div>
                </div>

                <?php if (empty($entries)): ?>
                    <p class="pdf-empty">該期間無記錄。</p>
                <?php else: ?>
                    <div class="pdf-report">
                        <h2>收支彙總</h2>
                        <div class="pdf-total">
                            <table class="pdf-table">
                                <thead>
                                    <tr>
                                        <th>項目</th>
                                        <th class="num">金額（元）</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>收入</td>
                                        <td class="num"><?php echo h(formatCents($totalIncomeCents)); ?></td>
                                    </tr>
                                    <tr>
                                        <td>支出</td>
                                        <td class="num"><?php echo h(formatCents($totalExpenseCents)); ?></td>
                                    </tr>
                                    <tr>
                                        <td>轉帳</td>
                                        <td class="num"><?php echo h(formatCents($totalTransferCents)); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>結餘</strong></td>
                                        <td class="num"><strong><?php echo h(formatCents($totalBalanceCents)); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <h2>詳細記錄</h2>
                        <table class="pdf-table">
                            <thead>
                                <tr>
                                    <th>日期</th>
                                    <th>類型</th>
                                    <th>原幣別</th>
                                    <th class="num">原幣金額</th>
                                    <th class="num">匯率</th>
                                    <th class="num">台幣金額（元）</th>
                                    <th>分類</th>
                                    <th>記錄者</th>
                                    <th>備註</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $row):
                                    $currency = (string) ($row['currency'] ?? 'TWD');
                                    $amountCents = (int) $row['amount_cents'];
                                    $originalCents = displayOriginalCents((int) ($row['original_amount_cents'] ?? 0), $amountCents, $currency);
                                ?>
                                <tr>
                                    <td><?php echo h($row['entry_date']); ?></td>
                                    <td class="num"><?php echo h($row['type'] === 'income' ? '收入' : ($row['type'] === 'expense' ? '支出' : '轉帳')); ?></td>
                                    <td><?php echo h($currency); ?></td>
                                    <td class="num"><?php echo h(formatForeignAmount($originalCents, $currency)); ?></td>
                                    <td class="num"><?php echo h(formatExchangeRate((int) ($row['exchange_rate'] ?? 10000))); ?></td>
                                    <td class="num"><?php echo h(formatCents($amountCents)); ?></td>
                                    <td><?php echo h($row['category']); ?></td>
                                    <td><?php echo h($row['username']); ?></td>
                                    <td><?php echo h((string) ($row['note'] ?? '')); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="print-page-controls">
                    <p class="sub">請使用瀏覽器列印；系統不提供 PDF 下載。</p>
                    <?php if ($entries !== []): ?><button type="button" class="btn primary" data-print-page>列印</button><?php endif; ?>
                    <a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>&amp;ym=<?php echo h(substr($startDate, 0, 7)); ?>" class="btn secondary">返回帳本詳情</a>
                </div>
            </main>
        </div>
    </div>

    <script src="./assets/app.js?v=20261127-print"></script>
</body>
</html>