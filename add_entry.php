<?php
// 全家人記帳系統 — 新增記錄（Task 2）：針對特定帳本新增一筆收支記錄
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

// 驗證帳本存取權限；寫入需為 owner 或 member（viewer 不可新增）
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
$canWrite = in_array($accessRole, ['owner', 'member'], true);

// 側欄「這本帳」：帳本編號、名稱與成員名單（含角色）
$memberStmt = $pdo->prepare('SELECT u.username, la.role FROM ledger_access la INNER JOIN users u ON u.id = la.user_id WHERE la.ledger_id = :ledger_id ORDER BY u.username');
$memberStmt->execute([':ledger_id' => $ledgerId]);
$members = $memberStmt->fetchAll();

$amount    = '';
$entryDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
$category  = '';
$note      = '';
$type      = 'expense';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf_token'] ?? '')) {
        $error = '請求驗證失敗，請重新提交表單。';
    } elseif (!$canWrite) {
        $error = '您的身份為檢視者，無法新增記錄。';
    } else {
        $amount    = trim($_POST['amount'] ?? '');
        $entryDate = trim($_POST['entry_date'] ?? '');
        $category  = trim($_POST['category'] ?? '');
        $note      = trim($_POST['note'] ?? '');
        $type      = $_POST['type'] ?? '';

        if ($amount === '' || ($cents = toCents($amount)) === false) {
            $error = '金額格式不正確（請使用整數或至多小數點後兩位，例如 100 或 100.50；不得負數）';
        } elseif ($entryDate === '' || !isValidDate($entryDate)) {
            $error = '請選擇有效的記帳日期';
        } elseif ($category === '') {
            $error = '請輸入類別';
        } elseif (!in_array($type, ['income', 'expense'], true)) {
            $error = '類別必須為收入或支出';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO entries
                    (ledger_id, user_id, amount_cents, type, category, entry_date, note, deleted)
                VALUES
                    (:ledger_id, :user_id, :amount_cents, :type, :category, :entry_date, :note, 0)
            ');
            $stmt->execute([
                ':ledger_id'   => $ledgerId,
                ':user_id'     => $userId,
                ':amount_cents' => $cents,
                ':type'        => $type,
                ':category'    => $category,
                ':entry_date'  => $entryDate,
                ':note'        => $note,
            ]);
            $newId = (int) $pdo->lastInsertId();
            redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&voucher=' . $newId . '&vtype=' . $type);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新增記錄 - 全家人記帳系統</title>
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
                        <h1>新增記錄</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong>　·　您的身份：<span class="role-tag <?php echo h($accessRole); ?>"><?php echo h($accessRole); ?></span></p>
                    </div>
                </div>

                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($error); ?></p>
                <?php endif; ?>

                <?php if (!$canWrite): ?>
                    <p class="alert error" role="alert">您的身份為檢視者，無法對此帳本新增記錄。</p>
                <?php else: ?>
                    <form method="POST" action="./add_entry.php?ledger_id=<?php echo h((string) $ledgerId); ?>" autocomplete="off" class="form">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">

                        <div class="form-grid">
                            <div class="field field-full">
                                <label for="amount" class="label">金額</label>
                                <input
                                    type="text"
                                    name="amount"
                                    id="amount"
                                    required
                                    inputmode="decimal"
                                    pattern="\d+(\.\d{1,2})?"
                                    class="input mono"
                                    value="<?php echo h($amount); ?>"
                                    placeholder="例如 100 或 100.50"
                                    style="font-size: var(--text-xl);"
                                    autofocus
                                >
                            </div>

                            <div class="field">
                                <label for="entry_date" class="label">記帳日期</label>
                                <input type="date" name="entry_date" id="entry_date" required class="input mono" value="<?php echo h($entryDate); ?>">
                            </div>

                            <div class="field">
                                <label for="type" class="label">類型</label>
                                <select name="type" id="type" class="select">
                                    <option value="expense" <?php echo $type === 'expense' ? 'selected' : ''; ?>>支出聯（藍）</option>
                                    <option value="income" <?php echo $type === 'income' ? 'selected' : ''; ?>>收入聯（紅）</option>
                                </select>
                            </div>

                            <div class="field field-full">
                                <label for="category" class="label">類別</label>
                                <input type="text" name="category" id="category" required class="input" value="<?php echo h($category); ?>" placeholder="例如 餐費、薪資">
                            </div>

                            <div class="field field-full">
                                <label for="note" class="label">備註（選填）</label>
                                <textarea name="note" id="note" rows="3" class="textarea"><?php echo h($note); ?></textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn primary btn-block">儲存記錄</button>
                    </form>
                <?php endif; ?>

                <p style="margin-top: var(--space-4);"><a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">← 返回帳本詳情</a></p>
            </main>
        </div>
    </div>
</body>
</html>
