<?php
// 全家人記帳系統 — 匯入 CSV 明細記錄
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

// 驗證帳本存取權限；匯入為寫入操作，僅 owner 或 member 可執行（viewer 不可匯入）
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
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法匯入記錄。'));
}
$accessRole = $ledger['role'];
$canWrite = in_array($accessRole, ['owner', 'member'], true);
if (!$canWrite) {
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('您的身份為檢視者，無法匯入記錄。'));
}

$error      = '';
$success    = '';
$importResult = null; // ['success' => int, 'failed' => int, 'errors' => array]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 驗證
    if (!checkCsrf($_POST['csrf_token'] ?? '')) {
        $error = '請求驗證失敗，請重新提交表單。';
    } else {
        // 檔案上傳驗證
        $file = $_FILES['csv_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = '請選擇要匯入的 CSV 檔案。';
        } elseif ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $error = '檔案上傳失敗，請重新嘗試。';
        } else {
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size    = (int) ($file['size'] ?? 0);
            $origName = (string) ($file['name'] ?? '');

            if ($tmpName === '' || !is_file($tmpName)) {
                $error = '無法讀取上傳的檔案。';
            } elseif ($size > 2 * 1024 * 1024) {
                $error = '檔案大小超過 2MB 限制，請縮小後再上傳。';
            } else {
                // 僅允許 .csv 副檔名
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    $error = '僅允許 .csv 副檔名的檔案。';
                } else {
                    // 開始匯入
                    $successCount = 0;
                    $failedCount  = 0;
                    $failErrors   = [];

                    // 開啟 CSV 檔案，逐行讀取（不儲存於伺服器，直接由 tmp_name 讀取）
                    $handle = @fopen($tmpName, 'r');
                    if ($handle === false) {
                        $error = '無法開啟 CSV 檔案。';
                    } else {
                        $lineNum     = 0;
                        $headerSkipped = false;
                        $bomRemoved    = false;

                        while (($row = fgetcsv($handle)) !== false) {
                            $lineNum++;

                            // 第一列視為標題行並跳過
                            if (!$headerSkipped) {
                                $headerSkipped = true;
                                continue;
                            }

                            // 去除 BOM（若第一欄開頭為 BOM 字元，僅處理第一筆資料列）
                            if (!$bomRemoved && isset($row[0]) && $row[0] !== '') {
                                $row[0] = ltrim($row[0], "\xEF\xBB\xBF");
                                $bomRemoved = true;
                            }

                            // 欄位數量檢查（至少 4 個欄位：日期、類型、金額、分類）
                            if (count($row) < 4) {
                                $failedCount++;
                                $failErrors[] = '第 ' . $lineNum . ' 行：欄位不足（需至少 4 個欄位：日期、類型、金額、分類）';
                                continue;
                            }

                            // 取得欄位（記錄者第 5 欄可選，備註第 6 欄可選）
                            $dateStr   = trim((string) ($row[0] ?? ''));
                            $typeStr   = trim((string) ($row[1] ?? ''));
                            $amountStr = trim((string) ($row[2] ?? ''));
                            $category  = trim((string) ($row[3] ?? ''));
                            $recorder  = trim((string) ($row[4] ?? ''));
                            $note      = trim((string) ($row[5] ?? ''));

                            // 逐行驗證
                            $rowError = '';

                            // 日期格式（Y-m-d）
                            if ($dateStr === '' || !isValidDate($dateStr)) {
                                $rowError = '日期格式不正確（需為 Y-m-d，例如 2026-01-15）';
                            }
                            // 類型（收入/支出/轉帳）
                            elseif (!in_array($typeStr, ['收入', '支出', '轉帳'], true)) {
                                $rowError = '類型不正確（需為 收入、支出、轉帳）';
                            }

                            // 金額格式（toCents，不得為負數）
                            $cents = 0;
                            if ($rowError === '') {
                                $cents = toCents($amountStr);
                                if ($cents === false) {
                                    $rowError = '金額格式不正確（請使用整數或至多小數點後兩位，例如 100 或 100.50）';
                                } elseif ($cents < 0) {
                                    $rowError = '金額不得為負數';
                                }
                            }

                            // 分類長度（≤50 字元）
                            if ($rowError === '') {
                                if ($category === '') {
                                    $rowError = '分類不得為空';
                                } elseif (mb_strlen($category, 'UTF-8') > 50) {
                                    $rowError = '分類長度超過 50 字元限制';
                                }
                            }

                            // 記錄者：若不提供則使用目前登入使用者
                            if ($rowError === '') {
                                if ($recorder === '') {
                                    $recorder = $user['username'];
                                }
                            }

                            if ($rowError !== '') {
                                $failedCount++;
                                $failErrors[] = '第 ' . $lineNum . ' 行：' . $rowError;
                                continue;
                            }

                            // 類型轉換為資料庫值
                            $typeDb = match ($typeStr) {
                                '收入'    => 'income',
                                '支出'    => 'expense',
                                '轉帳'    => 'transfer',
                            };

                            // 寫入 entries 表（PDO 預備陳述式，具名佔位符）
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
                                ':type'        => $typeDb,
                                ':category'    => $category,
                                ':entry_date'  => $dateStr,
                                ':note'        => $note,
                            ]);
                            $successCount++;
                        }

                        fclose($handle);

                        // 記錄 audit_log（匯入成功與失敗筆數）
                        logAudit('csv_import', 'ledger_id=' . $ledgerId . ' success=' . $successCount . ' failed=' . $failedCount);

                        $importResult = [
                            'success' => $successCount,
                            'failed'  => $failedCount,
                            'errors'  => $failErrors,
                        ];

                        if ($failedCount === 0) {
                            $success = '匯入完成：共 ' . $successCount . ' 筆記錄已成功匯入。';
                        } else {
                            $success = '匯入完成：成功 ' . $successCount . ' 筆，失敗 ' . $failedCount . ' 筆。';
                        }
                    }
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
    <title>匯入 CSV - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261122-1">
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
                        <h1>匯入 CSV 明細記錄</h1>
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

                <?php if ($importResult !== null && $importResult['failed'] > 0): ?>
                    <div class="import-errors" role="region" aria-label="匯入錯誤詳情">
                        <h2>失敗記錄詳情（共 <?php echo h((string) $importResult['failed']); ?> 筆）</h2>
                        <ul class="import-error-list">
                            <?php foreach ($importResult['errors'] as $err): ?>
                                <li><?php echo h($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="./import_csv.php?ledger_id=<?php echo h((string) $ledgerId); ?>" autocomplete="off" class="form import-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">

                    <div class="form-grid">
                        <div class="field field-full">
                            <label for="csv_file" class="label">選擇 CSV 檔案</label>
                            <input type="file" name="csv_file" id="csv_file" required accept=".csv" class="input">
                            <p class="meta" style="margin-top: var(--space-2);">
                                檔案格式：UTF-8 編碼（含 BOM 或不含 BOM 均可）<br>
                                欄位順序：日期、類型、金額、分類、記錄者（選填）、備註（選填）<br>
                                日期格式：Y-m-d（例如 2026-01-15）<br>
                                類型：收入、支出、轉帳<br>
                                金額：整數或小數點後兩位（例如 100 或 100.50），不得為負數<br>
                                分類：最多 50 個字元<br>
                                記錄者：若不提供則使用目前登入使用者<br>
                                檔案大小上限 2MB，第一列為標題行會自動跳過
                            </p>
                        </div>
                    </div>

                    <button type="submit" class="btn primary btn-block">開始匯入</button>
                </form>

                <p style="margin-top: var(--space-4);"><a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">← 返回帳本詳情</a></p>
            </main>
        </div>
    </div>
</body>
</html>
