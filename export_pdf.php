<?php
// [#8][#23] 相容入口；只提供 HTML 列印預覽。
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!array_key_exists('entry', $_GET)) {
    require __DIR__ . '/pdf_export_html_fallback.php';
    exit;
}

requireLogin();
$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 ledger_id
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

$entryId = filter_var($_GET['entry'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

// 驗證目前使用者對該帳本的存取權限（IDOR 防護）
$ledger = getLedgerWithAccess($pdo, $ledgerId, $userId);
if ($ledger === false) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('您無權存取此帳本。'));
}
if ((int) ($ledger['archived'] ?? 0) === 1) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法列印報表。'));
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$entry = $entryId === false ? false : getEntryForVoucher($pdo, $userId, $ledgerId, $entryId);
if ($entry === false) {
    http_response_code(404);
    require __DIR__ . '/error.php';
    exit;
}

logAudit('voucher_print_view', json_encode(['ledger_id' => $ledgerId, 'entry_id' => $entryId]));
require __DIR__ . '/voucher_print_fallback.php';
