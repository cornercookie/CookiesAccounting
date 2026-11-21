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
$stmt = $pdo->prepare('
    SELECT l.name, l.archived, la.role
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
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法匯出報表。'));
}

// 讀取日期參數，預設為當月首日與當月最後一日（Asia/Taipei 時區）
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
$startDateRaw = $_GET['start_date'] ?? '';
$endDateRaw   = $_GET['end_date'] ?? '';

if (!is_string($startDateRaw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateRaw) || !isValidDate($startDateRaw)) {
    $startDate = $today->format('Y-m-01');
} else {
    $startDate = $startDateRaw;
}

if (!is_string($endDateRaw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDateRaw) || !isValidDate($endDateRaw)) {
    $endDate = $today->format('Y-m-t');
} else {
    $endDate = $endDateRaw;
}

// 結束日期不得早於開始日期
if ($endDate < $startDate) {
    $endDate = $startDate;
}

// 查詢該帳本所有非作廢記錄
$stmt = $pdo->prepare('
    SELECT e.entry_date, e.type, e.amount_cents, e.category, e.note, u.username
    FROM entries e
    INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
    WHERE e.ledger_id = :ledger_id
      AND e.deleted = 0
      AND e.entry_date BETWEEN :startDate AND :endDate
    ORDER BY e.entry_date DESC, e.id DESC
');
$stmt->execute([
    ':user_id'      => $userId,
    ':ledger_id'    => $ledgerId,
    ':startDate'    => $startDate,
    ':endDate'      => $endDate,
]);
$entries = $stmt->fetchAll();

// 統計總額
$totalIncomeCents  = 0;
$totalExpenseCents = 0;
$totalTransferCents = 0;
foreach ($entries as $row) {
    if ($row['type'] === 'income') {
        $totalIncomeCents += (int) $row['amount_cents'];
    } elseif ($row['type'] === 'expense') {
        $totalExpenseCents += (int) $row['amount_cents'];
    } elseif ($row['type'] === 'transfer') {
        $totalTransferCents += (int) $row['amount_cents'];
    }
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
                                <tr class="pdf-total">
                                    <td><strong>總計</strong></td>
                                    <td class="num"></td>
                                    <td class="num"><?php echo h(formatCents($totalIncomeCents)); ?></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <p><a href="./export_pdf.php?ledger_id=<?php echo h((string) $ledgerId) . '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate); ?>" class="btn primary">嘗試重新匯出 PDF</a></p>
                <p><a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">返回帳本詳情</a></p>
            </main>
        </div>
    </div>

    <script>
        // 字級切換：Cookie 讀寫與 .font-large 切換
        (function () {
            var toggle = document.getElementById('fontSizeToggle');
            if (!toggle) return;

            function getCookie(name) {
                var value = '; ' + document.cookie;
                var parts = value.split('; ' + name + '=');
                if (parts.length === 2) return parts.pop().split(';').shift();
                return null;
            }

            function setCookie(name, value, days) {
                var expires = new Date();
                expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000);
                document.cookie = name + '=' + value + '; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
            }

            var fontSize = getCookie('font_size');
            if (fontSize === 'large') {
                document.body.classList.add('font-large');
                toggle.textContent = '一般 A';
            }

            toggle.addEventListener('click', function () {
                if (document.body.classList.contains('font-large')) {
                    document.body.classList.remove('font-large');
                    setCookie('font_size', 'normal', 90);
                    toggle.textContent = '大字 A';
                } else {
                    document.body.classList.add('font-large');
                    setCookie('font_size', 'large', 90);
                    toggle.textContent = '一般 A';
                }
            });
        })();
    </script>
</body>
</html>
