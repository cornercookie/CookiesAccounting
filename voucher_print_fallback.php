<?php
// 全家人記帳系統 — 單筆傳票列印降級頁面（TCPDF 載入失敗時）
// 此檔案由 export_pdf.php 透過 include 引入，依賴外部作用域變數：
// $entry: array 包含 id, entry_date, type, amount_cents, category, note, username, ledger_name
// $user: 當前使用者陣列 (currentUser() 回傳格式)
// $ledger: 帳本資料陣列 (含 name, archived, role)
//
// 若直接存取或變數未設定，回傳 404

require_once __DIR__ . '/functions.php';

// 防止直接存取或變數未設定
if (!isset($entry) || !is_array($entry) || !isset($user) || !isset($ledger)) {
    http_response_code(404);
    include __DIR__ . '/error.php';
    exit;
}

// 防止直接存取
if (!isset($entry) || !is_array($entry)) {
    http_response_code(404);
    include __DIR__ . '/error.php';
    exit;
}

$pageTitle = '列印傳票 #' . h((string) $entry['id']);
$ledgerName = h($entry['ledger_name']);
$subText = '列印模式（瀏覽器列印為 PDF）';

$typeLabel = match ($entry['type']) {
    'income' => '收入',
    'expense' => '支出',
    'transfer' => '轉帳',
    default => '未知',
};

$amount = formatCents($entry['amount_cents']);
$category = h($entry['category']);
$username = h($entry['username']);
$note = h((string) ($entry['note'] ?? ''));
$date = h($entry['entry_date']);
$entryId = h((string) $entry['id']);

$content = <<<HTML
<div class="voucher-print">
    <div class="voucher-header">
        <h1>{$ledgerName} - 傳票 #{$entryId}</h1>
        <p class="voucher-meta">列印時間：<?php echo date('Y-m-d H:i:s'); ?></p>
    </div>

    <table class="voucher-table">
        <tbody>
            <tr>
                <th class="voucher-label">傳票編號</th>
                <td class="voucher-value">{$entryId}</td>
            </tr>
            <tr>
                <th class="voucher-label">日期</th>
                <td class="voucher-value">{$date}</td>
            </tr>
            <tr>
                <th class="voucher-label">類型</th>
                <td class="voucher-value">{$typeLabel}</td>
            </tr>
            <tr>
                <th class="voucher-label">金額 (元)</th>
                <td class="voucher-value amount">{$amount}</td>
            </tr>
            <tr>
                <th class="voucher-label">分類</th>
                <td class="voucher-value">{$category}</td>
            </tr>
            <tr>
                <th class="voucher-label">記錄者</th>
                <td class="voucher-value">{$username}</td>
            </tr>
            <tr>
                <th class="voucher-label">備註</th>
                <td class="voucher-value">{$note}</td>
            </tr>
        </tbody>
    </table>

    <div class="voucher-actions">
        <button type="button" class="btn primary" onclick="window.print()">列印 / 另存為 PDF</button>
        <a href="./export_pdf.php?ledger_id=<?php echo h((string) $_GET['ledger_id']); ?>&entry=<?php echo h((string) $_GET['entry']); ?>" class="btn secondary">重試 PDF 匯出</a>
        <a href="./ledger.php?ledger_id=<?php echo h((string) $_GET['ledger_id']); ?>" class="btn secondary">返回帳本詳情</a>
    </div>
</div>
HTML;

renderPdfExportPage($user, $pageTitle, $ledgerName, $subText, $content);