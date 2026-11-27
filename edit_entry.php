<?php
// 全家人記帳系統 — 編輯記錄：修改指定帳本下的記錄，並可更換收據照片
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 ledger_id 與 entry_id
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$entryIdRaw  = $_GET['id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
$entryId  = filter_var($entryIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null || $entryId === false || $entryId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證帳本存取權限；寫入需為 owner 或 member
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
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法檢視或編輯記錄。'));
}
$accessRole = $ledger['role'];
$canWrite = in_array($accessRole, ['owner', 'member'], true);

// 側欄「這本帳」：帳本編號、名稱與成員名單（含角色）
$memberStmt = $pdo->prepare('SELECT u.username, la.role FROM ledger_access la INNER JOIN users u ON u.id = la.user_id WHERE la.ledger_id = :ledger_id ORDER BY u.username');
$memberStmt->execute([':ledger_id' => $ledgerId]);
$members = $memberStmt->fetchAll();

// 查詢現有記錄（僅未刪除、且使用者有權存取）
$stmt = $pdo->prepare('
    SELECT e.id, e.amount_cents, e.type, e.category, e.entry_date, e.note, e.receipt_path,
           e.currency, e.exchange_rate, e.original_amount_cents
    FROM entries e
    WHERE e.id = :entry_id
      AND e.ledger_id = :ledger_id
      AND e.deleted = 0
    LIMIT 1
');
$stmt->execute([':entry_id' => $entryId, ':ledger_id' => $ledgerId]);
$entry = $stmt->fetch();
if ($entry === false) {
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1');
}

$currency = (string) ($entry['currency'] ?? 'TWD');
$exchangeRate = $currency === 'TWD' ? '1.0000' : formatExchangeRate((int) ($entry['exchange_rate'] ?? 10000));
$amount = formatCentsPlain(displayOriginalCents((int) ($entry['original_amount_cents'] ?? 0), (int) $entry['amount_cents'], $currency));
$entryDate = (string) $entry['entry_date'];
$todayDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
$category  = (string) $entry['category'];
$note      = (string) $entry['note'];
$type      = (string) $entry['type'];
$error     = '';
$success   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf_token'] ?? '')) {
        $error = '請求驗證失敗，請重新提交表單。';
    } elseif (!$canWrite) {
        $error = '您的身份為檢視者，無法編輯此記錄。';
    } else {
        $amount    = trim($_POST['amount'] ?? '');
        $entryDate = trim($_POST['entry_date'] ?? '');
        $categoryPreset = trim($_POST['category_preset'] ?? '');
        if ($categoryPreset !== '' && $categoryPreset !== '__custom__') {
            $category = $categoryPreset;
        } else {
            $category = trim($_POST['category'] ?? '');
        }
        $note      = trim($_POST['note'] ?? '');
        $type      = $_POST['type'] ?? '';
        $currency  = trim($_POST['currency'] ?? 'TWD');
        $exchangeRate = trim($_POST['exchange_rate'] ?? '1.0000');

        // 後端二次驗證【後端必驗】
        if (!isSupportedCurrency($currency)) {
            $error = '不支援的幣別。';
        } elseif ($amount === '' || ($foreignCents = parseAmountCents($amount, 10)) === false) {
            $error = '原幣金額格式不正確（請使用整數或至多小數點後兩位，例如 100 或 100.50；不得負數）';
        } elseif ($entryDate === '' || !isValidDate($entryDate)) {
            $error = '請選擇有效的記帳日期';
        } elseif ($category === '') {
            $error = '請輸入類別';
        } elseif (mb_strlen($category, 'UTF-8') > 50) {
            $error = '類別長度超過 50 字元限制，請縮短後再提交。';
        } elseif (!in_array($type, ['income', 'expense', 'transfer'], true)) {
            $error = '類別必須為收入、支出或轉帳';
        } else {
            // 匯率驗證與換算
            $rateBasisPoints = null;
            if ($currency === 'TWD') {
                $rateBasisPoints = 10000; // 1.0000
                $exchangeRate = '1.0000';
            } else {
                $rateBasisPoints = parseExchangeRateBasisPoints($exchangeRate);
                if ($rateBasisPoints === false || !isValidExchangeRateBasisPoints($rateBasisPoints)) {
                    $error = '匯率格式不正確或超出範圍（0.0001 ~ 10000）。';
                }
            }

            if (!$error) {
                try {
                    $cents = convertToTwdCents($foreignCents, $rateBasisPoints);
                } catch (ValueError $e) {
                    $error = '金額換算超出範圍，請檢查金額與匯率。';
                }
            }

            if (!$error) {
            // 處理收據上傳（可更換）
            $receiptPath = (string) ($entry['receipt_path'] ?? '');
            $receiptUploadFailed = false;
            if (!empty($_FILES['receipt']['name'])) {
                $receiptUpload = handleReceiptUpload();
                if ($receiptUpload !== false) {
                    // 上傳成功：刪除舊收據實體檔（若有）
                    if ($receiptPath !== '' && is_file(__DIR__ . '/' . $receiptPath)) {
                        @unlink(__DIR__ . '/' . $receiptPath);
                    }
                    $receiptPath = $receiptUpload;
                } else {
                    $receiptUploadFailed = true;
                    $error = '收據上傳失敗：僅允許 JPEG / PNG / WebP，且檔案大小不得超過 5MB。';
                }
            }

            if (!$receiptUploadFailed) {
                $stmt = $pdo->prepare('
                    UPDATE entries
                    SET amount_cents = :amount_cents,
                        type = :type,
                        category = :category,
                        entry_date = :entry_date,
                        note = :note,
                        receipt_path = :receipt_path,
                        currency = :currency,
                        exchange_rate = :exchange_rate,
                        original_amount_cents = :original_amount_cents
                    WHERE id = :entry_id
                      AND ledger_id = :ledger_id
                      AND deleted = 0
                ');
                $stmt->execute([
                    ':amount_cents' => $cents,
                    ':type'        => $type,
                    ':category'    => $category,
                    ':entry_date'  => $entryDate,
                    ':note'        => $note,
                    ':receipt_path'=> $receiptPath,
                    ':currency'    => $currency,
                    ':exchange_rate' => $rateBasisPoints,
                    ':original_amount_cents' => $foreignCents,
                    ':entry_id'    => $entryId,
                    ':ledger_id'   => $ledgerId,
                ]);
                logAudit('entry_edit', json_encode([
                    'entry_id' => $entryId,
                    'ledger_id' => $ledgerId,
                    'type' => $type,
                    'amount_cents' => $cents,
                    'currency' => $currency,
                    'exchange_rate' => $rateBasisPoints,
                    'original_amount_cents' => $foreignCents,
                    'changed_fields' => ['currency', 'exchange_rate', 'amount_cents', 'original_amount_cents'],
                ], JSON_UNESCAPED_UNICODE));
                $_SESSION['voice_played'] = 1;
                $_SESSION['voice_text'] = ($type === 'income' ? '收入' : ($type === 'expense' ? '支出' : '轉帳')) . ' ' . formatCents($cents) . ' 元已更新';
                redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('記錄已更新。'));
            }
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>編輯記錄 - 全家人記帳系統</title>
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
                <button type="button" class="font-size-toggle" id="fontSizeToggle" title="切換大字模式">大字 A</button>
                <a class="logout" href="./logout.php">登出</a>
            </div>
        </aside>

        <div class="main">
            <main class="sheet">
                <div class="page-head">
                    <div>
                        <h1>編輯記錄</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong>　·　您的身份：<span class="role-tag <?php echo h($accessRole); ?>"><?php echo h($accessRole); ?></span></p>
                    </div>
                    <a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">← 返回帳本詳情</a>
                </div>

                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($error); ?></p>
                <?php endif; ?>
                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($success); ?></p>
                <?php endif; ?>

                <?php if (!$canWrite): ?>
                    <p class="alert error" role="alert">您的身份為檢視者，無法編輯此記錄。</p>
                <?php else: ?>
                    <form method="POST" action="./edit_entry.php?ledger_id=<?php echo h((string) $ledgerId); ?>&id=<?php echo h((string) $entryId); ?>" autocomplete="off" class="form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">

                        <div class="form-grid">
                            <div class="field field-full">
                                <label for="amount" class="label">原幣金額</label>
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
                                <label for="currency" class="label">幣別</label>
                                <select name="currency" id="currency" class="select">
                                    <option value="TWD"<?php echo $currency === 'TWD' ? ' selected' : ''; ?>>TWD（台幣）</option>
                                    <option value="USD"<?php echo $currency === 'USD' ? ' selected' : ''; ?>>USD（美金）</option>
                                    <option value="JPY"<?php echo $currency === 'JPY' ? ' selected' : ''; ?>>JPY（日圓）</option>
                                    <option value="CNY"<?php echo $currency === 'CNY' ? ' selected' : ''; ?>>CNY（人民幣）</option>
                                    <option value="EUR"<?php echo $currency === 'EUR' ? ' selected' : ''; ?>>EUR（歐元）</option>
                                    <option value="HKD"<?php echo $currency === 'HKD' ? ' selected' : ''; ?>>HKD（港幣）</option>
                                    <option value="KRW"<?php echo $currency === 'KRW' ? ' selected' : ''; ?>>KRW（韓元）</option>
                                    <option value="GBP"<?php echo $currency === 'GBP' ? ' selected' : ''; ?>>GBP（英鎊）</option>
                                </select>
                            </div>

                            <div class="field" id="exchangeRateField"<?php echo $currency === 'TWD' ? ' style="display:none;"' : ''; ?>>
                                <label for="exchange_rate" class="label">匯率（1 原幣 = 台幣）</label>
                                <input
                                    type="text"
                                    name="exchange_rate"
                                    id="exchange_rate"
                                    required
                                    inputmode="decimal"
                                    pattern="\d+(\.\d{1,4})?"
                                    class="input mono"
                                    value="<?php echo h($exchangeRate); ?>"
                                    placeholder="例如 32.5000"
                                >
                            </div>

                            <div class="field">
                                <label for="entry_date" class="label">記帳日期</label>
                                <input type="date" name="entry_date" id="entry_date" required class="input mono"
                                       value="<?php echo h($entryDate); ?>"
                                       data-today="<?php echo h($todayDate); ?>">

                                <div class="quick-date-wrapper">
                                    <button type="button" class="quick-date-btn" data-offset="0">今天</button>
                                    <button type="button" class="quick-date-btn" data-offset="-1">昨天</button>
                                </div>
                            </div>

                            <div class="field">
                                <label for="type" class="label">類型</label>
                                <select name="type" id="type" class="select">
                                    <option value="expense" <?php echo $type === 'expense' ? 'selected' : ''; ?>>支出聯（藍）</option>
                                    <option value="income" <?php echo $type === 'income' ? 'selected' : ''; ?>>收入聯（紅）</option>
                                    <option value="transfer"<?php echo $type === 'transfer' ? ' selected' : ''; ?>>轉帳</option>
                                </select>
                            </div>

                            <div class="field field-full">
                                <label for="category" class="label">類別</label>
                                <select name="category_preset" id="category_preset" class="select">
                                    <option value=""<?php echo $category === '' ? ' selected' : ''; ?>>選擇常用分類</option>
                                    <option value="餐費"<?php echo $category === '餐費' ? ' selected' : ''; ?>>餐費</option>
                                    <option value="交通"<?php echo $category === '交通' ? ' selected' : ''; ?>>交通</option>
                                    <option value="娛樂"<?php echo $category === '娛樂' ? ' selected' : ''; ?>>娛樂</option>
                                    <option value="醫療"<?php echo $category === '醫療' ? ' selected' : ''; ?>>醫療</option>
                                    <option value="薪資"<?php echo $category === '薪資' ? ' selected' : ''; ?>>薪資</option>
                                    <option value="水電瓦斯"<?php echo $category === '水電瓦斯' ? ' selected' : ''; ?>>水電瓦斯</option>
                                    <option value="日用品"<?php echo $category === '日用品' ? ' selected' : ''; ?>>日用品</option>
                                    <option value="其他"<?php echo $category === '其他' ? ' selected' : ''; ?>>其他</option>
                                    <option value="__custom__"<?php echo !in_array($category, ['', '餐費', '交通', '娛樂', '醫療', '薪資', '水電瓦斯', '日用品', '其他'], true) ? ' selected' : ''; ?>>自訂分類</option>
                                </select>
                                <input type="text" name="category" id="category" required class="input" value="<?php echo h($category); ?>" placeholder="例如 餐費、薪資" autocomplete="off">
                                <p class="meta" style="margin-top: var(--space-2);">可從常用分類選擇，或自行輸入分類（最多 50 字元）。</p>
                            </div>

                            <div class="field field-full">
                                <label for="note" class="label">備註（選填）</label>
                                <textarea name="note" id="note" rows="3" class="textarea"><?php echo h($note); ?></textarea>
                            </div>

                            <div class="field field-full">
                                <label for="receipt" class="label">收據 / 發票照片（選填，更換時會取代舊圖片）</label>
                                <input type="file" name="receipt" id="receipt" accept="image/jpeg,image/png,image/webp" class="input">
                                <p class="meta" style="margin-top: var(--space-2);">JPEG / PNG / WebP，最大 5MB。<?php if (!empty($entry['receipt_path'])): ?>目前已有收據，上傳新圖片後會取代舊圖片。<?php endif; ?></p>
                            </div>
                        </div>

                        <button type="submit" class="btn primary btn-block">儲存變更</button>
                    </form>
                <?php endif; ?>

                <p style="margin-top: var(--space-4);"><a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">← 返回帳本詳情</a></p>
            </main>
        </div>
    </div>

        <script src="./assets/app.js?v=30"></script>
</body>
</html>
