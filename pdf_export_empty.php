<?php
// 全家人記帳系統 — 匯出 PDF 月結單無記錄頁面
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 讀取 ledger_id，驗證為整數
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（IDOR 防護）
$ledger = getLedgerWithAccess($pdo, $ledgerId, $userId);
if ($ledger === false) {
    redirect('./ledgers.php?error=1');
}

$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

$subText = '';
if ($startDate !== '' && $endDate !== '') {
    $subText = '查詢期間：' . h($startDate) . ' ～ ' . h($endDate);
}

$content = '<p class="alert info" role="status">該期間無記錄。</p>';

renderPdfExportPage($user, '匯出 PDF 月結單', $ledger['name'], $subText, $content);