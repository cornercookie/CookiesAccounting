<?php
/**
 * Generate TCPDF font files from system TTF fonts
 * Run this script to create the fonts directory
 */

require_once __DIR__ . '/tcpdf.php';

$pdf = new \TCPDF();

// Font directory
$fontDir = __DIR__ . '/fonts';
if (!is_dir($fontDir)) {
    mkdir($fontDir, 0755, true);
}

echo "Generating TCPDF fonts...\n";

// Convert Microsoft YaHei (msyi.ttf) - supports Traditional and Simplified Chinese
$fontPath = 'C:/Windows/Fonts/msyi.ttf';
if (file_exists($fontPath)) {
    echo "Converting Microsoft YaHei...\n";
    try {
        $fontName = $pdf->addTTFfont($fontPath, 'TrueTypeUnicode', '', 96, '', '', '', true);
        echo "Generated: $fontName\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Font not found: $fontPath\n";
}

// Convert SimSun (simsunb.ttf) - Traditional Chinese
$fontPath = 'C:/Windows/Fonts/simsunb.ttf';
if (file_exists($fontPath)) {
    echo "Converting SimSun...\n";
    try {
        $fontName = $pdf->addTTFfont($fontPath, 'TrueTypeUnicode', '', 96, '', '', '', true);
        echo "Generated: $fontName\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Font not found: $fontPath\n";
}

// Convert KaiU (kaiu.ttf) - Traditional Chinese
$fontPath = 'C:/Windows/Fonts/kaiu.ttf';
if (file_exists($fontPath)) {
    echo "Converting KaiU...\n";
    try {
        $fontName = $pdf->addTTFfont($fontPath, 'TrueTypeUnicode', '', 96, '', '', '', true);
        echo "Generated: $fontName\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Font not found: $fontPath\n";
}

// Convert DejaVu Sans equivalent - use Arial Unicode or similar
// We'll also create a basic DejaVu Sans compatible font using Arial
$fontPath = 'C:/Windows/Fonts/arial.ttf';
if (file_exists($fontPath)) {
    echo "Converting Arial (as DejaVu Sans substitute)...\n";
    try {
        $fontName = $pdf->addTTFfont($fontPath, 'TrueTypeUnicode', '', 96, 'dejavusans', '', '', true);
        echo "Generated: $fontName\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Font not found: $fontPath\n";
}

echo "\nFont generation complete!\n";
echo "Fonts directory: $fontDir\n";
echo "Contents:\n";
$files = scandir($fontDir);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        echo "  - $file\n";
    }
}