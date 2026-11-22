<?php
/**
 * TCPDF Font Conversion Script
 * 
 * This script converts TTF font files to TCPDF format (PHP descriptor + compressed .z files).
 * Run this script on the target server (cPanel with PHP 8.1+) to generate the font files.
 * 
 * Usage: php convert_fonts.php
 * 
 * Requirements:
 * - PHP 8.1+
 * - TCPDF library (../tcpdf.php)
 * - tc-lib-pdf-font package (if available via Composer)
 * 
 * Source fonts (already downloaded):
 * - DejaVuSans.ttf, DejaVuSans-Bold.ttf, DejaVuSans-Oblique.ttf, DejaVuSans-BoldOblique.ttf
 * - cid0ct.ttf (Traditional Chinese), cid0cs.ttf (Simplified Chinese)
 * 
 * Output:
 * - PHP descriptor files (e.g., dejavusans.php, dejavusansb.php, etc.)
 * - Compressed font data files (e.g., dejavusans.z, dejavusansb.z, etc.)
 * - CID font files for Chinese support
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from command line.\n");
    exit(1);
}

echo "TCPDF Font Conversion Script\n";
echo "============================\n\n";

// Load TCPDF
require_once __DIR__ . '/../tcpdf.php';

// Font directory
$fontDir = __DIR__;
echo "Font directory: $fontDir\n\n";

// Source fonts to convert
$sourceFonts = [
    // DejaVu Sans family (Unicode support)
    'DejaVuSans.ttf' => ['name' => 'dejavusans', 'type' => 'TrueTypeUnicode'],
    'DejaVuSans-Bold.ttf' => ['name' => 'dejavusansb', 'type' => 'TrueTypeUnicode'],
    'DejaVuSans-Oblique.ttf' => ['name' => 'dejavusansi', 'type' => 'TrueTypeUnicode'],
    'DejaVuSans-BoldOblique.ttf' => ['name' => 'dejavusansbi', 'type' => 'TrueTypeUnicode'],
    // CID fonts for Chinese (Traditional and Simplified)
    'cid0ct.ttf' => ['name' => 'cid0ct', 'type' => 'cidfont0'],
    'cid0cs.ttf' => ['name' => 'cid0cs', 'type' => 'cidfont0'],
];

$pdf = new \TCPDF();
$converted = 0;
$errors = 0;

foreach ($sourceFonts as $fontFile => $options) {
    $fontPath = $fontDir . '/' . $fontFile;
    
    if (!file_exists($fontPath)) {
        echo "⚠ Source font not found: $fontFile\n";
        $errors++;
        continue;
    }
    
    echo "Converting: $fontFile ... ";
    
    try {
        // Use TCPDF's addTTFfont method if available (legacy TCPDF)
        // For newer TCPDF with tc-lib-pdf-font, we need a different approach
        if (method_exists($pdf, 'addTTFfont')) {
            $fontName = $pdf->addTTFfont(
                $fontPath,
                $options['type'],
                '',  // encoding (empty for Unicode)
                96,  // dpi
                $options['name'],  // font name
                '',  // subset
                '',  // charlist
                true  // overwrite
            );
            echo "✓ Generated: $fontName\n";
            $converted++;
        } else {
            // For newer TCPDF (7.x with tc-lib-pdf-font), fonts are handled differently
            // The font files need to be pre-converted using tc-lib-pdf-font's build_fonts.php
            echo "⚠ TCPDF 7.x requires tc-lib-pdf-font package for font conversion.\n";
            echo "  Please run: php vendor/tecnickcom/tc-lib-pdf-font/util/build_fonts.php\n";
            echo "  Or install via Composer: composer require tecnickcom/tc-lib-pdf-font\n";
            $errors++;
            break;
        }
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n============================\n";
echo "Conversion complete.\n";
echo "Converted: $converted\n";
echo "Errors: $errors\n";

if ($errors > 0) {
    echo "\nNote: For TCPDF 7.0.25 (modular version), fonts must be generated using:\n";
    echo "  php vendor/tecnickcom/tc-lib-pdf-font/util/build_fonts.php\n";
    echo "This requires Composer to install the tc-lib-pdf-font package.\n";
    echo "\nAlternative: Use the legacy TCPDF font utilities or pre-converted fonts.\n";
    exit(1);
}

echo "\nFont files generated successfully in: $fontDir\n";
exit(0);