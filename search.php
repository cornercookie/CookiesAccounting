<?php
// 全家人記帳系統 — 全站記錄搜尋
// 功能：依關鍵字、金額範圍、日期範圍搜尋所有有存取權限的帳本記錄
// 結果分頁顯示（每頁 50 筆），依 entry_date DESC, id DESC 排序

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 登入保護
requireLogin();

$user = currentUser();
// requireLogin() 已保證已登入，currentUser() 不會回傳 null
assert($user !== null);
$pdo  = getPdo();
$userId = (int) $user['id'];

// 取得目前使用者可存取的帳本 ID 列表（用於查詢與顯示）
try {
    $accessStmt = $pdo->prepare('
        SELECT la.ledger_id, l.name, la.role
        FROM ledger_access la
        INNER JOIN ledgers l ON l.id = la.ledger_id
        WHERE la.user_id = :user_id
          AND l.archived = 0
        ORDER BY l.name
    ');
    $accessStmt->execute([':user_id' => $userId]);
    $accessibleLedgers = $accessStmt->fetchAll();
    $accessibleLedgerIds = array_column($accessibleLedgers, 'ledger_id');
} catch (Throwable $e) {
    logError('search.php: 查詢可存取帳本失敗: ' . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8"><title>系統錯誤</title><link rel="stylesheet" href="./assets/style.css?v=20261115-8"></head><body><div class="app"><div class="main"><main class="sheet"><h1>系統錯誤</h1><p>無法取得帳本權限，請稍後再試。</p></main></div></div></body></html>';
    exit;
}

// 處理搜尋參數
$keyword = '';
$amountMin = '';
$amountMax = '';
$dateFrom = '';
$dateTo = '';
$page = 1;
$errors = [];

// 保留原始輸入用於表單回填（驗證失敗時顯示使用者原始輸入）
$amountMinRawInput = '';
$amountMaxRawInput = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // CSRF 驗證
    $csrfRaw = $_POST['csrf_token'] ?? '';
    if (!checkCsrf(is_string($csrfRaw) ? $csrfRaw : '')) {
        http_response_code(400);
        $errors[] = '安全驗證失敗，請重新操作。';
    }

    // 關鍵字
    $keyword = inputString($_POST, 'keyword');

    // 金額下限
    $amountMinRawInput = inputString($_POST, 'amount_min');
    if ($amountMinRawInput !== '') {
        $parsed = toCents($amountMinRawInput);
        if ($parsed === false) {
            $errors[] = '金額下限格式不正確。';
        } else {
            $amountMin = (string) $parsed;
        }
    }

    // 金額上限
    $amountMaxRawInput = inputString($_POST, 'amount_max');
    if ($amountMaxRawInput !== '') {
        $parsed = toCents($amountMaxRawInput);
        if ($parsed === false) {
            $errors[] = '金額上限格式不正確。';
        } else {
            $amountMax = (string) $parsed;
        }
    }

    // 日期起始
    $dateFromRaw = inputString($_POST, 'date_from');
    if ($dateFromRaw !== '') {
        if (!isValidDate($dateFromRaw)) {
            $errors[] = '起始日期格式不正確（需為 Y-m-d 真實曆日）。';
        } else {
            $dateFrom = $dateFromRaw;
        }
    }

    // 日期結束
    $dateToRaw = inputString($_POST, 'date_to');
    if ($dateToRaw !== '') {
        if (!isValidDate($dateToRaw)) {
            $errors[] = '結束日期格式不正確（需為 Y-m-d 真實曆日）。';
        } else {
            $dateTo = $dateToRaw;
        }
    }

    // 頁碼
    $pageRaw = $_POST['page'] ?? 1;
    $page = max(1, (int) $pageRaw);

    // 如果有錯誤，回傳 HTTP 400
    if (!empty($errors)) {
        http_response_code(400);
    }
}

// 預設行為：GET 也允許帶參數（支援分頁連結）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $keyword = inputString($_GET, 'keyword');
    $amountMinRaw = inputString($_GET, 'amount_min');
    if ($amountMinRaw !== '') {
        $parsed = toCents($amountMinRaw);
        if ($parsed !== false) {
            $amountMin = (string) $parsed;
            $amountMinRawInput = $amountMinRaw; // 回填用原始輸入
        }
    }
    $amountMaxRaw = inputString($_GET, 'amount_max');
    if ($amountMaxRaw !== '') {
        $parsed = toCents($amountMaxRaw);
        if ($parsed !== false) {
            $amountMax = (string) $parsed;
            $amountMaxRawInput = $amountMaxRaw;
        }
    }
    $dateFromRaw = inputString($_GET, 'date_from');
    if ($dateFromRaw !== '' && isValidDate($dateFromRaw)) {
        $dateFrom = $dateFromRaw;
    }
    $dateToRaw = inputString($_GET, 'date_to');
    if ($dateToRaw !== '' && isValidDate($dateToRaw)) {
        $dateTo = $dateToRaw;
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
}

// 建構查詢條件
$where = 'WHERE e.deleted = 0 AND la.user_id = :user_id';
$params = [':user_id' => $userId];

// 關鍵字：搜尋 category 和 note，需逃脫 %, _, \
if ($keyword !== '') {
    // 逃脫萬用字元
    $escapedKeyword = addcslashes($keyword, '%_\\');
    $where .= ' AND (e.category LIKE :keyword ESCAPE \'\\\' OR e.note LIKE :keyword ESCAPE \'\\\')';
    $params[':keyword'] = '%' . $escapedKeyword . '%';
}

// 金額範圍
if ($amountMin !== '') {
    $where .= ' AND e.amount_cents >= :amountMin';
    $params[':amountMin'] = (int) $amountMin;
}
if ($amountMax !== '') {
    $where .= ' AND e.amount_cents <= :amountMax';
    $params[':amountMax'] = (int) $amountMax;
}

// 日期範圍
if ($dateFrom !== '') {
    $where .= ' AND e.entry_date >= :dateFrom';
    $params[':dateFrom'] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= ' AND e.entry_date <= :dateTo';
    $params[':dateTo'] = $dateTo;
}

$perPage = 50;

// 總筆數
$totalEntries = 0;
$totalPages = 1;
$entries = [];

try {
    $countSql = "SELECT COUNT(*) FROM entries e INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id $where";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalEntries = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEntries / $perPage));

    // 頁碼修正
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    // 查詢結果
    $selectSql = "
        SELECT e.id, e.ledger_id, e.entry_date, e.type, e.category, e.amount_cents, e.note, e.user_id, l.name AS ledger_name
        FROM entries e
        INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id
        INNER JOIN ledgers l ON l.id = e.ledger_id
        $where
        ORDER BY e.entry_date DESC, e.id DESC
        LIMIT :limit OFFSET :offset
    ";
    $selectStmt = $pdo->prepare($selectSql);
    foreach ($params as $key => $value) {
        $selectStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $selectStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $selectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $selectStmt->execute();
    $entries = $selectStmt->fetchAll();

    // 取得使用者名稱（用於顯示記錄者）
    $userIds = array_unique(array_column($entries, 'user_id'));
    $usernames = [];
    if (!empty($userIds)) {
        $namedPlaceholders = [];
        $uidParams = [];
        foreach ($userIds as $i => $uid) {
            $name = ':uid_' . $i;
            $namedPlaceholders[] = $name;
            $uidParams[$name] = (int) $uid;
        }
        $userStmt = $pdo->prepare("SELECT id, username FROM users WHERE id IN (" . implode(',', $namedPlaceholders) . ")");
        $userStmt->execute($uidParams);
        while ($row = $userStmt->fetch()) {
            $usernames[$row['id']] = $row['username'];
        }
    }
} catch (Throwable $e) {
    logError('search.php: 搜尋查詢失敗: ' . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="zh-TW"><head><meta charset="UTF-8"><title>系統錯誤</title><link rel="stylesheet" href="./assets/style.css?v=20261115-8"></head><body><div class="app"><div class="main"><main class="sheet"><h1>系統錯誤</h1><p>搜尋過程發生錯誤，請稍後再試。</p></main></div></div></body></html>';
    exit;
}

// 審計日誌：搜尋動作（resultCount 為總筆數）
$detail = sprintf(
    'keyword=%s amountMin=%s amountMax=%s dateFrom=%s dateTo=%s resultCount=%d',
    $keyword,
    $amountMin !== '' ? $amountMin : 'none',
    $amountMax !== '' ? $amountMax : 'none',
    $dateFrom !== '' ? $dateFrom : 'none',
    $dateTo !== '' ? $dateTo : 'none',
    $totalEntries
);
logAudit('entry_search', $detail);

// 是否有搜尋條件（用於判斷是否顯示「最近 50 筆」提示）
$hasSearchCondition = ($keyword !== '' || $amountMin !== '' || $amountMax !== '' || $dateFrom !== '' || $dateTo !== '');

// 分頁 URL 建構函數
$filterBase = [
    'keyword'    => $keyword,
    'amount_min' => $amountMin,
    'amount_max' => $amountMax,
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo,
];
$pageUrl = function (int $p) use ($filterBase) {
    $params = array_merge($filterBase, ['page' => $p]);
    return './search.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>全站記錄搜尋 - 全家人記帳系統</title>
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
                <a class="nav-item" href="./ledgers.php">新增記錄</a>
                <a class="nav-item" href="./calendar.php">行事曆檢視</a>
                <a class="nav-item active" href="./search.php">全站搜尋</a>
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
                        <h1>全站記錄搜尋</h1>
                        <p class="sub">搜尋所有可存取帳本的記錄（類別、備註、金額、日期）</p>
                    </div>
                </div>

                <!-- 搜尋表單 -->
                <form class="search-form" method="POST" action="./search.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                    <input type="hidden" name="page" value="1">

                    <div class="search-row">
                        <div class="form-group">
                            <label for="keyword">關鍵字</label>
                            <input type="text" id="keyword" name="keyword" value="<?php echo h($keyword); ?>" placeholder="類別、備註關鍵字…">
                        </div>
                        <div class="form-group">
                            <label for="amount_min">金額下限</label>
                            <input type="text" id="amount_min" name="amount_min" value="<?php echo h($amountMinRawInput); ?>" placeholder="如 100.00">
                        </div>
                        <div class="form-group">
                            <label for="amount_max">金額上限</label>
                            <input type="text" id="amount_max" name="amount_max" value="<?php echo h($amountMaxRawInput); ?>" placeholder="如 5000.00">
                        </div>
                    </div>

                    <div class="search-row">
                        <div class="form-group">
                            <label for="date_from">起始日期</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo h($dateFrom); ?>">
                        </div>
                        <div class="form-group">
                            <label for="date_to">結束日期</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo h($dateTo); ?>">
                        </div>
                    </div>

                    <div class="search-actions">
                        <button type="submit" class="btn primary">搜尋</button>
                        <a href="./search.php" class="btn secondary">重設</a>
                    </div>
                </form>

                <!-- 錯誤訊息 -->
                <?php if (!empty($errors)): ?>
                    <p class="alert error" role="alert"><?php echo h(implode(' ', $errors)); ?></p>
                <?php endif; ?>

                <!-- 結果區塊 -->
                <div class="search-result">
                    <?php if (!$hasSearchCondition && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'): ?>
                        <p class="search-hint">未輸入搜尋條件，顯示您可存取帳本的最近 50 筆記錄。</p>
                    <?php endif; ?>

                    <?php if (empty($entries)): ?>
                        <p class="search-empty">沒有符合篩選條件的記錄</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th class="ctr">傳票編號</th>
                                        <th>帳本</th>
                                        <th>類別</th>
                                        <th class="num">金額</th>
                                        <th>日期</th>
                                        <th>備註</th>
                                        <th class="ctr">記錄者</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entries as $e): ?>
                                        <?php
                                        $voucherPrefix = $e['type'] === 'income' ? '收-' : ($e['type'] === 'expense' ? '支-' : '轉-');
                                        $voucherNo = $voucherPrefix . str_pad((string) $e['id'], 3, '0', STR_PAD_LEFT);
                                        $voucherClass = $e['type'] === 'income' ? 'vno in' : ($e['type'] === 'expense' ? 'vno out' : 'vno plain');
                                        $amtClass = $e['type'] === 'income' ? 'amt-in' : ($e['type'] === 'expense' ? 'amt-out' : 'amt-neutral');
                                        $sign = $e['type'] === 'income' ? '+' : ($e['type'] === 'expense' ? '-' : '');
                                        ?>
                                        <tr>
                                            <td class="ctr"><span class="<?php echo h($voucherClass); ?>"><?php echo h($voucherNo); ?></span></td>
                                            <td><?php echo h($e['ledger_name']); ?></td>
                                            <td><?php echo h($e['category']); ?></td>
                                            <td class="num <?php echo h($amtClass); ?>"><?php echo h($sign . formatCents((int) $e['amount_cents'])); ?></td>
                                            <td><?php echo h($e['entry_date']); ?></td>
                                            <td><?php echo h($e['note']); ?></td>
                                            <td class="ctr"><?php echo h($usernames[$e['user_id']] ?? ('ID:' . $e['user_id'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- 分頁 -->
                        <?php if ($totalPages > 1): ?>
                            <nav class="search-pager" aria-label="搜尋結果分頁">
                                <span class="pager-info">第 <?php echo $page; ?> 頁，共 <?php echo $totalPages; ?> 頁（<?php echo $totalEntries; ?> 筆）</span>
                                <div class="pager-links">
                                    <?php if ($page > 1): ?>
                                        <a href="<?php echo h($pageUrl($page - 1)); ?>" class="btn secondary">上一頁</a>
                                    <?php endif; ?>
                                    <?php if ($page < $totalPages): ?>
                                        <a href="<?php echo h($pageUrl($page + 1)); ?>" class="btn secondary">下一頁</a>
                                    <?php endif; ?>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    <script src="./assets/app.js?v=20261115-8" defer></script>
</body>
</html>