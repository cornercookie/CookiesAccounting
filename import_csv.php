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
                // 僅允許 .csv 副檔名（大小寫不敏感）
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    $error = '僅允許 .csv 副檔名的檔案。';
                } else {
                    // MIME type 驗證。沒有 fileinfo 擴充時不得呼叫 finfo_open()，否則會 500。
                    // 副檔名已確認是 .csv，此時改以 text/plain 放行並寫入 error.log。
                    $allowedMime = ['text/csv', 'text/plain', 'application/csv'];
                    $mime = '';
                    if (!function_exists('finfo_open')) {
                        logError('csv import: finfo_open failed (fileinfo extension unavailable)');
                        $mime = 'text/plain';
                    } else {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo === false) {
                            logError('csv import: finfo_open failed (fileinfo extension unavailable)');
                            $error = '系統錯誤：無法驗證檔案類型。';
                        } else {
                            $mime = finfo_file($finfo, $tmpName);
                            finfo_close($finfo);
                        }
                    }
                    if ($error === '' && !in_array($mime, $allowedMime, true)) {
                        $error = '檔案類型不允許，請上傳有效的 CSV 檔案。';
                    } elseif ($error === '') {
                            // 開始匯入
                            $successCount = 0;
                            $failedCount  = 0;
                            $errors       = [];    // 導致該行跳過的錯誤
                            $warnings     = [];    // 該行仍成功但有提示的警告

                            // 開啟 CSV 檔案，逐行讀取（不儲存於伺服器，直接由 tmp_name 讀取）
                            $handle = @fopen($tmpName, 'r');
                            if ($handle === false) {
                                $error = '無法開啟 CSV 檔案。';
                            } else {
                                $lineNum     = 0;
                                $headerSkipped = false;
                                $bomRemoved    = false;
                                $dataRowCount  = 0;              // 資料列計數（不含標題行）
                                $maxCsvRows = 10000;      // 最大資料列數，防禦 DoS

                                // CSV 公式注入防護前綴字元（同 export_csv.php）
                                $formulaPrefixes = ['=', '+', '-', '@', "\t", "\r"];
                                $stmtUser = $pdo->prepare('
                                    SELECT u.id
                                    FROM users u
                                    INNER JOIN ledger_access la ON la.user_id = u.id AND la.ledger_id = :ledger_id
                                    WHERE u.username = :username
                                    LIMIT 1
                                ');
                                $stmtInsert = $pdo->prepare('
                                    INSERT INTO entries
                                        (ledger_id, user_id, amount_cents, type, category, entry_date, note, receipt_path, deleted, recurring_source_id,
                                         currency, exchange_rate, original_amount_cents)
                                    VALUES
                                        (:ledger_id, :user_id, :amount_cents, :type, :category, :entry_date, :note, \'\', 0, NULL,
                                         :currency, :exchange_rate, :original_amount_cents)
                                ');

                                $transactionFailed = false;
                                try {
                                    $pdo->beginTransaction();
                                    while (($row = fgetcsv($handle)) !== false) {
                                    $lineNum++;

                                    // 最大資料列數限制（不含標題行），防禦超大檔 DoS
                                    if ($headerSkipped) {
                                        $dataRowCount++;
                                        if ($dataRowCount > $maxCsvRows) {
                                            $error = 'CSV 資料列數超過上限（' . $maxCsvRows . ' 筆），請拆分檔案後再匯入。';
                                            break;
                                        }
                                    }

                                    // 第一列視為標題行並跳過
                                    if (!$headerSkipped) {
                                        $headerSkipped = true;
                                        // 檢查標題行 BOM
                                        if (isset($row[0]) && str_starts_with($row[0], "\xEF\xBB\xBF")) {
                                            $row[0] = substr($row[0], 3);
                                            $bomRemoved = true;
                                        }
                                        continue;
                                    }

                                    // 去除 BOM（若標題行未處理到，則在第一筆資料列處理）
                                    if (!$bomRemoved && isset($row[0]) && $row[0] !== '') {
                                        if (str_starts_with($row[0], "\xEF\xBB\xBF")) {
                                            $row[0] = substr($row[0], 3);
                                        }
                                        $bomRemoved = true;
                                    }

                                    // 還原匯出時加上的 CSV 公式注入防護前綴
                                    foreach ($row as &$cell) {
                                        if (isset($cell[0], $cell[1]) && $cell[0] === "'" && in_array($cell[1], $formulaPrefixes, true)) {
                                            $cell = substr($cell, 1);
                                        }
                                    }
                                    unset($cell);

                                    $isExportLayout = count($row) >= 9;
                                    $currency = 'TWD';
                                    $exchangeRate = 10000;
                                    $originalAmountCents = 0;
                                    $originalAmountStr = '';
                                    $exchangeRateStr = '';
                                    if ($isExportLayout) {
                                        // 匯出格式：日期、類型、原幣別、原幣金額、匯率、台幣金額、分類、記錄者、備註
                                        $dateStr          = trim((string) ($row[0] ?? ''));
                                        $typeStr          = trim((string) ($row[1] ?? ''));
                                        $currency         = strtoupper(trim((string) ($row[2] ?? '')));
                                        if ($currency === '') {
                                            $currency = 'TWD';
                                        }
                                        $originalAmountStr = trim((string) ($row[3] ?? ''));
                                        $exchangeRateStr  = trim((string) ($row[4] ?? ''));
                                        $amountStr        = trim((string) ($row[5] ?? ''));
                                        $category         = trim((string) ($row[6] ?? ''));
                                        $recorder         = trim((string) ($row[7] ?? ''));
                                        $note             = trim((string) ($row[8] ?? ''));
                                    } else {
                                        // 舊版 6 欄位不足補空字串，超過截斷並記錄警告
                                        $fieldCount = count($row);
                                        if ($fieldCount < 6) {
                                            $row = array_pad($row, 6, '');
                                            if (count($warnings) < 100) {
                                                $warnings[] = '第 ' . $lineNum . ' 行：欄位不足 6 個（已自動補空）';
                                            }
                                        } elseif ($fieldCount > 6) {
                                            $row = array_slice($row, 0, 6);
                                            if (count($warnings) < 100) {
                                                $warnings[] = '第 ' . $lineNum . ' 行：欄位超過 6 個（已截斷多餘欄位）';
                                            }
                                        }

                                        $dateStr   = trim((string) ($row[0] ?? ''));
                                        $typeStr   = trim((string) ($row[1] ?? ''));
                                        $amountStr = trim((string) ($row[2] ?? ''));
                                        $category  = trim((string) ($row[3] ?? ''));
                                        $recorder  = trim((string) ($row[4] ?? ''));
                                        $note      = trim((string) ($row[5] ?? ''));
                                    }

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

                                    if ($rowError === '' && !isSupportedCurrency($currency)) {
                                        $rowError = '不支援的幣別。';
                                    }

                                    // 金額格式（接受匯出值的千分位，不得為負數或零）
                                    $cents = 0;
                                    if ($rowError === '') {
                                        $cents = toCents(str_replace(',', '', $amountStr));
                                        if ($cents === false) {
                                            $rowError = '金額格式不正確（請使用整數或至多小數點後兩位，例如 100 或 100.50）';
                                        } elseif ($cents < 0) {
                                            $rowError = '金額不得為負數';
                                        } elseif ($cents === 0) {
                                            $rowError = '金額必須大於 0';
                                        }
                                    }

                                    // 台幣與舊版格式固定使用原幣等值及 1.0000 匯率
                                    if ($rowError === '') {
                                        $originalAmountCents = $cents;
                                        if ($isExportLayout && $currency !== 'TWD') {
                                            $originalAmountCents = toCents((string) preg_replace(
                                                '/^[^0-9.]*/',
                                                '',
                                                str_replace(',', '', $originalAmountStr)
                                            ));
                                            if ($originalAmountCents === false) {
                                                $rowError = '原幣金額格式不正確（請使用整數或至多小數點後兩位）';
                                            } else {
                                                $exchangeRate = parseExchangeRateBasisPoints($exchangeRateStr);
                                                if ($exchangeRate === false || !isValidExchangeRateBasisPoints($exchangeRate)) {
                                                    $rowError = '匯率格式不正確或超出範圍（0.0001 ~ 10000）。';
                                                }
                                            }
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

                                    // 記錄者必須有此帳本存取權；不存在、無權或空值 → 使用目前登入使用者
                                    $entryUserId = $userId; // 預設為登入使用者
                                    if ($rowError === '') {
                                        if ($recorder !== '') {
                                            $stmtUser->execute([':username' => $recorder, ':ledger_id' => $ledgerId]);
                                            $userRow = $stmtUser->fetch();
                                            if ($userRow !== false) {
                                                $entryUserId = (int) $userRow['id'];
                                            } else {
                                                // 記錄者不存在，使用登入使用者（不視為錯誤，只記錄警告，受 100 筆限制）
                                                if (count($warnings) < 100) {
                                                    $warnings[] = '第 ' . $lineNum . ' 行：記錄者 "' . $recorder . '" 不存在，改用目前登入使用者';
                                                }
                                            }
                                        }
                                    }

                                    if ($rowError !== '') {
                                        $failedCount++;
                                        // 失敗原因最多記錄 100 筆（超過則不再加入陣列，但仍繼續計數 failedCount）
                                        if (count($errors) < 100) {
                                            $errors[] = '第 ' . $lineNum . ' 行：' . $rowError;
                                        } elseif (count($errors) === 100) {
                                            // 第 101 筆錯誤時，加入略過提示並停止記錄詳細錯誤
                                            $errors[] = '... 其餘錯誤略過（超過 100 筆上限）';
                                        }
                                        continue;
                                    }

                                    // 類型轉換為資料庫值
                                    $typeDb = match ($typeStr) {
                                        '收入'    => 'income',
                                        '支出'    => 'expense',
                                        '轉帳'    => 'transfer',
                                    };

                                    // 寫入 entries 表（含多幣別欄位）
                                    $stmtInsert->execute([
                                        ':ledger_id'             => $ledgerId,
                                        ':user_id'               => $entryUserId,
                                        ':amount_cents'          => $cents,
                                        ':type'                  => $typeDb,
                                        ':category'              => $category,
                                        ':entry_date'            => $dateStr,
                                        ':note'                  => $note,
                                        ':currency'              => $currency,
                                        ':exchange_rate'         => $exchangeRate,
                                        ':original_amount_cents' => $originalAmountCents,
                                    ]);
                                    $successCount++;
                                    }
                                    $pdo->commit();
                                } catch (Throwable $e) {
                                    $transactionFailed = true;
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                    logError('csv_import_failed: ' . $e->getMessage());
                                    $error = '匯入失敗，請稍後再試。';
                                }

                                fclose($handle);

                                if (!$transactionFailed) {
                                    // 記錄 audit_log（匯入成功與失敗筆數）
                                    logAudit('csv_import', 'ledger_id=' . $ledgerId . ' success=' . $successCount . ' failed=' . $failedCount);

                                    $importResult = [
                                        'success'   => $successCount,
                                        'failed'    => $failedCount,
                                        'errors'    => $errors,
                                        'warnings'  => $warnings,
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

                <?php if ($importResult !== null && ($importResult['failed'] > 0 || !empty($importResult['warnings']))): ?>
                    <?php if ($importResult['failed'] > 0): ?>
                        <div class="import-errors" role="region" aria-label="匯入錯誤詳情">
                            <h2>失敗記錄詳情（共 <?php echo h((string) $importResult['failed']); ?> 筆）</h2>
                            <ul class="import-error-list">
                                <?php foreach ($importResult['errors'] as $err): ?>
                                    <li><?php echo h($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($importResult['warnings'])): ?>
                        <div class="import-warnings" role="region" aria-label="匯入警告詳情">
                            <h2>警告（共 <?php echo h((string) count($importResult['warnings'])); ?> 筆，不影響匯入）</h2>
                            <ul class="import-error-list">
                                <?php foreach ($importResult['warnings'] as $warn): ?>
                                    <li><?php echo h($warn); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
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
