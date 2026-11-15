<?php
// 全家人記帳系統 — 帳本詳情（Task 2）：本月收支彙總與最近 50 筆記錄
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 ledger_id（亦相容舊式 id 參數）
$ledgerIdRaw = $_GET['ledger_id'] ?? $_GET['id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（查詢 ledger_access）
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
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法檢視或新增記錄。'));
}
$accessRole = $ledger['role'];

// 本帳查詢參數：月份（預設本月 Asia/Taipei）、類別、類型、頁碼——皆為唯讀查詢
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
$ymRaw = $_GET['ym'] ?? '';
if (!is_string($ymRaw) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ymRaw)) {
    $ym = $today->format('Y-m');
} else {
    $ym = $ymRaw;
}
$monthStart = $ym . '-01';
$monthEnd   = (new DateTimeImmutable($monthStart . ' 00:00:00', new DateTimeZone('Asia/Taipei')))->format('Y-m-t');

$typeSel = $_GET['type'] ?? '';
if (!in_array($typeSel, ['', 'income', 'expense'], true)) {
    $typeSel = '';
}
$catSel = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

// 所選月份的收入／支出彙總（不含類別/類型篩選；整數分儲存，顯示除以 100）
$stmt = $pdo->prepare('
    SELECT type, SUM(amount_cents) AS total_cents
    FROM entries
    WHERE ledger_id = :ledger_id
      AND deleted = 0
      AND entry_date BETWEEN :monthStart AND :monthEnd
    GROUP BY type
');
$stmt->execute([':ledger_id' => $ledgerId, ':monthStart' => $monthStart, ':monthEnd' => $monthEnd]);
$incomeCents  = 0;
$expenseCents = 0;
while ($row = $stmt->fetch()) {
    $total = (int) $row['total_cents'];
    if ($row['type'] === 'income') {
        $incomeCents = $total;
    } elseif ($row['type'] === 'expense') {
        $expenseCents = $total;
    }
}
$balanceCents = $incomeCents - $expenseCents;

// 條件子句（預備陳述式，具名佔位符）
$where  = 'WHERE e.ledger_id = :ledger_id AND e.deleted = 0 AND e.entry_date BETWEEN :ms AND :me';
$params = [':ledger_id' => $ledgerId, ':ms' => $monthStart, ':me' => $monthEnd];
if ($typeSel !== '') {
    $where .= ' AND e.type = :type';
    $params[':type'] = $typeSel;
}
if ($catSel !== '') {
    $where .= ' AND e.category = :category';
    $params[':category'] = $catSel;
}

// 符合條件總筆數 → 分頁
$stmt = $pdo->prepare("SELECT COUNT(*) FROM entries e $where");
$stmt->execute($params);
$totalEntries = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalEntries / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT e.id, e.entry_date, e.type, e.category, e.amount_cents, e.note, u.username
    FROM entries e
    INNER JOIN users u ON u.id = e.user_id
    $where
    ORDER BY e.entry_date DESC, e.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll();

// 該帳本出現過的類別（供篩選下拉使用；查詢條件以 h() 輸出）
$catStmt = $pdo->prepare('SELECT DISTINCT category FROM entries WHERE ledger_id = :ledger_id AND deleted = 0 ORDER BY category');
$catStmt->execute([':ledger_id' => $ledgerId]);
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// 產生帶篩選的相對連結（沿用既有參數，覆寫指定鍵）
$filterBase = ['ledger_id' => $ledgerId, 'ym' => $ym];
if ($typeSel !== '') {
    $filterBase['type'] = $typeSel;
}
if ($catSel !== '') {
    $filterBase['category'] = $catSel;
}
$filterUrl = function (array $overrides = []) use ($filterBase) {
    return './ledger.php?' . http_build_query(array_merge($filterBase, $overrides));
};
$ymLabel = (new DateTimeImmutable($monthStart))->format('Y 年 n 月');
$isFiltered = ($typeSel !== '' || $catSel !== '');

// 側欄「這本帳」：帳本編號、名稱與成員名單（含角色），讓共用帳本的面孔一眼可見
$memberStmt = $pdo->prepare('SELECT u.username, la.role FROM ledger_access la INNER JOIN users u ON u.id = la.user_id WHERE la.ledger_id = :ledger_id ORDER BY u.username');
$memberStmt->execute([':ledger_id' => $ledgerId]);
$members = $memberStmt->fetchAll();

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$canWrite = in_array($accessRole, ['owner', 'member'], true);

// 傳票號碼：新增記錄成功後以 lastInsertId 導回，組出與分錄表一致的編號（收-008／支-012）
$voucherIdRaw = $_GET['voucher'] ?? null;
$voucherId = filter_var($voucherIdRaw, FILTER_VALIDATE_INT);
$voucherType = $_GET['vtype'] ?? '';
$voucherMsg = '';
if ($voucherId !== false && $voucherId !== null && $voucherId > 0 && in_array($voucherType, ['income', 'expense'], true)) {
    $voucherNo = ($voucherType === 'income' ? '收-' : '支-') . str_pad((string) $voucherId, 3, '0', STR_PAD_LEFT);
    $voucherMsg = '傳票 ' . $voucherNo . ' 已立，請核對。';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>帳本詳情 - 全家人記帳系統</title>
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
                <a class="nav-item active" href="./ledgers.php">帳本列表</a>
                <a class="nav-item" href="./change_password.php">變更密碼</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <p class="nav-h">後台</p>
                    <a class="nav-item" href="./admin/users.php">使用者管理</a>
                    <a class="nav-item" href="./admin/ledgers.php">帳本管理</a>
                <?php endif; ?>
            </nav>
            <div class="rail">
                <p class="nav-h">這本帳 No.<?php echo h(str_pad((string) $ledgerId, 3, '0', STR_PAD_LEFT)); ?></p>
                <div class="rail-name"><?php echo h($ledger['name']); ?></div>
                <ul class="rail-members">
                    <?php foreach ($members as $m): ?>
                        <li><span class="rail-user"><?php echo h($m['username']); ?></span><span class="role-chip small"><?php echo h($m['role']); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="side-foot">
                <div class="who"><b><?php echo h($user['username']); ?></b><span class="role-chip"><?php echo h($user['role']); ?></span></div>
                <a class="logout" href="./logout.php">登出</a>
            </div>
        </aside>

        <div class="main">
            <main class="sheet">
                <div class="page-head">
                    <div>
                        <h1><?php echo h($ledger['name']); ?></h1>
                        <p class="sub">您的身份：<span class="role-tag <?php echo h($accessRole); ?>"><?php echo h($accessRole); ?></span>　·　檢視：<strong><?php echo h($ymLabel); ?></strong>　·　<a href="./ledgers.php">← 返回帳本列表</a></p>
                    </div>
                    <?php if ($canWrite): ?>
                        <a href="./add_entry.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn primary">＋ 立傳票（新增記錄）</a>
                    <?php endif; ?>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($voucherMsg !== '' ? $voucherMsg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert">操作失敗：記錄不存在或您沒有該帳本的權限。</p>
                <?php endif; ?>

                <div class="plates">
                    <div class="plate in"><small><?php echo h($ymLabel); ?>收入</small><div class="v">+<?php echo h(formatCents($incomeCents)); ?></div></div>
                    <div class="plate out"><small><?php echo h($ymLabel); ?>支出</small><div class="v">-<?php echo h(formatCents($expenseCents)); ?></div></div>
                    <div class="plate net"><small><?php echo h($ymLabel); ?>結餘</small><div class="v"><?php echo h($balanceCents >= 0 ? '+' : '') . h(formatCents($balanceCents)); ?></div></div>
                </div>

                <form method="GET" action="./ledger.php" class="filters" aria-label="查帳篩選">
                    <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledgerId); ?>">
                    <label class="filter">
                        <span class="filter-label">月份</span>
                        <input type="month" name="ym" class="input mono filter-input" value="<?php echo h($ym); ?>">
                    </label>
                    <label class="filter">
                        <span class="filter-label">類別</span>
                        <select name="category" class="select filter-input">
                            <option value="">全部類別</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo h($cat); ?>"<?php echo $cat === $catSel ? ' selected' : ''; ?>><?php echo h($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="filter">
                        <span class="filter-label">類型</span>
                        <select name="type" class="select filter-input">
                            <option value="">全部</option>
                            <option value="expense"<?php echo $typeSel === 'expense' ? ' selected' : ''; ?>>支出聯（藍）</option>
                            <option value="income"<?php echo $typeSel === 'income' ? ' selected' : ''; ?>>收入聯（紅）</option>
                        </select>
                    </label>
                    <button type="submit" class="btn primary sm">查帳</button>
                    <a class="btn secondary sm" href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>">清除</a>
                </form>

                <h3><?php echo $isFiltered ? '符合條件的記錄' : '分錄記錄'; ?><span class="h3-meta">共 <?php echo h((string) $totalEntries); ?> 筆 · 每頁 50 筆 · 第 <?php echo h((string) $page); ?>/<?php echo h((string) $totalPages); ?> 頁</span></h3>
                <?php if ($entries): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>編號</th>
                                    <th>日期</th>
                                    <th>成員</th>
                                    <th>類別</th>
                                    <th class="num">金額</th>
                                    <th class="hide-m">備註</th>
                                    <th class="ctr">作廢</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><span class="vno <?php echo $entry['type'] === 'income' ? 'in' : 'out'; ?>"><?php echo $entry['type'] === 'income' ? '收' : '支'; ?>-<?php echo h(str_pad((string) $entry['id'], 3, '0', STR_PAD_LEFT)); ?></span></td>
                                        <td class="mono"><?php echo h($entry['entry_date']); ?></td>
                                        <td><?php echo h($entry['username']); ?></td>
                                        <td><?php echo h($entry['category']); ?></td>
                                        <td class="num <?php echo $entry['type'] === 'income' ? 'amt-in' : 'amt-out'; ?>"><?php echo h($entry['type'] === 'income' ? '+' : '-') . h(formatCents((int) $entry['amount_cents'])); ?></td>
                                        <td class="hide-m"><?php echo h($entry['note']); ?></td>
                                        <td class="ctr">
                                            <?php if ($canWrite): ?>
                                                <form method="POST" action="./delete_entry.php" onsubmit="return confirm('確定要作廢這筆記錄嗎？');">
                                                    <input type="hidden" name="id" value="<?php echo h((string) $entry['id']); ?>">
                                                    <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledgerId); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                    <button type="submit" class="btn danger sm">作廢</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="meta">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pager" aria-label="分頁">
                            <?php if ($page > 1): ?>
                                <a class="btn secondary sm" href="<?php echo h($filterUrl(['page' => $page - 1])); ?>">← 上一頁</a>
                            <?php else: ?><span></span><?php endif; ?>
                            <span class="pager-info">第 <?php echo h((string) $page); ?> / <?php echo h((string) $totalPages); ?> 頁</span>
                            <?php if ($page < $totalPages): ?>
                                <a class="btn secondary sm" href="<?php echo h($filterUrl(['page' => $page + 1])); ?>">下一頁 →</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="empty"><?php echo $isFiltered ? '沒有符合篩選條件的記錄。調整月份或清除篩選再試。' : '尚無記錄。按上方「立傳票」新增第一筆。'; ?></p>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>
