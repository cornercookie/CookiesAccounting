<?php
// 全家人記帳系統 — 匯出 CSV 功能
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
    SELECT l.name, la.role
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

// 查詢該帳本所有非作廢記錄
$stmt = $pdo->prepare('
    SELECT e.entry_date, e.type, e.amount_cents, e.category, e.note, u.username
    FROM entries e
    INNER JOIN users u ON u.id = e.user_id
    WHERE e.ledger_id = :ledger_id
      AND e.deleted = 0
    ORDER BY e.entry_date DESC, e.id DESC
');
$stmt->execute([':ledger_id' => $ledgerId]);
$entries = $stmt->fetchAll();

// 設定 CSV Headers 與 UTF-8 BOM
$safeLedgerName = preg_replace('/[^\p{L}\p{N}_-]/u', '_', $ledger['name']);
$filename = 'ledger_' . $ledgerId . '_' . $safeLedgerName . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
// RFC 5987 格式防止檔名標頭注入 (SEC-02)
$encodedFilename = rawurlencode($filename);
header("Content-Disposition: attachment; filename*=UTF-8''{$encodedFilename}");
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// 輸出 UTF-8 BOM 確保 Excel 讀取中文不亂碼
fwrite($output, "\xEF\xBB\xBF");

// 輸出標題列
fputcsv($output, ['日期', '類型', '金額 (元)', '分類', '記錄者', '備註']);

// 輸出資料列（含 CSV 公式注入防護）
try {
    foreach ($entries as $row) {
        $date = $row['entry_date'];
        $type = $row['type'] === 'income' ? '收入' : ($row['type'] === 'expense' ? '支出' : '轉帳');
        // 金額以整數分儲存，顯示時使用 formatCents() 避免浮點誤差（SPEC-01 / CODE-01）
        $amount = formatCents((int) $row['amount_cents']);
        $category = $row['category'];
        $username = $row['username'];
        $description = (string) ($row['note'] ?? '');

        // 依資安規範 SEC-03：CSV 公式注入防護 (Formula Injection)
        // 若欄位開頭為 =, +, -, @, \t, \r，強制在前方加上單引號，避免 Excel 等軟體將其解析為公式
        $formulaPrefixes = ['=', '+', '-', '@', "\t", "\r"];
        foreach ([&$date, &$type, &$category, &$username, &$description] as &$field) {
            if ($field !== '' && in_array($field[0], $formulaPrefixes, true)) {
                $field = "'" . $field;
            }
        }
        unset($field);

        fputcsv($output, [$date, $type, $amount, $category, $username, $description]);
    }
} catch (Throwable $e) {
    logError('export_csv_failed', $e->getMessage());
    http_response_code(500);
    exit;
}

// 審計日誌：記錄 CSV 匯出操作 (SPEC-01 / SEC-01)
logAudit('csv_export', 'ledger_id=' . $ledgerId . ' count=' . count($entries));

fclose($output);
exit;
