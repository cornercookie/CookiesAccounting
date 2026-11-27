<?php
// 全家人記帳系統 — 單筆傳票 HTML 列印預覽。
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

$pageTitle = '列印傳票 #' . (string) $entry['id'];
$ledgerName = $entry['ledger_name'];
$escapedLedgerName = h($ledgerName);
$subText = '可列印頁面，不是 PDF 檔。請用瀏覽器列印。';

$typeLabel = match ($entry['type']) {
    'income' => '收入',
    'expense' => '支出',
    'transfer' => '轉帳',
    default => '未知',
};

$currency = (string) ($entry['currency'] ?? 'TWD');
$amountCents = (int) $entry['amount_cents'];
$originalCents = displayOriginalCents((int) ($entry['original_amount_cents'] ?? 0), $amountCents, $currency);
$currencyLabel = h($currency);
$originalAmount = h(formatForeignAmount($originalCents, $currency));
$exchangeRate = h(formatExchangeRate((int) ($entry['exchange_rate'] ?? 10000)));
$amount = h(formatCents($amountCents));
$category = h($entry['category']);
$username = h($entry['username']);
$note = h((string) ($entry['note'] ?? ''));
$date = h($entry['entry_date']);
$entryId = h((string) $entry['id']);

$previewTime = h(date('Y-m-d H:i:s'));
$ledgerUrl = './ledger.php?ledger_id=' . h((string) $ledgerId);

$content = <<<HTML
<div class="voucher-print">
    <div class="voucher-header">
        <h2>{$escapedLedgerName} - 傳票 #{$entryId}</h2>
        <p class="voucher-meta">預覽時間：{$previewTime}</p>
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
                <th class="voucher-label">原幣別</th>
                <td class="voucher-value">{$currencyLabel}</td>
            </tr>
            <tr>
                <th class="voucher-label">原幣金額</th>
                <td class="voucher-value">{$originalAmount}</td>
            </tr>
            <tr>
                <th class="voucher-label">匯率</th>
                <td class="voucher-value">{$exchangeRate}</td>
            </tr>
            <tr>
                <th class="voucher-label">台幣金額（元）</th>
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

    <div class="voucher-actions print-page-controls">
        <button type="button" class="btn primary" data-print-page>列印</button>
        <a href="{$ledgerUrl}" class="btn secondary">返回帳本詳情</a>
    </div>
</div>
HTML;

renderPdfExportPage($user, $pageTitle, $ledgerName, $subText, $content);