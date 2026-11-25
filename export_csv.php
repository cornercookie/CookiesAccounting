<?php
// 全家人記帳系統 — 匯出 CSV/JSON 功能
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 ledger_id
$ledgerIdRaw = $_GET['ledger_id'] ?? $_GET['id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（IDOR 防護）
$stmt = $pdo->prepare('
    SELECT l.name
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.id = :ledger_id
      AND la.user_id = :user_id
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$ledger = $stmt->fetch();
if ($ledger === false) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('您無權存取此帳本。'));
}

// 解析 format 參數（csv|json，預設 csv）
$format = $_GET['format'] ?? 'csv';
$validFormats = ['csv', 'json'];
if (!in_array($format, $validFormats, true)) {
    http_response_code(400);
    include __DIR__ . '/error.php';
    exit;
}

// 解析 ym 參數（YYYY-MM，預設本月）
$ymRaw = $_GET['ym'] ?? '';
[$startDate, $endDate, $ymError] = parseYm($ymRaw);
if ($ymError !== '') {
    // 格式錯誤：HTTP 400，不輸出檔案
    http_response_code(400);
    include __DIR__ . '/error.php';
    exit;
}

// 查詢記錄（串流用 PDOStatement）
$stmt = fetchEntriesForExport($pdo, $userId, $ledgerId, $startDate, $endDate);

// 檔名安全化與 Content-Disposition
$safeLedgerName = sanitizeFilename($ledger['name']);
$dt = date('Ymd_His');
$extension = $format === 'json' ? 'json' : 'csv';
$filename = "ledger_{$ledgerId}_{$safeLedgerName}_{$dt}.{$extension}";
$contentDisposition = buildContentDisposition($filename);

// 審計日誌參數累計用
$rowCount = 0;
$exportSuccess = false;

try {
    if ($format === 'json') {
        // JSON 串流輸出
        header('Content-Type: application/json; charset=utf-8');
        header($contentDisposition);
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '[';
        $first = true;
        while ($row = $stmt->fetch()) {
            $date = $row['entry_date'];
            $type = $row['type'] === 'income' ? '收入' : ($row['type'] === 'expense' ? '支出' : '轉帳');
            // JSON 金額格式：無千分位（純數值字串），保留兩位小數
            $cents = (int) $row['amount_cents'];
            $negative = $cents < 0;
            $abs = abs($cents);
            $dollars = intdiv($abs, 100);
            $centsPart = $abs % 100;
            $amount = ($negative ? '-' : '') . $dollars . '.' . str_pad((string) $centsPart, 2, '0', STR_PAD_LEFT);
            $category = $row['category'];
            $username = $row['username'];
            $note = (string) ($row['note'] ?? '');

            $record = [
                '日期' => $date,
                '類型' => $type,
                '金額 (元)' => $amount,
                '分類' => $category,
                '記錄者' => $username,
                '備註' => $note,
            ];

            if (!$first) {
                echo ',';
            }
            echo json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = false;
            $rowCount++;
        }
        echo ']';
        $exportSuccess = true;

    } else {
        // CSV 串流輸出
        header('Content-Type: text/csv; charset=utf-8');
        header($contentDisposition);
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // 輸出 UTF-8 BOM 確保 Excel 讀取中文不亂碼
        fwrite($output, "\xEF\xBB\xBF");

        // 輸出標題列
        fputcsv($output, ['日期', '類型', '金額 (元)', '分類', '記錄者', '備註']);

        // 輸出資料列（含 CSV 公式注入防護）
        while ($row = $stmt->fetch()) {
            $date = h($row['entry_date']);
            $type = h($row['type'] === 'income' ? '收入' : ($row['type'] === 'expense' ? '支出' : '轉帳'));
            $amount = h(formatCents((int) $row['amount_cents']));
            $category = h($row['category']);
            $username = h($row['username']);
            $description = h((string) ($row['note'] ?? ''));

            // 依資安規範 SEC-03：CSV 公式注入防護 (Formula Injection)
            // 若欄位開頭為 =, +, -, @, \t, \r，強制在前方加上單引號，避免 Excel 等軟體將其解析為公式
            // 注意：在 h() 轉義之後進行，確保 HTML 特殊字元已轉義
            $formulaPrefixes = ['=', '+', '-', '@', "\t", "\r"];
            foreach ([&$date, &$type, &$category, &$username, &$description] as &$field) {
                if ($field !== '' && in_array($field[0], $formulaPrefixes, true)) {
                    $field = "'" . $field;
                }
            }
            unset($field);

            fputcsv($output, [$date, $type, $amount, $category, $username, $description]);
            $rowCount++;
        }

        fclose($output);
        $exportSuccess = true;
    }
} catch (Throwable $e) {
    logError('export_csv_failed', $e->getMessage());
    http_response_code(500);
    include __DIR__ . '/error.php';
    exit;
}

// 審計日誌：在 shutdown 中檢查 $exportSuccess 且 HTTP 200 才寫入
// 使用參考捕獲 (&$exportSuccess) 確保閉包能讀取 try 區塊中設定的 true 值
register_shutdown_function(function () use (&$exportSuccess, &$format, &$ledgerId, &$ymRaw, &$rowCount) {
    if ($exportSuccess && http_response_code() === 200) {
        $action = $format === 'json' ? 'json_export' : 'csv_export';
        $detail = json_encode([
            'ledger_id' => $ledgerId,
            'format' => $format,
            'ym' => $ymRaw ?: date('Y-m'),
            'count' => $rowCount,
        ], JSON_UNESCAPED_UNICODE);
        logAudit($action, $detail);
    }
});

exit;