<?php
// 全家人記帳系統 — 匯出 PDF 月結單備援頁面（TCPDF 載入失敗時）
// 提供列印優化的 HTML 頁面，讓使用者透過瀏覽器「列印為 PDF」
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
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法匯出報表。'));
}

// 讀取日期參數，預設為當月首日與當月最後一日（Asia/Taipei 時區）
$startDateRaw = $_GET['start_date'] ?? '';
$endDateRaw   = $_GET['end_date'] ?? '';
[$startDate, $endDate, $dateError] = parseDateRange($startDateRaw, $endDateRaw);

if ($dateError !== '') {
    // 日期錯誤：顯示友善錯誤頁面，不 redirect
    $content = '<p class="alert error" role="alert">' . h($dateError) . '</p>';
    renderPdfExportPage($user, '匯出 PDF 月結單', $ledger['name'], '查詢期間：' . h($startDate) . ' ～ ' . h($endDate), $content);
    exit;
}

// 查詢明細與彙總
[$entries, $monthlyStats, $queryError] = fetchEntriesWithStats($pdo, $userId, $ledgerId, $startDate, $endDate);

if ($queryError !== '') {
    // 查詢失敗：顯示友善錯誤頁面
    $content = '<p class="alert error" role="alert">' . h($queryError) . '</p>';
    renderPdfExportPage($user, '匯出 PDF 月結單', $ledger['name'], '查詢期間：' . h($startDate) . ' ～ ' . h($endDate), $content);
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
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>匯出 PDF 月結單 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
    <style>
        /* 列印優化樣式 */
        @media print {
            .side, .nav, .ctr-actions, .quick, .font-size-toggle {
                display: none !important;
            }
            .main { margin: 0; padding: 0; }
            .sheet { width: 100%; max-width: 100%; }
            body { background: #fff; color: #000; }
            .pdf-report { page-break-inside: avoid; }
            .pdf-table, .pdf-total { page-break-inside: avoid; }
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
                        <h1>匯出 PDF 月結單</h1>
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
                                    <th class="num">類型</th>
                                    <th class="num">金額（元）</th>
                                    <th>分類</th>
                                    <th>記錄者</th>
                                    <th>備註</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $row): ?>
                                <tr>
                                    <td><?php echo h($row['entry_date']); ?></td>
                                    <td class="num"><?php echo h($row['type'] === 'income' ? '收入' : ($row['type'] === 'expense' ? '支出' : '轉帳')); ?></td>
                                    <td class="num"><?php echo h(formatCents((int) $row['amount_cents'])); ?></td>
                                    <td><?php echo h($row['category']); ?></td>
                                    <td><?php echo h($row['username']); ?></td>
                                    <td><?php echo h((string) ($row['note'] ?? '')); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <p><a href="./export_pdf.php?ledger_id=<?php echo h((string) $ledgerId) . '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate); ?>" class="btn primary">嘗試重新匯出 PDF</a></p>
                <p><a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">返回帳本詳情</a></p>
            </main>
        </div>
    </div>

    <script src="./assets/app.js"></script>
</body>
</html>