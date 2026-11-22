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
$todayDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
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
        $categoryPreset = trim($_POST['category_preset'] ?? '');
        if ($categoryPreset !== '' && $categoryPreset !== '__custom__') {
            $category = $categoryPreset;
        } else {
            $category = trim($_POST['category'] ?? '');
        }
        $note      = trim($_POST['note'] ?? '');
        $type      = $_POST['type'] ?? '';

        if ($amount === '' || ($cents = toCents($amount)) === false) {
            $error = '金額格式不正確（請使用整數或至多小數點後兩位，例如 100 或 100.50；不得負數）';
        } elseif ($entryDate === '' || !isValidDate($entryDate)) {
            $error = '請選擇有效的記帳日期';
        } elseif ($category === '') {
            $error = '請輸入類別';
        } elseif (mb_strlen($category, 'UTF-8') > 50) {
            $error = '類別長度超過 50 字元限制，請縮短後再提交。';
        } elseif (!in_array($type, ['income', 'expense', 'transfer'], true)) {
            $error = '類別必須為收入、支出或轉帳';
        } else {
            // 處理收據上傳
            $receiptPath = '';
            $receiptUploadFailed = false;
            if (!empty($_FILES['receipt']['name'])) {
                $receiptUpload = handleReceiptUpload();
                if ($receiptUpload !== false) {
                    $receiptPath = $receiptUpload;
                } else {
                    // 上傳失敗：回饋使用者，不靜默繼續；記錄不寫入，保留使用者輸入以便重試
                    $receiptUploadFailed = true;
                    $error = '收據上傳失敗：僅允許 JPEG / PNG / WebP，且檔案大小不得超過 5MB。';
                }
            }

            if (!$receiptUploadFailed) {
                $stmt = $pdo->prepare('
                INSERT INTO entries
                    (ledger_id, user_id, amount_cents, type, category, entry_date, note, receipt_path, deleted)
                VALUES
                    (:ledger_id, :user_id, :amount_cents, :type, :category, :entry_date, :note, :receipt_path, 0)
            ');
                $stmt->execute([
                    ':ledger_id'   => $ledgerId,
                    ':user_id'     => $userId,
                    ':amount_cents' => $cents,
                    ':type'        => $type,
                    ':category'    => $category,
                    ':entry_date'  => $entryDate,
                    ':note'        => $note,
                    ':receipt_path' => $receiptPath,
                ]);
                $newId = (int) $pdo->lastInsertId();
                logAudit('entry_create', 'entry #' . $newId . ' · ledger ' . $ledgerId . ' · ' . $type . ' · ' . formatCents($cents));
                $_SESSION['voice_played'] = 1;
                $_SESSION['voice_text'] = ($type === 'income' ? '收入' : ($type === 'expense' ? '支出' : '轉帳')) . ' ' . formatCents($cents) . ' 元已儲存';
                redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&voucher=' . $newId . '&vtype=' . $type);
            }
        }
    }
}

/**
 * 處理收據圖片上傳（SEC-02）。
 *
 * - 檢查上傳成功、大小上限 5MB、MIME Type（finfo_file）與副檔名（jpeg/png/webp）
 * - 以 bin2hex(random_bytes(16)) 強制重新命名，禁止保留原始檔名
 * - 儲存於 private_data/uploads/，回傳相對路徑；失敗回傳 false（不中斷主流程）
 */
function handleReceiptUpload(): string|false
{
    $file = $_FILES['receipt'] ?? null;
    if (!is_array($file)) {
        return false;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return false;
    }
    if ($error !== UPLOAD_ERR_OK) {
        return false;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size    = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || !is_file($tmpName)) {
        return false;
    }
    if ($size > 5 * 1024 * 1024) {
        return false;
    }

    // MIME Type 檢查（finfo_file，不依賴 extension）
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        logError('receipt upload: finfo_open failed (fileinfo extension unavailable)');
        return false;
    }
    $mime = finfo_file($finfo, $tmpName);
    finfo_close($finfo);
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMime[$mime])) {
        return false;
    }
    $ext = $allowedMime[$mime];

    // 目標目錄
    $uploadDir = __DIR__ . '/private_data/uploads';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0770, true) && !is_dir($uploadDir)) {
        return false;
    }

    // 亂數重新命名（bin2hex(random_bytes(16)) + 副檔名）
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;
    if (!@move_uploaded_file($tmpName, $destPath)) {
        return false;
    }

    // 儲存後再次以 finfo_file 確認實際檔案內容仍為允許的圖片（防上傳期間被替換）
    $verifyMime = '';
    $verifyFinfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($verifyFinfo) {
        $verifyMime = finfo_file($verifyFinfo, $destPath);
        finfo_close($verifyFinfo);
    }
    if (!in_array($verifyMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        @unlink($destPath);
        return false;
    }

    @chmod($destPath, 0640);
    return 'private_data/uploads/' . $filename;
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
                        <h1>新增記錄</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong>　·　您的身份：<span class="role-tag <?php echo h($accessRole); ?>"><?php echo h($accessRole); ?></span></p>
                    </div>
                </div>

                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($error); ?></p>
                <?php endif; ?>

                <!-- AI 一句話記帳輔助區塊 -->
                <div class="ai-assist" role="region" aria-label="AI 一句話記帳">
                    <div class="ai-head">
                        <button type="button" class="ai-toggle" aria-expanded="false" aria-controls="ai-panel">
                            🤖 AI 一句話記帳
                        </button>
                    </div>
                    <div id="ai-panel" class="ai-panel" hidden>
                        <p class="ai-hint">說一句話或輸入文字，例如「今天中午吃麵 120 元」，AI 會自動幫你填好金額、分類與日期。</p>
                        <div class="ai-input-group">
                            <textarea id="aiText" class="ai-textarea" rows="2" placeholder="輸入收支內容……"></textarea>
                            <button type="button" id="aiVoiceBtn" class="ai-voice-btn" title="語音輸入">
                                🎤
                            </button>
                        </div>
                        <div class="ai-actions">
                            <button type="button" id="aiParseBtn" class="btn primary sm">解析並填入表單</button>
                            <button type="button" id="aiClearBtn" class="btn secondary sm">清除</button>
                            <span id="aiStatus" class="ai-status" role="status"></span>
                        </div>
                    </div>
                </div>

                <?php if (!$canWrite): ?>
                    <p class="alert error" role="alert">您的身份為檢視者，無法對此帳本新增記錄。</p>
                <?php else: ?>
                    <form method="POST" action="./add_entry.php?ledger_id=<?php echo h((string) $ledgerId); ?>" autocomplete="off" class="form" enctype="multipart/form-data">
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
                                <label for="receipt" class="label">收據 / 發票照片（選填）</label>
                                <input type="file" name="receipt" id="receipt" accept="image/jpeg,image/png,image/webp" class="input">
                                <p class="meta" style="margin-top: var(--space-2);">JPEG / PNG / WebP，最大 5MB。上傳後將與本次記錄關聯。</p>
                            </div>
                        </div>

                        <button type="submit" class="btn primary btn-block">儲存記錄</button>
                    </form>
                <?php endif; ?>

                <p style="margin-top: var(--space-4);"><a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">← 返回帳本詳情</a></p>
            </main>
        </div>
    </div>

        <script src="./assets/app.js"></script>
    <script src="./assets/ai-parse.js"></script>
</body>
</html>
