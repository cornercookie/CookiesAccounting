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
$isCurrentMonth = ($today->format('Y-m') === date('Y-m'));

$stmt = $pdo->prepare('
    SELECT
        l.id,
        l.name,
        la.role AS access_role,
        l.monthly_budget_cents,
        (SELECT COUNT(*) FROM entries e WHERE e.ledger_id = l.id AND e.deleted = 0) AS entry_count,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM entries e
            WHERE e.ledger_id = l.id AND e.deleted = 0 AND e.type = \'income\'
              AND e.entry_date BETWEEN :msA AND :meA) AS month_income,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM entries e
            WHERE e.ledger_id = l.id AND e.deleted = 0 AND e.type = \'expense\'
              AND e.entry_date BETWEEN :msB AND :meB) AS month_expense,
        (SELECT COALESCE(SUM(e.amount_cents), 0) FROM entries e
            WHERE e.ledger_id = l.id AND e.deleted = 0 AND e.type = \'transfer\'
              AND e.entry_date BETWEEN :msA AND :meA) AS month_transfer,
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

// 全家跨帳本本月總覽（複用 $ledgerSummaries，不新增 SQL）
$summaryError = false;
$totalIncome = 0;
$totalExpense = 0;
$totalBalance = 0;

try {
    foreach ($ledgerSummaries as $ls) {
        $totalIncome  += (int) ($ls['month_income']  ?? 0);
        $totalExpense += (int) ($ls['month_expense'] ?? 0);
    }
    $totalBalance = $totalIncome - $totalExpense;
} catch (Throwable $e) {
    logError('跨帳本本月總覽加總失敗：' . $e->getMessage());
    $summaryError = true;
}
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
                        <h1>儀表板</h1>
                        <p class="sub">歡迎回來，<?php echo h($user['username']); ?>；這是本家的傳票總帳。</p>
                    </div>
                    <?php if (!empty($_SESSION['show_login_notice'])): ?>
                    <div class="alert warn" role="alert">
                        偵測到新登入來源：
                        <strong class="num"><?php echo h((string) ($_SESSION['login_notice_ip'] ?? '')); ?></strong>，
                        時間 <?php echo h((string) ($_SESSION['login_notice_at'] ?? '')); ?>，
                        若非本人操作請立即變更密碼。
                    </div>
                    <?php unset($_SESSION['show_login_notice']); unset($_SESSION['login_notice_ip']); unset($_SESSION['login_notice_at']); endif; ?>
                    <a class="btn primary" href="./ledgers.php">前往帳本</a>
                </div>

                <?php foreach ($ledgerSummaries as $ls): ?>
                    <?php
                    $lsBudget = (int) ($ls['monthly_budget_cents'] ?? 0);
                    $lsBudgetRate = null;
                    if ($isCurrentMonth && $lsBudget > 0) {
                        $lsBudgetRate = (int) $ls['month_expense'] / $lsBudget;
                    }
                    ?>
                    <?php if ($lsBudgetRate !== null && $lsBudgetRate >= 0.8): ?>
                    <div class="alert <?php echo $lsBudgetRate >= 1 ? 'danger' : 'warn'; ?>" role="status">
                        <?php echo h($ls['name']); ?> 本月預算已使用
                        <strong class="num"><?php echo h((string) ($lsBudgetRate >= 1 ? 100 : (int) ($lsBudgetRate * 100))); ?>%</strong>：
                        <?php echo $lsBudgetRate >= 1 ? '已超過預算' : '接近預算上限'; ?>
                        （預算 <?php echo h(formatCents($lsBudget)); ?> 元，已支出
                        <strong class="num"><?php echo h(formatCents((int) $ls['month_expense'])); ?></strong> 元）
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- 重複記錄登入提醒（Feature 11） -->
                <?php
                $recurringReminders = [];
                foreach ($ledgerSummaries as $ls) {
                    $recStmt = $pdo->prepare('
                        SELECT re.id, re.name, re.amount_cents, re.type, re.category, re.next_due_date
                        FROM recurring_entries re
                        WHERE re.ledger_id = :ledger_id
                          AND re.is_active = 1
                    ');
                    $recStmt->execute([':ledger_id' => (int) $ls['id']]);
                    while ($rec = $recStmt->fetch()) {
                        // 檢查本月是否已有來源自該重複記錄的 entries
                        $genStmt = $pdo->prepare('
                            SELECT COUNT(*) AS cnt FROM entries
                            WHERE recurring_source_id = :recurring_id
                              AND entry_date BETWEEN :ms AND :me
                        ');
                        $genStmt->execute([
                            ':recurring_id' => (int) $rec['id'],
                            ':ms' => $monthStart,
                            ':me' => $monthEnd,
                        ]);
                        $genRow = $genStmt->fetch();
                        if ($genRow === false || (int) $genRow['cnt'] === 0) {
                            $recurringReminders[] = [
                                'ledger_name' => $ls['name'],
                                'recurring_name' => $rec['name'],
                                'amount_cents' => (int) $rec['amount_cents'],
                                'type' => $rec['type'],
                                'next_due_date' => $rec['next_due_date'],
                                'ledger_id' => (int) $ls['id'],
                            ];
                        }
                    }
                }
                ?>
                <?php if (!empty($recurringReminders)): ?>
                <div class="alert warn" role="status">
                    <strong>有 <?php echo h((string) count($recurringReminders)); ?> 筆固定開銷尚未記帳：</strong>
                    <ul style="margin: var(--space-2) 0 0 var(--space-4); padding-left: var(--space-4);">
                        <?php foreach ($recurringReminders as $rem): ?>
                        <li>
                            <?php echo h($rem['ledger_name']); ?>：「<?php echo h($rem['recurring_name']); ?>」
                            （<?php echo h($rem['type'] === 'income' ? '收入' : '支出'); ?>
                            <?php echo h(formatCents($rem['amount_cents'])); ?> 元，
                            下次預計 <?php echo h($rem['next_due_date']); ?>）
                            <a href="./ledger.php?ledger_id=<?php echo h((string) $rem['ledger_id']); ?>" class="btn secondary sm" style="margin-left: var(--space-2);">立即記一筆</a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!$summaryError && !empty($ledgerSummaries)): ?>
                <div class="summary-total plates">
                    <div class="plate"><small>全家本月總收入</small><div class="v">+<?php echo h(formatCents($totalIncome)); ?><small> 元</small></div></div>
                    <div class="plate"><small>全家本月總支出</small><div class="v">-<?php echo h(formatCents($totalExpense)); ?><small> 元</small></div></div>
                    <div class="plate"><small>全家本月總結餘</small><div class="v"><?php echo h($totalBalance >= 0 ? '+' : '') . h(formatCents($totalBalance)); ?><small> 元</small></div></div>
                </div>
                <?php endif; ?>

                <p class="summary-h">各帳本 · 本月收支</p>
                <?php if ($ledgerSummaries): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th rowspan="2">帳本</th>
                                    <th rowspan="2">您的身份</th>
                                    <th colspan="4" class="group-h">本月</th>
                                    <th colspan="3" class="group-h group-start">總表</th>
                                    <th class="num hide-m" rowspan="2">記錄數</th>
                                    <th class="ctr" rowspan="2">進入</th>
                                </tr>
                                <tr>
                                    <th class="num">收入</th>
                                    <th class="num">支出</th>
                                    <th class="num">結餘</th>
                                    <th class="num">轉帳</th>
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
                                        // 預算紅線警示：僅在已設定預算且當月支出達門檻時顯示（≥80% 警告、≥100% 危險；整數判定）
                                        $lsBudgetChip = '';
                                        $lsBudget = (int) ($ls['monthly_budget_cents'] ?? 0);
                                        if ($lsBudget > 0) {
                                            if ((int) $ls['month_expense'] * 100 >= $lsBudget * 100) {
                                                $lsBudgetChip = 'danger';
                                            } elseif ((int) $ls['month_expense'] * 100 >= $lsBudget * 80) {
                                                $lsBudgetChip = 'warn';
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><a class="ledger-link" href="./ledger.php?ledger_id=<?php echo h((string) $ls['id']); ?>"><strong><?php echo h($ls['name']); ?></strong></a><?php if ($lsBudgetChip !== ''): ?> <span class="budget-chip <?php echo h($lsBudgetChip); ?>"><?php echo $lsBudgetChip === 'danger' ? '已超支' : '近上限'; ?></span><?php endif; ?></td>
                                        <td><span class="role-tag <?php echo h($ls['access_role']); ?>"><?php echo h($ls['access_role']); ?></span></td>
                                        <td class="num amt-in">+<?php echo h(formatCents((int) $ls['month_income'])); ?></td>
                                        <td class="num amt-out">-<?php echo h(formatCents((int) $ls['month_expense'])); ?></td>
                                        <td class="num"><?php echo h($lsBalance >= 0 ? '+' : '') . h(formatCents($lsBalance)); ?></td>
                                        <td class="num amt-neutral"><?php echo h(formatCents((int) $ls['month_transfer'])); ?></td>
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

                <?php if ($ledgerSummaries): ?>
                <p class="summary-h">本月各成員記帳進度</p>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>帳本</th>
                                <th>成員</th>
                                <th class="num">本月記錄數</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledgerSummaries as $ls): ?>
                                <?php
                                $memberStmt = $pdo->prepare('
                                    SELECT u.username, COALESCE(COUNT(e.id), 0) AS entry_count
                                    FROM ledger_access la
                                    INNER JOIN users u ON u.id = la.user_id
                                    LEFT JOIN entries e
                                        ON e.ledger_id = la.ledger_id
                                       AND e.user_id = la.user_id
                                       AND e.deleted = 0
                                       AND e.entry_date BETWEEN :ms AND :me
                                    WHERE la.ledger_id = :ledger_id
                                    GROUP BY u.id, u.username
                                    ORDER BY u.username
                                ');
                                $memberStmt->execute([
                                    ':ledger_id' => (int) $ls['id'],
                                    ':ms' => $monthStart,
                                    ':me' => $monthEnd,
                                ]);
                                $membersProgress = $memberStmt->fetchAll();
                                ?>
                                <?php foreach ($membersProgress as $mp): ?>
                                    <tr>
                                        <td><?php echo h($ls['name']); ?></td>
                                        <td><?php echo h($mp['username']); ?></td>
                                        <td class="num"><?php echo h((string) $mp['entry_count']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                        <a class="quick" href="./admin/audit_log.php">
                            <b>操作紀錄 →</b>
                            <span>登入、記帳與後台操作的審計紀錄。</span>
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
