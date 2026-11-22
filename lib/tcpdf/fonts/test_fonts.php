<?php
/**
 * TCPDF Font Test Script
 * 
 * Run this script to verify that TCPDF can load and use the fonts.
 * Usage: php test_fonts.php
 */

require_once __DIR__ . '/../tcpdf.php';

echo "TCPDF Font Test\n";
echo "===============\n\n";

$pdf = new \TCPDF();

// Test 1: Built-in core fonts (no external files needed)
echo "Test 1: Built-in core fonts\n";
try {
    $pdf->AddPage();
    $pdf->setFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Helvetica: Built-in core font', 0, 1);
    $pdf->setFont('times', '', 12);
    $pdf->Cell(0, 10, 'Times: Built-in core font', 0, 1);
    $pdf->setFont('courier', '', 12);
    $pdf->Cell(0, 10, 'Courier: Built-in core font', 0, 1);
    echo "✓ Core fonts work\n";
} catch (Exception $e) {
    echo "✗ Core fonts failed: " . $e->getMessage() . "\n";
}

// Test 2: DejaVu Sans (if converted)
echo "\nTest 2: DejaVu Sans Unicode font\n";
try {
    $pdf->AddPage();
    $pdf->setFont('dejavusans', '', 12);
    $pdf->Cell(0, 10, 'DejaVu Sans: Unicode font', 0, 1);
    $pdf->Cell(0, 10, '測試中文 (Chinese test)', 0, 1);
    $pdf->Cell(0, 10, 'テスト日本語 (Japanese test)', 0, 1);
    $pdf->Cell(0, 10, '테스트 한국어 (Korean test)', 0, 1);
    echo "✓ DejaVu Sans works\n";
} catch (Exception $e) {
    echo "⚠ DejaVu Sans not available (needs conversion): " . $e->getMessage() . "\n";
}

// Test 3: CID Traditional Chinese
echo "\nTest 3: CID Traditional Chinese font\n";
try {
    $pdf->AddPage();
    $pdf->setFont('cid0ct', '', 12);
    $pdf->Cell(0, 10, 'CID0CT: Traditional Chinese', 0, 1);
    $pdf->Cell(0, 10, '繁體中文測試', 0, 1);
    echo "✓ CID Traditional Chinese works\n";
} catch (Exception $e) {
    echo "⚠ CID Traditional Chinese not available (needs conversion): " . $e->getMessage() . "\n";
}

// Test 4: CID Simplified Chinese
echo "\nTest 4: CID Simplified Chinese font\n";
try {
    $pdf->AddPage();
    $pdf->setFont('cid0cs', '', 12);
    $pdf->Cell(0, 10, 'CID0CS: Simplified Chinese', 0, 1);
    $pdf->Cell(0, 10, '简体中文测试', 0, 1);
    echo "✓ CID Simplified Chinese works\n";
} catch (Exception $e) {
    echo "⚠ CID Simplified Chinese not available (needs conversion): " . $e->getMessage() . "\n";
}

// Output test PDF
$outputPath = __DIR__ . '/font_test.pdf';
try {
    $pdf->Output($outputPath, 'F');
    echo "\n✓ Test PDF generated: $outputPath\n";
    echo "  File size: " . filesize($outputPath) . " bytes\n";
} catch (Exception $e) {
    echo "\n✗ Failed to generate test PDF: " . $e->getMessage() . "\n";
}

echo "\n===============\n";
echo "Font test complete.\n";
echo "\nIf Unicode/CID fonts show warnings, run the conversion script:\n";
echo "  php convert_fonts.php\n";
echo "Or install tc-lib-pdf-font package:\n";
echo "  composer require tecnickcom/tc-lib-pdf-font\n";
echo "  php vendor/tecnickcom/tc-lib-pdf-font/util/build_fonts.php\n";