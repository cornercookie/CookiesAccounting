<?php
// 全家人記帳系統 — 行事曆檢視（[#17] 行事曆檢視功能）
// 純 PHP 伺服端渲染（SSR），無前端行事曆庫。
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 入口點第一道防線：於任何 $_GET 參數處理之前執行 requireLogin()
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 預設當月（Asia/Taipei 時區）
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
$defaultYm = $today->format('Y-m');

// === 月份參數解析（§3.2 第 2 點）===
// 驗證格式 /^\d{4}-(0[1-9]|1[0-2])$/；驗證通過後額外檢查年份下限 1-9999
$ymRaw = $_GET['ym'] ?? '';
$ym = null;
$ymHint = '';
$ledgerHint = '';
if ($ymRaw !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ymRaw)) {
    $year = (int) substr($ymRaw, 0, 4);
    if ($year >= 1 && $year <= 9999) {
        $ym = $ymRaw;
    } else {
        $ymHint = '月份年份超出有效範圍，已顯示當月行事曆。';
    }
}
if ($ym === null) {
    $ym = $defaultYm;
    if ($ymHint === '' && $ymRaw !== '') {
        $ymHint = '月份格式不正確，已顯示當月行事曆。';
    }
}

// 月份首日與末日（僅由伺服端建構，絕不來自 $_GET）
$monthStart = (new DateTimeImmutable($ym . '-01', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
$monthEnd   = (new DateTimeImmutable($monthStart . ' 00:00:00', new DateTimeZone('Asia/Taipei')))->format('Y-m-t');
$ymLabel    = (new DateTimeImmutable($monthStart))->format('Y 年 n 月');

// === 單帳本檢視參數（§3.2 第 3 點）===
// 若未提供或為空字串，採用跨帳本檢視；否則驗證整數 > 0
$ledgerId = null;
$ledgerIdRaw = $_GET['ledger_id'] ?? '';
if ($ledgerIdRaw !== '') {
    $ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
    if ($ledgerId === false || $ledgerId <= 0) {
        $ledgerId = null;
        $ledgerHint = '帳本編號格式不正確，已顯示跨帳本行事曆。';
    }
}

// === 單帳本檢視前置權限檢查（§3.2 第 4 點、§11 STRIDE 側信道防護）===
// 無記錄時靜默回退跨帳本檢視，不區分「無此帳本」與「無此帳本權限」
$isSingleLedger = false;
$ledgerName = '所有可存取帳本';
$dbError = false;
if ($ledgerId !== null) {
    try {
        $accessStmt = $pdo->prepare('SELECT 1 FROM ledger_access WHERE ledger_id = :ledger_id AND user_id = :user_id LIMIT 1');
        $accessStmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
        if ($accessStmt->fetch() !== false) {
            $isSingleLedger = true;
            $ledgerStmt = $pdo->prepare('SELECT name FROM ledgers WHERE id = :ledger_id AND archived = 0 LIMIT 1');
            $ledgerStmt->execute([':ledger_id' => $ledgerId]);
            $ledgerRow = $ledgerStmt->fetch();
            if ($ledgerRow !== false) {
                $ledgerName = (string) $ledgerRow['name'];
            } else {
                // 帳本不存在或已封存，靜默回退跨帳本檢視
                $isSingleLedger = false;
                $ledgerId = null;
            }
        }
    } catch (Throwable $e) {
        logError('calendar.php ledger access check failed: ' . $e->getMessage());
        $isSingleLedger = false;
        $ledgerId = null;
        $dbError = true;
    }
}

// === 每日加總查詢（§3.2 第 7、8 點）===
// INNER JOIN ledger_access 做 IDOR 防護；INNER JOIN ledgers ON archived = 0 排除封存帳本
// 單帳本檢視時額外加入 AND e.ledger_id = :ledger_id（雙重防護）
$dailySummary = [];
try {
    $sql = '
        SELECT
            e.entry_date,
            SUM(CASE WHEN e.type = \'income\'  THEN e.amount_cents ELSE 0 END) AS income_cents,
            SUM(CASE WHEN e.type = \'expense\' THEN e.amount_cents ELSE 0 END) AS expense_cents,
            SUM(CASE WHEN e.type = \'transfer\' THEN e.amount_cents ELSE 0 END) AS transfer_cents
        FROM entries e
        INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
        INNER JOIN ledgers l ON l.id = e.ledger_id AND l.archived = 0
        WHERE e.deleted = 0
          AND e.entry_date BETWEEN :monthStart AND :monthEnd';
    $params = [
        ':user_id'   => $userId,
        ':monthStart'=> $monthStart,
        ':monthEnd'  => $monthEnd,
    ];
    if ($isSingleLedger && $ledgerId !== null) {
        $sql .= ' AND e.ledger_id = :ledger_id';
        $params[':ledger_id'] = $ledgerId;
    }
    $sql .= ' GROUP BY e.entry_date ORDER BY e.entry_date';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $dailySummary[$row['entry_date']] = [
            'income_cents'  => (int) ($row['income_cents']  ?? 0),
            'expense_cents' => (int) ($row['expense_cents'] ?? 0),
            'transfer_cents'=> (int) ($row['transfer_cents'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    logError('calendar.php daily summary query failed: ' . $e->getMessage());
    $dailySummary = [];
    $dbError = true;
}

// === 月份導航（§3.2 第 11 點）===
$ymPrev = (new DateTimeImmutable($monthStart . ' -1 month'))->format('Y-m');
$ymNext = (new DateTimeImmutable($monthStart . ' +1 month'))->format('Y-m');
$ymUrl = function (string $ymVal, ?int $ledgerIdRaw = null): string {
    $query = ['ym' => $ymVal];
    if ($ledgerIdRaw !== null) {
        $query['ledger_id'] = $ledgerIdRaw;
    }
    return './calendar.php?' . http_build_query($query);
};
$ymPrevUrl = $ymUrl($ymPrev, $isSingleLedger ? $ledgerId : null);
$ymNextUrl = $ymUrl($ymNext, $isSingleLedger ? $ledgerId : null);

// === 點擊日期導向（§3.2 第 10 點）===
// 單帳本檢視時日期格可點擊導向 ./ledger.php?ledger_id=X&ym=Y&entry_date=Z
// 跨帳本檢視時日期格不可點擊，僅顯示純文字金額
$dateLink = function (string $dateStr) use ($isSingleLedger, $ledgerId, $ym): string {
    if (!$isSingleLedger || $ledgerId === null) {
        return '';
    }
    $query = [
        'ledger_id' => (string) $ledgerId,
        'ym'        => $ym,
        'entry_date'=> $dateStr,
    ];
    return './ledger.php?' . http_build_query($query);
};

// === PHP DateTime 產生日曆格線（§3.2 第 9 點）===
// 含上月尾、本月、下月首
$firstDayOfMonth = new DateTimeImmutable($monthStart . ' 00:00:00', new DateTimeZone('Asia/Taipei'));
$firstWeekday    = (int) $firstDayOfMonth->format('N'); // 1=週一 ... 7=週日
// 將週日（7）視為第 7 格（最後一欄），週一至週六為第 1-6 欄
$firstCol = ($firstWeekday === 7) ? 7 : $firstWeekday;
$daysInMonth = (int) $firstDayOfMonth->format('t');

// 上月最後幾日
$prevMonth = (new DateTimeImmutable($monthStart . ' -1 month'))->format('Y-m');
$prevDaysInMonth = (int) (new DateTimeImmutable($prevMonth . '-01'))->format('t');
$prevStartDay = $prevDaysInMonth - $firstCol + 2;
$calendarDays = [];
for ($i = $prevStartDay; $i <= $prevDaysInMonth; $i++) {
    $d = (new DateTimeImmutable($prevMonth . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)))->format('Y-m-d');
    $calendarDays[] = [
        'date'     => $d,
        'day'      => $i,
        'month'    => (int) substr($d, 5, 2),
        'year'     => (int) substr($d, 0, 4),
        'isCurrent'=> false,
        'isPrev'   => true,
        'isNext'   => false,
    ];
}
// 本月
for ($i = 1; $i <= $daysInMonth; $i++) {
    $d = $ym . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $isToday = ($d === $today->format('Y-m-d'));
    $calendarDays[] = [
        'date'     => $d,
        'day'      => $i,
        'month'    => (int) substr($d, 5, 2),
        'year'     => (int) substr($d, 0, 4),
        'isCurrent'=> true,
        'isToday'  => $isToday,
        'isPrev'   => false,
        'isNext'   => false,
    ];
}
// 下月首幾日
$totalCells = 6 * 7; // 6 週 x 7 日
$nextMonth = (new DateTimeImmutable($monthStart . ' +1 month'))->format('Y-m');
$nextDay = 1;
while (count($calendarDays) < $totalCells) {
    $d = $nextMonth . '-' . str_pad((string) $nextDay, 2, '0', STR_PAD_LEFT);
    $calendarDays[] = [
        'date'     => $d,
        'day'      => $nextDay,
        'month'    => (int) substr($d, 5, 2),
        'year'     => (int) substr($d, 0, 4),
        'isCurrent'=> false,
        'isPrev'   => false,
        'isNext'   => true,
    ];
    $nextDay++;
}

// === 錯誤/成功訊息 ===
$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msgRaw  = $_GET['msg'] ?? '';
$flashMsg = is_string($msgRaw) ? $msgRaw : '';

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>行事曆檢視 - 全家人記帳系統</title>
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
                <a class="nav-item" href="<?php echo $isSingleLedger && $ledgerId !== null ? './add_entry.php?ledger_id=' . h((string) $ledgerId) : './ledgers.php'; ?>">新增記錄</a>
                <a class="nav-item active" href="./calendar.php">行事曆檢視</a>
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
                        <h1>行事曆檢視</h1>
                        <p class="sub">
                            檢視：<strong><?php echo h($ymLabel); ?></strong>
                            <?php if ($isSingleLedger): ?>
                                │ 帳本：<strong><?php echo h($ledgerName); ?></strong>
                            <?php endif; ?>
                            │ <a href="./ledgers.php">← 返回帳本列表</a>
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <a href="<?php echo h($ymPrevUrl); ?>" class="btn secondary sm">← 上月</a>
                        <a href="<?php echo h($ymUrl($ym, $isSingleLedger ? $ledgerId : null)); ?>" class="btn sm">本月</a>
                        <a href="<?php echo h($ymNextUrl); ?>" class="btn secondary sm">下月 →</a>
                        <?php if ($isSingleLedger): ?>
                            <a href="<?php echo h('./ledger.php?' . http_build_query(['ledger_id' => (string) $ledgerId, 'ym' => $ym])); ?>" class="btn primary sm">查看該帳本明細</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($flashMsg !== '' ? $flashMsg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($flashMsg !== '' ? $flashMsg : '操作失敗。'); ?></p>
                <?php endif; ?>
                <?php if ($ymHint !== ''): ?>
                    <p class="alert hint" role="status"><?php echo h($ymHint); ?></p>
                <?php endif; ?>
                <?php if ($ledgerHint !== ''): ?>
                    <p class="alert hint" role="status"><?php echo h($ledgerHint); ?></p>
                <?php endif; ?>
                <?php if ($dbError): ?>
                    <p class="alert error" role="alert">資料庫查詢失敗，請稍後再試。</p>
                <?php endif; ?>

                <div class="calendar-wrap" aria-label="<?php echo h($ymLabel); ?>行事曆">
                    <table class="table calendar-table" role="grid" aria-labelledby="calendar-title">
                        <caption class="calendar-caption" id="calendar-title"><?php echo h($ymLabel); ?> 行事曆</caption>
                        <thead>
                            <tr>
                                <th scope="col">週一</th>
                                <th scope="col">週二</th>
                                <th scope="col">週三</th>
                                <th scope="col">週四</th>
                                <th scope="col">週五</th>
                                <th scope="col">週六</th>
                                <th scope="col">週日</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $col = 1;
                            foreach ($calendarDays as $idx => $day):
                                if ($idx % 7 === 0) {
                                    echo '<tr>';
                                }
                                $isCurrentMonth = $day['isCurrent'];
                                $dayData   = $dailySummary[$day['date']] ?? ['income_cents' => 0, 'expense_cents' => 0, 'transfer_cents' => 0];
                                $incomeCents  = $dayData['income_cents']  ?? 0;
                                $expenseCents = $dayData['expense_cents'] ?? 0;
                                $transferCents= $dayData['transfer_cents'] ?? 0;
                                $balanceCents = $incomeCents - $expenseCents;
                                $hasData = ($incomeCents > 0 || $expenseCents > 0 || $transferCents > 0);
                                $dateLinkUrl = $isCurrentMonth ? $dateLink($day['date']) : '';
                            ?>
                                <td class="calendar-day <?php echo $day['isCurrent'] ? 'cal-day-current' : 'cal-day-other'; ?> <?php echo $day['isToday'] ? 'cal-day-today' : ''; ?>" role="gridcell">
                                    <div class="cal-day-inner">
                                        <span class="cal-day-num"><?php echo h((string) $day['day']); ?></span>
                                        <?php if ($isCurrentMonth && $hasData): ?>
                                            <div class="cal-day-summary">
                                                <?php if ($incomeCents > 0): ?>
                                                    <span class="cal-amt cal-amt-in" title="收入 <?php echo h(formatCents($incomeCents)); ?> 元">+<?php echo h(formatCents($incomeCents)); ?></span>
                                                <?php endif; ?>
                                                <?php if ($expenseCents > 0): ?>
                                                    <span class="cal-amt cal-amt-out" title="支出 <?php echo h(formatCents($expenseCents)); ?> 元">-<?php echo h(formatCents($expenseCents)); ?></span>
                                                <?php endif; ?>
                                                <?php if ($transferCents > 0): ?>
                                                    <span class="cal-amt cal-amt-transfer" title="轉帳 <?php echo h(formatCents($transferCents)); ?> 元"><?php echo h(formatCents($transferCents)); ?></span>
                                                <?php endif; ?>
                                                <?php if ($balanceCents !== 0 || $hasData): ?>
                                                    <span class="cal-amt cal-amt-balance" title="結餘 <?php echo h(formatCents($balanceCents)); ?> 元"><?php echo h(($balanceCents >= 0 ? '+' : '') . formatCents($balanceCents)); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isCurrentMonth && $hasData && $isSingleLedger && $ledgerId !== null): ?>
                                            <a href="<?php echo h($dateLinkUrl); ?>" class="cal-day-link">查看詳情</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php
                                $col++;
                                if ($col > 7) {
                                    echo '</tr>';
                                    $col = 1;
                                }
                            endforeach;
                            if ($col > 1) {
                                for ($i = $col; $i <= 7; $i++) {
                                    echo '<td class="cal-day-empty"></td>';
                                }
                                echo '</tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <div class="calendar-legend" aria-label="圖例">
                    <span class="legend-item"><span class="legend-dot cal-amt-in"></span>收入</span>
                    <span class="legend-item"><span class="legend-dot cal-amt-out"></span>支出</span>
                    <span class="legend-item"><span class="legend-dot cal-amt-transfer"></span>轉帳</span>
                </div>

                <?php if (!$isSingleLedger): ?>
                    <p class="alert hint" role="status">提示：點選帳本列表中的帳本可進入單帳本行事曆檢視，並可點擊日期查看該日記錄。</p>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="./assets/app.js"></script>
</body>
</html>
