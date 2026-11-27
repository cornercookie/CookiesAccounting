<?php
// 全家人記帳系統 — 收支比較報表（本月 vs 上月 / 今年 vs 去年）
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
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法檢視比較報表。'));
}
$accessRole = $ledger['role'];

// 讀取比較模式，預設 month；無效則回 400
$mode = ($_GET['mode'] ?? 'month');
if (!in_array($mode, ['month', 'year'], true)) {
    http_response_code(400);
    exit('無效的比較模式。');
}

// 根據模式計算兩個日期區間（Period A / Period B）
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
if ($mode === 'month') {
    // Period A = 本月全月，Period B = 上月全月
    $periodAStart = $today->format('Y-m-01');
    $periodAEnd   = $today->format('Y-m-t');
    $lastMonth = $today->modify('first day of last month');
    $periodBStart = $lastMonth->format('Y-m-01');
    $periodBEnd   = $lastMonth->format('Y-m-t');
    $periodALabel = $today->format('Y年m月');
    $periodBLabel = $lastMonth->format('Y年m月');
} else {
    // Period A = 今年全年，Period B = 去年全年
    $year = (int) $today->format('Y');
    $periodAStart = $year . '-01-01';
    $periodAEnd   = $year . '-12-31';
    $periodBStart = ($year - 1) . '-01-01';
    $periodBEnd   = ($year - 1) . '-12-31';
    $periodALabel = $year . '年';
    $periodBLabel = ($year - 1) . '年';
}

// 日期格式防禦性驗證（伺服端計算，正常不觸發；錯誤則 400 並終止）
if (!isValidDate($periodAStart) || !isValidDate($periodAEnd) || !isValidDate($periodBStart) || !isValidDate($periodBEnd)) {
    http_response_code(400);
    exit('日期格式錯誤。');
}

// 讀取類別下拉選單（僅顯示該帳本已使用支出類別，帶 INNER JOIN ledger_access IDOR 防護）
$categoryStmt = $pdo->prepare("
    SELECT DISTINCT e.category
    FROM entries e
    INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
    WHERE e.ledger_id = :ledger_id
      AND e.deleted = 0
      AND e.type = 'expense'
    ORDER BY e.category
");
$categoryStmt->execute([':user_id' => $userId, ':ledger_id' => $ledgerId]);
$categories = $categoryStmt->fetchAll();

// 選取類別篩選（可選）
$categorySel = $_GET['category'] ?? '';
$categoryValues = [];
foreach ($categories as $c) {
    $categoryValues[] = $c['category'];
}
if ($categorySel !== '' && !in_array($categorySel, $categoryValues, true)) {
    $categorySel = '';
}

// CSRF 權杖：僅於表單提交（_submit === '1'）時驗證，初次載入網址不強制（維持網址可分享）
$csrfError = '';
$isSubmitted = (($_GET['_submit'] ?? '') === '1');
if ($isSubmitted) {
    if (!checkCsrf($_GET['csrf_token'] ?? '')) {
        $csrfError = '請求驗證失敗，請重新提交表單。';
    }
}

// 查詢 Period A 與 Period B 類別支出彙總
$periodAStats = [];
$periodBStats = [];
$queryError = '';

if ($csrfError === '') {
    // Period A
    try {
        $sql = "
            SELECT e.category, SUM(e.amount_cents) AS total_cents
            FROM entries e
            INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
            WHERE e.ledger_id = :ledger_id
              AND e.deleted = 0
              AND e.type = 'expense'
              AND e.entry_date BETWEEN :startDateA AND :endDateA
        " . ($categorySel !== '' ? " AND e.category = :category" : "") . "
            GROUP BY e.category
            ORDER BY total_cents DESC
        ";
        $stmt = $pdo->prepare($sql);
        $params = [
            ':user_id'   => $userId,
            ':ledger_id' => $ledgerId,
            ':startDateA' => $periodAStart,
            ':endDateA'   => $periodAEnd,
        ];
        if ($categorySel !== '') {
            $params[':category'] = $categorySel;
        }
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $periodAStats[$row['category']] = (int) $row['total_cents'];
        }
    } catch (Throwable $e) {
        logError('comparison_report Period A query failed: ' . $e->getMessage());
        $queryError = '報表查詢失敗，請稍後再試。';
    }

    // Period B
    if ($queryError === '') {
        try {
            $sql = "
                SELECT e.category, SUM(e.amount_cents) AS total_cents
                FROM entries e
                INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
                WHERE e.ledger_id = :ledger_id
                  AND e.deleted = 0
                  AND e.type = 'expense'
                  AND e.entry_date BETWEEN :startDateB AND :endDateB
            " . ($categorySel !== '' ? " AND e.category = :category" : "") . "
                GROUP BY e.category
                ORDER BY total_cents DESC
            ";
            $stmt = $pdo->prepare($sql);
            $params = [
                ':user_id'   => $userId,
                ':ledger_id' => $ledgerId,
                ':startDateB' => $periodBStart,
                ':endDateB'   => $periodBEnd,
            ];
            if ($categorySel !== '') {
                $params[':category'] = $categorySel;
            }
            $stmt->execute($params);
            while ($row = $stmt->fetch()) {
                $periodBStats[$row['category']] = (int) $row['total_cents'];
            }
        } catch (Throwable $e) {
            logError('comparison_report Period B query failed: ' . $e->getMessage());
            $queryError = '報表查詢失敗，請稍後再試。';
        }
    }
}

// 合併類別清單，計算差額（絕對值）與百分比變動（整數運算，禁止 FLOAT）
$comparison = [];
$totalACents = 0;
$totalBCents = 0;
$totalDiffCents = 0;

$allCategories = array_unique(array_merge(array_keys($periodAStats), array_keys($periodBStats)));
sort($allCategories);

foreach ($allCategories as $category) {
    $aCents = $periodAStats[$category] ?? 0;
    $bCents = $periodBStats[$category] ?? 0;
    $diffCents = abs($aCents - $bCents);
    if ($bCents > 0) {
        $percentChange = (int) floor($diffCents * 100 / $bCents);
    } else {
        $percentChange = null;
    }
    $comparison[] = [
        'category'       => $category,
        'periodA'        => $aCents,
        'periodB'        => $bCents,
        'diff'           => $diffCents,
        'percentChange'  => $percentChange,
    ];
    $totalACents += $aCents;
    $totalBCents += $bCents;
    $totalDiffCents += $diffCents;
}

$hasData = count($comparison) > 0;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>收支比較報表 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261127-1">
    <style>
        /* 收支比較報表列印樣式 */
        @media print {
            .side, .nav, .ctr-actions, .quick, .font-size-toggle, .filters {
                display: none !important;
            }
            .main { margin: 0; padding: 0; }
            .sheet { width: 100%; max-width: 100%; }
            body { background: #fff; color: #000; }
            .plate { border: 1px solid #000; }
            .v { color: #000; }
            .comparison-table, .comparison-chart { page-break-inside: avoid; }
            .comparison-table tfoot tr.comparison-total-row td {
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
                        <h1>收支比較報表</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong>　·　模式：<strong><?php echo h($mode === 'month' ? '本月 vs 上月' : '今年 vs 去年'); ?></strong>　·　<a href="./ledgers.php">← 返回帳本列表</a></p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗：請檢查輸入後重試。'); ?></p>
                <?php endif; ?>
                <?php if ($csrfError !== ''): ?>
                    <p class="alert error" role="alert"><?php echo h($csrfError); ?></p>
                <?php endif; ?>
                <?php if ($queryError !== ''): ?>
                    <p class="alert error" role="alert"><?php echo h($queryError); ?></p>
                <?php endif; ?>

                <!-- 比較模式與帳本選擇表單 -->
                <form method="GET" action="./comparison_report.php" class="filters" aria-label="選擇比較模式與帳本">
                    <input type="hidden" name="_submit" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                    <label class="filter">
                        <span class="filter-label">比較模式</span>
                        <select name="mode" class="select filter-input">
                            <option value="month"<?php echo $mode === 'month' ? ' selected' : ''; ?>>本月 vs 上月</option>
                            <option value="year"<?php echo $mode === 'year' ? ' selected' : ''; ?>>今年 vs 去年</option>
                        </select>
                    </label>
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
                        <span class="filter-label">類別</span>
                        <select name="category" class="select filter-input">
                            <option value="">全部類別</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo h($cat['category']); ?>"<?php echo $categorySel === $cat['category'] ? ' selected' : ''; ?>>
                                    <?php echo h($cat['category']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn primary sm">查詢</button>
                </form>

                <?php if (!$hasData): ?>
                    <p class="report-empty">尚無記錄。</p>
                <?php else: ?>
                    <div class="comparison-chart" aria-label="類別支出比較圖表">
                        <h3><?php echo h($periodALabel . ' vs ' . $periodBLabel . ' 各類別支出比較'); ?></h3>
                        <?php
                        // 共用版面數值（於開啟 SVG 前定義，供迴圈與 SVG 高度共用）
                        $barHeight = 22;
                        $gap       = 14;
                        $y         = 60;
                        $stride    = $barHeight * 2 + $gap; // 每組（藍+紅兩條）間距
                        $svgHeight = max(420, $y + count($comparison) * $stride);
                        ?>
                        <svg class="category-chart" viewBox="0 0 700 <?php echo h((string) $svgHeight); ?>" role="img" aria-labelledby="comparison-chart-title">
                            <title id="comparison-chart-title"><?php echo h($periodALabel . ' vs ' . $periodBLabel . ' 各類別支出比較'); ?></title>
                            <!-- 圖例 -->
                            <rect x="80" y="20" width="18" height="18" fill="var(--blue)" rx="2" />
                            <text x="105" y="34" font-size="12" fill="#23272b"><?php echo h($periodALabel); ?></text>
                            <rect x="260" y="20" width="18" height="18" fill="var(--red)" rx="2" />
                            <text x="285" y="34" font-size="12" fill="#23272b"><?php echo h($periodBLabel); ?></text>
                            <?php
                            $maxWidth = 360;
                            $maxCents = 0;
                            foreach ($comparison as $c) {
                                $maxCents = max($maxCents, $c['periodA'], $c['periodB']);
                            }
                            foreach ($comparison as $c):
                                $aWidth = $maxCents > 0 ? (int) ($maxWidth * $c['periodA'] / $maxCents) : 0;
                                $bWidth = $maxCents > 0 ? (int) ($maxWidth * $c['periodB'] / $maxCents) : 0;
                                $label = h($c['category']);
                                $aAmount = h(formatCents($c['periodA']));
                                $bAmount = h(formatCents($c['periodB']));
                                echo '<text x="75" y="' . (int) ($y + $barHeight / 2 + 5) . '" text-anchor="end" font-size="12" fill="#23272b">' . $label . '</text>';
                                echo '<rect x="80" y="' . (int) $y . '" width="' . $aWidth . '" height="' . (int) $barHeight . '" fill="var(--blue)" rx="2" />';
                                echo '<text x="' . (int) (80 + $aWidth + 5) . '" y="' . (int) ($y + $barHeight / 2 + 5) . '" font-size="11" fill="#23272b">' . $aAmount . '</text>';
                                echo '<rect x="80" y="' . (int) ($y + $barHeight + 4) . '" width="' . $bWidth . '" height="' . (int) $barHeight . '" fill="var(--red)" rx="2" />';
                                echo '<text x="' . (int) (80 + $bWidth + 5) . '" y="' . (int) ($y + $barHeight + 4 + $barHeight / 2 + 5) . '" font-size="11" fill="#23272b">' . $bAmount . '</text>';
                                $y += $stride;
                            endforeach;
                            ?>
                        </svg>
                    </div>

                    <div class="comparison-table">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>類別</th>
                                    <th class="num"><?php echo h($periodALabel); ?></th>
                                    <th class="num"><?php echo h($periodBLabel); ?></th>
                                    <th class="num">差額（絕對值）</th>
                                    <th class="num">變動幅度</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comparison as $c): ?>
                                <tr>
                                    <td><?php echo h($c['category']); ?></td>
                                    <td class="num amt-out mono">-<?php echo h(formatCents($c['periodA'])); ?></td>
                                    <td class="num amt-out mono">-<?php echo h(formatCents($c['periodB'])); ?></td>
                                    <td class="num mono"><?php echo h(formatCents($c['diff'])); ?></td>
                                    <td class="num mono"><?php echo $c['percentChange'] !== null ? h((string) $c['percentChange']) . '%' : '－'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="comparison-total-row">
                                    <td><strong>總計</strong></td>
                                    <td class="num amt-out mono">-<?php echo h(formatCents($totalACents)); ?></td>
                                    <td class="num amt-out mono">-<?php echo h(formatCents($totalBCents)); ?></td>
                                    <td class="num mono"><?php echo h(formatCents($totalDiffCents)); ?></td>
                                    <td class="num mono"><?php echo $totalBCents > 0 ? h((string) (int) floor($totalDiffCents * 100 / $totalBCents)) . '%' : '－'; ?></td>
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
