<?php
// 全家人記帳系統 — 匯出 PDF 月結單
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 ledger_id，驗證為整數
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（IDOR 防護）
$ledger = getLedgerWithAccess($pdo, $ledgerId, $userId);
if ($ledger === false) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('您無權存取此帳本。'));
}
if ((int) ($ledger['archived'] ?? 0) === 1) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法匯出報表。'));
}

// 讀取日期參數，預設為當月首日與當月最後一日（Asia/Taipei 時區）
$startDateRaw = $_GET['start_date'] ?? '';
$endDateRaw   = $_GET['end_date'] ?? '';
[$startDate, $endDate, $dateError] = parseDateRange($startDateRaw, $endDateRaw);

if ($dateError !== '') {
    redirect('./export_pdf.php?ledger_id=' . $ledgerId . '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&error=1&msg=' . urlencode($dateError));
}

// 查詢明細與彙總
[$entries, $monthlyStats, $queryError] = fetchEntriesWithStats($pdo, $userId, $ledgerId, $startDate, $endDate);

if ($queryError !== '') {
    // 查詢失敗：顯示友善錯誤頁面，不 redirect
    $content = '<p class="alert error" role="alert">' . h($queryError) . '</p>';
    renderPdfExportPage($user, '匯出 PDF 月結單', $ledger['name'], '查詢期間：' . h($startDate) . ' ～ ' . h($endDate), $content);
    exit;
}

// 計算總計（從 monthlyStats 計算，避免 cursor 耗盡問題）
$totalIncomeCents  = 0;
$totalExpenseCents = 0;
$totalTransferCents = 0;
foreach ($monthlyStats as $row) {
    $income  = (int) $row['income_cents'];
    $expense = (int) $row['expense_cents'];
    $transfer= (int) $row['transfer_cents'];
    $totalIncomeCents  += $income;
    $totalExpenseCents += $expense;
    $totalTransferCents += $transfer;
}
$totalBalanceCents = $totalIncomeCents - $totalExpenseCents;

// 無記錄時導向空白頁面
if (empty($entries)) {
    redirect('./pdf_export_empty.php?ledger_id=' . $ledgerId . '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate));
}

// 檢查 TCPDF 庫是否存在
$tcpdfPath = __DIR__ . '/lib/tcpdf/tcpdf.php';
$tcpdfAvailable = file_exists($tcpdfPath);

if (!$tcpdfAvailable) {
    // 退而求其次提供列印優化的 HTML 頁面
    header('Content-Type: text/html; charset=utf-8');
    header('Pragma: no-cache');
    header('Expires: 0');
    require_once __DIR__ . '/pdf_export_html_fallback.php';
    exit;
}

// 建立 PDF 輸出內容（先在記憶體中產生，確認成功後再輸出 HTTP 標頭）
ob_start();

try {
    // 建立 TCPDF 實例
    $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // 設定文件資訊
    $pdf->SetCreator('全家人記帳系統');
    $pdf->SetAuthor('全家人記帳系統');
    $pdf->SetTitle(h($ledger['name']) . ' - 帳本收支明細報表');
    $pdf->SetSubject('收支明細彙總報表');
    $pdf->SetKeywords('記帳, 收支, 報表, PDF');

    // 設定頁面邊距
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);

    // 設定自動分頁
    $pdf->SetAutoPageBreak(TRUE, 15);

    // 設定字型（使用內建字型，避免中文顯示問題）
    $pdf->SetFont('helvetica', '', 9);

    // 新增頁面
    $pdf->AddPage();

    // 標題
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, h($ledger['name']) . ' - 帳本收支明細報表', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, '查詢期間：' . h($startDate) . ' ～ ' . h($endDate), 0, 1, 'C');
    $pdf->Ln(5);

    // 彙總表
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, '收支彙總', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);

    $tableHeader = ['項目', '金額（元）'];
    $tableData = [
        ['收入', formatCents($totalIncomeCents)],
        ['支出', formatCents($totalExpenseCents)],
        ['轉帳', formatCents($totalTransferCents)],
        ['結餘', formatCents($totalBalanceCents)],
    ];

    $w = [120, 60];
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', 'B', 9);
    for ($i = 0; $i < count($tableHeader); $i++) {
        $pdf->Cell($w[$i], 7, h($tableHeader[$i]), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0);
    foreach ($tableData as $row) {
        $pdf->Cell($w[0], 6, h($row[0]), 1, 0, 'L');
        $pdf->Cell($w[1], 6, h($row[1]), 1, 0, 'R');
        $pdf->Ln();
    }

    $pdf->Ln(5);

    // 詳細記錄表
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, '詳細記錄', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);

    $tableHeader = ['日期', '類型', '金額（元）', '分類', '記錄者', '備註'];
    $w = [30, 30, 35, 60, 40, 105];

    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', 'B', 9);
    foreach ($tableHeader as $i => $hText) {
        $pdf->Cell($w[$i], 7, h($hText), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0);
    foreach ($entries as $row) {
        $date = $row['entry_date'];
        $type = $row['type'] === 'income' ? '收入' : ($row['type'] === 'expense' ? '支出' : '轉帳');
        $amount = formatCents($row['amount_cents']);
        $category = $row['category'];
        $username = $row['username'];
        $description = (string) ($row['note'] ?? '');

        $pdf->Cell($w[0], 6, h($date), 1, 0, 'L');
        $pdf->Cell($w[1], 6, h($type), 1, 0, 'L');
        $pdf->Cell($w[2], 6, h($amount), 1, 0, 'R');
        $pdf->Cell($w[3], 6, h($category), 1, 0, 'L');
        $pdf->Cell($w[4], 6, h($username), 1, 0, 'L');
        $pdf->Cell($w[5], 6, h($description), 1, 0, 'L');
        $pdf->Ln();
    }

    // 輸出 PDF
    $filename = 'ledger_' . $ledgerId . '_' . preg_replace('/[^\p{L}\p{N}_-]/u', '_', $ledger['name']) . '_' . date('Ymd_His') . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Pragma: no-cache');
    header('Expires: 0');

    // 匯出成功後記錄 audit_log（移到 Output 成功後）
    logAudit('pdf_export', 'ledger_id=' . $ledgerId . ' start_date=' . $startDate . ' end_date=' . $endDate);

    $pdf->Output($filename, 'D');
    exit;
} catch (Throwable $e) {
    ob_end_clean(); // 清除已緩衝的輸出
    logError('export_pdf.php PDF generation failed: ' . $e->getMessage());

    // PDF 生成失敗：顯示友善錯誤頁面
    $content = '<p class="alert error" role="alert">PDF 產生失敗，請稍後再試。</p>';
    renderPdfExportPage($user, '匯出 PDF 月結單', $ledger['name'], '查詢期間：' . h($startDate) . ' ～ ' . h($endDate), $content);
    exit;
}