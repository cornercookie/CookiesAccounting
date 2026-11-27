<?php
// 全家人記帳系統 — 轉帳配對頁面 [#22]
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 處理 POST：配對動作
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'pair_transfer') {
    // CSRF 驗證
    $csrfRaw = $_POST['csrf_token'] ?? '';
    if (!checkCsrf(is_string($csrfRaw) ? $csrfRaw : '')) {
        redirect('./transfer_pair.php?error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
    }

    // 取得表單資料
    $id1 = filter_var($_POST['entry_id_1'] ?? '', FILTER_VALIDATE_INT);
    $id2 = filter_var($_POST['entry_id_2'] ?? '', FILTER_VALIDATE_INT);

    if ($id1 === false || $id2 === false || $id1 === $id2) {
        redirect('./transfer_pair.php?error=1&msg=' . urlencode('請選擇兩筆不同的轉帳記錄。'));
    }

    // 查詢兩筆記錄詳細資料
    $stmt = $pdo->prepare('
        SELECT e.id, e.ledger_id, e.type, e.amount_cents, e.transfer_pair_id, e.note, l.name AS ledger_name
        FROM entries e
        INNER JOIN ledgers l ON l.id = e.ledger_id
        WHERE e.id IN (:id1, :id2) AND e.deleted = 0
    ');
    $stmt->execute([':id1' => $id1, ':id2' => $id2]);
    $entries = $stmt->fetchAll();

    if (count($entries) !== 2) {
        redirect('./transfer_pair.php?error=1&msg=' . urlencode('找不到對應的轉帳記錄。'));
    }

    // 驗證類型與配對狀態
    foreach ($entries as $e) {
        if ($e['type'] !== 'transfer') {
            redirect('./transfer_pair.php?error=1&msg=' . urlencode('所選記錄必須皆為轉帳類型。'));
        }
        if ($e['transfer_pair_id'] !== null) {
            redirect('./transfer_pair.php?error=1&msg=' . urlencode('該記錄已配對，不可重複配對。'));
        }
    }

    // 驗證雙帳本存取權限 + 角色權限：目前使用者必須在「兩筆記錄所屬的每一個帳本」
    // 皆為 owner / member，且帳本均未封存（viewer 或封存帳本皆不可配對）
    $ledgerIds = array_unique(array_column($entries, 'ledger_id'));
    foreach ($ledgerIds as $lid) {
        $access = getLedgerWithAccess($pdo, (int) $lid, $userId);
        if ($access === false) {
            redirect('./transfer_pair.php?error=1&msg=' . urlencode('您沒有這兩筆記錄的存取權限。'));
        }
        if ((int) ($access['archived'] ?? 0) === 1) {
            redirect('./transfer_pair.php?error=1&msg=' . urlencode('封存帳本中的轉帳記錄無法配對。'));
        }
        if (!in_array($access['role'], ['owner', 'member'], true)) {
            redirect('./transfer_pair.php?error=1&msg=' . urlencode('您的權限不足，無法執行配對操作（需為擁有者或成員）。'));
        }
    }

    // 驗證兩筆記錄來自不同帳本（跨帳本轉帳配對）
    if (count($ledgerIds) !== 2) {
        redirect('./transfer_pair.php?error=1&msg=' . urlencode('配對的兩筆轉帳必須來自不同帳本。'));
    }

    // 執行配對（交易）
    $pdo->beginTransaction();
    try {
        // 雙向更新 transfer_pair_id（含樂觀鎖：AND transfer_pair_id IS NULL 防競態）
        $stmt = $pdo->prepare('UPDATE entries SET transfer_pair_id = :pair_id WHERE id = :id AND transfer_pair_id IS NULL');
        $stmt->execute([':pair_id' => $id2, ':id' => $id1]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('第一筆記錄已被配對或不存在');
        }
        $stmt->execute([':pair_id' => $id1, ':id' => $id2]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('第二筆記錄已被配對或不存在');
        }

        // 計算差額（整數分）
        $amtMap = [];
        foreach ($entries as $e) {
            $amtMap[$e['id']] = (int) $e['amount_cents'];
        }
        $diffCents = abs($amtMap[$id1] - $amtMap[$id2]);

        // 審計日誌
        $detail = "entry_id_1=$id1 entry_id_2=$id2 diff_cents=$diffCents";
        logAudit('transfer_pair', $detail);

        $pdo->commit();

        $msg = '轉帳配對成功。';
        if ($diffCents > 0) {
            $msg .= ' 差額 ' . formatCents($diffCents) . ' 元。';
        }
        redirect('./transfer_pair.php?success=1&msg=' . urlencode($msg));
    } catch (Throwable $e) {
        $pdo->rollBack();
        logError('transfer_pair failed: ' . $e->getMessage());
        redirect('./transfer_pair.php?error=1&msg=' . urlencode('配對失敗，請稍後再試。'));
    }
}

// 查詢使用者有存取權的帳本中，所有未配對的轉帳
$stmt = $pdo->prepare('
    SELECT e.id, e.ledger_id, l.name AS ledger_name, e.entry_date, e.type,
           e.amount_cents, e.category, e.note, e.user_id, u.username,
           la.role AS access_role
    FROM entries e
    INNER JOIN ledgers l ON l.id = e.ledger_id
    INNER JOIN ledger_access la ON la.ledger_id = l.id AND la.user_id = :user_id
    INNER JOIN users u ON u.id = e.user_id
    WHERE e.type = \'transfer\'
      AND e.deleted = 0
      AND e.transfer_pair_id IS NULL
    ORDER BY l.name, e.entry_date DESC, e.id DESC
');
$stmt->execute([':user_id' => $userId]);
$unpaired = $stmt->fetchAll();

// 依帳本分組
$byLedger = [];
foreach ($unpaired as $row) {
    $lid = (int) $row['ledger_id'];
    if (!isset($byLedger[$lid])) {
        $byLedger[$lid] = [
            'ledger_name' => $row['ledger_name'],
            'entries' => [],
        ];
    }
    $byLedger[$lid]['entries'][] = $row;
}

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$flashMsg = signedNotice();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>轉帳配對 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
    <style>
        .unpaired-group {
            margin-bottom: 1.5rem;
        }
        .unpaired-group h3 {
            margin: 0 0 0.5rem;
            font-size: 1rem;
            color: var(--text);
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.25rem;
        }
        .unpaired-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .unpaired-table th,
        .unpaired-table td {
            padding: 0.5rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }
        .unpaired-table th {
            background: var(--bg);
            font-weight: 600;
        }
        .unpaired-table .num { text-align: right; }
        .unpaired-table .amt-in { color: var(--red); }
        .unpaired-table .amt-out { color: var(--blue); }
        .pair-form {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1.5rem;
        }
        .pair-form h3 {
            margin: 0 0 1rem;
            font-size: 1rem;
        }
        .pair-form .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 600px) {
            .pair-form .form-grid {
                grid-template-columns: 1fr;
            }
        }
        .pair-form .field {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .pair-form label {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .pair-form select {
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: #fff;
            font-size: 0.9rem;
        }
        .diff-preview {
            grid-column: 1 / -1;
            padding: 0.75rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .diff-preview .warn {
            color: var(--orange);
            font-weight: 600;
        }
        .pair-form .ctr-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            grid-column: 1 / -1;
        }
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--muted);
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
                <a class="nav-item" href="./ledgers.php">新增記錄</a>
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
                        <h1>轉帳配對</h1>
                        <p class="sub"><a href="./dashboard.php">← 返回儀表板</a></p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($flashMsg !== '' ? $flashMsg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($flashMsg !== '' ? $flashMsg : '操作失敗。'); ?></p>
                <?php endif; ?>

                <!-- 未配對轉帳清單 -->
                <section aria-label="未配對轉帳清單">
                    <?php if (empty($byLedger)): ?>
                        <div class="empty-state">
                            <p>目前沒有未配對的轉帳記錄。</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($byLedger as $lid => $group): ?>
                            <div class="unpaired-group">
                                <h3><?php echo h($group['ledger_name']); ?> (帳本編號: <?php echo h(str_pad((string) $lid, 3, '0', STR_PAD_LEFT)); ?>)</h3>
                                <div class="table-wrap">
                                    <table class="unpaired-table">
                                        <thead>
                                            <tr>
                                                <th>傳票編號</th>
                                                <th>日期</th>
                                                <th>類型</th>
                                                <th class="num">金額</th>
                                                <th>類別</th>
                                                <th>備註</th>
                                                <th>記錄者</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($group['entries'] as $entry): ?>
                                                <tr>
                                                    <td><?php echo h('轉-' . str_pad((string) $entry['id'], 3, '0', STR_PAD_LEFT)); ?></td>
                                                    <td class="mono"><?php echo h($entry['entry_date']); ?></td>
                                                    <td><?php echo h($entry['type'] === 'income' ? '收入' : '支出'); ?></td>
                                                    <td class="num mono <?php echo $entry['type'] === 'income' ? 'amt-in' : 'amt-out'; ?>">
                                                        <?php echo h(($entry['type'] === 'income' ? '+' : '-') . formatCents((int) $entry['amount_cents'])); ?>
                                                    </td>
                                                    <td><?php echo h($entry['category'] ?: '—'); ?></td>
                                                    <td><?php echo h($entry['note'] ?: '—'); ?></td>
                                                    <td><?php echo h($entry['username']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <!-- 配對表單 -->
                <?php if (!empty($byLedger)): ?>
                <section class="pair-form" aria-label="轉帳配對表單">
                    <h3>建立配對</h3>
                    <form method="POST" action="./transfer_pair.php" id="pairForm">
                        <input type="hidden" name="action" value="pair_transfer">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">

                        <div class="form-grid">
                            <div class="field">
                                <label for="entry_id_1">第一筆轉帳</label>
                                <select name="entry_id_1" id="entry_id_1" required>
                                    <option value="">請選擇...</option>
                                    <?php foreach ($byLedger as $lid => $group): ?>
                                        <optgroup label="<?php echo h($group['ledger_name']); ?>">
                                            <?php foreach ($group['entries'] as $entry): ?>
                                                <option value="<?php echo (int) $entry['id']; ?>"
                                                    data-amount="<?php echo (int) $entry['amount_cents']; ?>"
                                                    data-type="<?php echo h($entry['type']); ?>">
                                                    轉-<?php echo h(str_pad((string) $entry['id'], 3, '0', STR_PAD_LEFT)); ?>
                                                    <?php echo h($entry['entry_date']); ?>
                                                    <?php echo h($entry['type'] === 'income' ? '收入' : '支出'); ?>
                                                    <?php echo h(($entry['type'] === 'income' ? '+' : '-') . formatCents((int) $entry['amount_cents'])); ?>
                                                    <?php echo h($entry['note'] ?: ''); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="entry_id_2">第二筆轉帳</label>
                                <select name="entry_id_2" id="entry_id_2" required>
                                    <option value="">請選擇...</option>
                                    <?php foreach ($byLedger as $lid => $group): ?>
                                        <optgroup label="<?php echo h($group['ledger_name']); ?>">
                                            <?php foreach ($group['entries'] as $entry): ?>
                                                <option value="<?php echo (int) $entry['id']; ?>"
                                                    data-amount="<?php echo (int) $entry['amount_cents']; ?>"
                                                    data-type="<?php echo h($entry['type']); ?>">
                                                    轉-<?php echo h(str_pad((string) $entry['id'], 3, '0', STR_PAD_LEFT)); ?>
                                                    <?php echo h($entry['entry_date']); ?>
                                                    <?php echo h($entry['type'] === 'income' ? '收入' : '支出'); ?>
                                                    <?php echo h(($entry['type'] === 'income' ? '+' : '-') . formatCents((int) $entry['amount_cents'])); ?>
                                                    <?php echo h($entry['note'] ?: ''); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="diff-preview" id="diffPreview" style="display: none;">
                                <span id="diffText"></span>
                            </div>

                            <div class="ctr-actions">
                                <button type="submit" class="btn primary" data-confirm="確定要將這兩筆轉帳記錄配對嗎？">配對</button>
                                <button type="button" class="btn secondary" id="resetPairButton">重置</button>
                            </div>
                        </div>
                    </form>
                </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="./assets/app.js?v=30"></script>
</body>
</html>