# TCPDF Fonts Directory

This directory contains font files for TCPDF PDF generation with Chinese language support.

## Directory Structure

```
fonts/
├── README.md                    # This file
├── convert_fonts.php           # Font conversion script (run on server)
├── DejaVuSans.ttf              # Source: DejaVu Sans Regular
├── DejaVuSans-Bold.ttf         # Source: DejaVu Sans Bold
├── DejaVuSans-Oblique.ttf      # Source: DejaVu Sans Oblique
├── DejaVuSans-BoldOblique.ttf  # Source: DejaVu Sans Bold Oblique
├── cid0ct.ttf                  # Source: CID Font for Traditional Chinese (12MB)
├── cid0cs.ttf                  # Source: CID Font for Simplified Chinese (12MB)
│
├── [Generated after conversion:]
├── dejavusans.php              # Font descriptor for DejaVu Sans
├── dejavusans.z                # Compressed font data
├── dejavusansb.php             # Font descriptor for DejaVu Sans Bold
├── dejavusansb.z               # Compressed font data
├── dejavusansi.php             # Font descriptor for DejaVu Sans Oblique
├── dejavusansi.z               # Compressed font data
├── dejavusansbi.php            # Font descriptor for DejaVu Sans Bold Oblique
├── dejavusansbi.z              # Compressed font data
├── cid0ct.php                  # Font descriptor for Traditional Chinese CID
├── cid0ct.z                    # Compressed font data
├── cid0cs.php                  # Font descriptor for Simplified Chinese CID
└── cid0cs.z                    # Compressed font data
```

## Font Sources

- **DejaVu Sans Family**: From `tc-font-mirror` repository (Bitstream Vera / Arev fonts)
  - License: Bitstream Vera Fonts Copyright + Arev Fonts Copyright
  - Unicode coverage: Latin, Greek, Cyrillic, and many other scripts
  - Chinese support: Limited (basic CJK Unified Ideographs)

- **CID Fonts (cid0ct, cid0cs)**: From `tc-font-mirror` repository (Unifont project)
  - License: GPL-2.0-or-later WITH Font-exception-2.0 OR OFL-1.1
  - Full Chinese character coverage (Traditional and Simplified)
  - Size: ~12MB each

## Conversion Process

### On Target Server (cPanel with PHP 8.1+)

Since this project uses TCPDF 7.0.25 (modular version with tc-lib packages), fonts must be generated using the `tc-lib-pdf-font` package.

#### Option 1: With Composer (Recommended)

```bash
# In project root
composer require tecnickcom/tc-lib-pdf-font
php vendor/tecnickcom/tc-lib-pdf-font/util/build_fonts.php
```

This will generate all font families (cid0, core, dejavu, freefont, pdfa, unifont) in `vendor/tecnickcom/tc-lib-pdf-font/target/fonts/`.

#### Option 2: Manual Conversion (This Script)

```bash
cd lib/tcpdf/fonts
php convert_fonts.php
```

Note: This requires the legacy `addTTFfont()` method which may not be available in TCPDF 7.x modular version.

#### Option 3: Pre-converted Fonts

Copy pre-converted font files from a working TCPDF installation:
- `dejavusans.php`, `dejavusans.z`, etc.
- `cid0ct.php`, `cid0ct.z`, etc.
- `cid0cs.php`, `cid0cs.z`, etc.

## Usage in PHP Code

```php
require_once './lib/tcpdf/tcpdf.php';

$pdf = new \TCPDF();

// For DejaVu Sans (Unicode, supports Chinese partially)
$pdf->setFont('dejavusans', '', 12);
$pdf->Cell(0, 10, '測試中文 DejaVu Sans', 0, 1);

// For Traditional Chinese CID font (full coverage, requires viewer support)
$pdf->setFont('cid0ct', '', 12);
$pdf->Cell(0, 10, '測試中文 CID Traditional', 0, 1);

// For Simplified Chinese CID font
$pdf->setFont('cid0cs', '', 12);
$pdf->Cell(0, 10, '测试中文 CID Simplified', 0, 1);
```

## Font Selection Guide

| Use Case | Font | Notes |
|----------|------|-------|
| General Unicode (Latin + basic CJK) | `dejavusans` | Embedded, works everywhere |
| Traditional Chinese (full coverage) | `cid0ct` | Unembedded, requires Adobe CID fonts on viewer |
| Simplified Chinese (full coverage) | `cid0cs` | Unembedded, requires Adobe CID fonts on viewer |
| Mixed content with embedding | `dejavusans` + `cid0ct` fallback | Best compatibility |

## Troubleshooting

1. **Chinese characters show as boxes/tofu**: Font not embedded or viewer lacks CID fonts. Use `dejavusans` for embedded Unicode support.

2. **Font not found error**: Run conversion script or install `tc-lib-pdf-font` package.

3. **Memory error converting CID fonts**: CID fonts are 12MB each. Increase PHP memory_limit: `ini_set('memory_limit', '256M');`

4. **Permission denied**: Ensure fonts directory is writable by web server user.

## License Compliance

All fonts are distributed under their respective licenses:
- DejaVu Sans: Bitstream Vera License + Arev License
- CID Fonts (Unifont): GPL-2.0-or-later WITH Font-exception-2.0 OR OFL-1.1

See individual LICENSE files in each font family directory after conversion.

## Version Information

- TCPDF Version: 7.0.25
- Font Source: tc-font-mirror (https://github.com/tecnickcom/tc-font-mirror)
- Last Updated: 2026-11-23