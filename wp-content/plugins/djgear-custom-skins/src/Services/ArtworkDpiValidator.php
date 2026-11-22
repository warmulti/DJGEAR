<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Services;

/**
 * Evaluates customer artwork print resolution (DPI) against the skin's printable area.
 *
 * Enforced Business Policy:
 * - Raster images (PNG, JPEG, single-page TIFF):
 *   - `< 180 DPI`     -> `rejected` (blocked from checkout; returns exact minimum pixel dimensions in FA/EN)
 *   - `180–299 DPI`   -> `warning`  (requires explicit customer acknowledgment bound to revision ID + SHA-256)
 *   - `>= 300 DPI`    -> `pass`     (print-ready immediately)
 *   - Multi-page TIFF -> `rejected` (multi-page TIFFs are rejected so thumbnail IFDs cannot spoof resolution)
 * - PDF files:
 *   - NEVER blindly marked DPI-compliant (`pass`) without inspecting actual artwork content.
 *   - Multi-page PDF (`page_count !== 1`) -> `rejected`.
 *   - Embedded raster `/Subtype /Image` XObjects found -> evaluates the lowest effective DPI against the
 *     target print area using the exact `< 180 reject / 180–299 warning / >= 300 pass` thresholds.
 *   - Pure vector PDF or compressed object streams where raster inspection cannot certify DPI ->
 *     `manual_review_required` (explicitly NOT marked DPI-compliant; requires designer review + customer ack).
 */
final class ArtworkDpiValidator
{
    public const STATUS_PASS                   = 'pass';
    public const STATUS_WARNING                = 'warning';
    public const STATUS_REJECTED               = 'rejected';
    public const STATUS_MANUAL_REVIEW_REQUIRED = 'manual_review_required';
    public const STATUS_NOT_APPLICABLE         = 'not_applicable';

    public const MIN_ALLOWED_DPI = 180.0;
    public const RECOMMENDED_DPI = 300.0;

    public const DEFAULT_PRINT_WIDTH_MM  = 450.0;
    public const DEFAULT_PRINT_HEIGHT_MM = 280.0;
    private const MM_PER_INCH = 25.4;
    private const PDF_POINTS_PER_INCH = 72.0;

    /**
     * Evaluate raster image pixel dimensions against target print area in millimeters.
     *
     * @return array{
     *     pixel_width: int,
     *     pixel_height: int,
     *     target_width_mm: float,
     *     target_height_mm: float,
     *     computed_dpi: float,
     *     dpi_status: string,
     *     min_required_px_width: int,
     *     min_required_px_height: int,
     *     recommended_px_width: int,
     *     recommended_px_height: int,
     *     message_en: string,
     *     message_fa: string
     * }
     */
    public function evaluateDimensions(
        int $pixelWidth,
        int $pixelHeight,
        ?float $printWidthMm = null,
        ?float $printHeightMm = null,
    ): array {
        $targetW = ($printWidthMm !== null && $printWidthMm > 0.0) ? $printWidthMm : self::DEFAULT_PRINT_WIDTH_MM;
        $targetH = ($printHeightMm !== null && $printHeightMm > 0.0) ? $printHeightMm : self::DEFAULT_PRINT_HEIGHT_MM;

        $inchesW = $targetW / self::MM_PER_INCH;
        $inchesH = $targetH / self::MM_PER_INCH;

        $minPxW = (int) ceil($inchesW * self::MIN_ALLOWED_DPI);
        $minPxH = (int) ceil($inchesH * self::MIN_ALLOWED_DPI);
        $recPxW = (int) ceil($inchesW * self::RECOMMENDED_DPI);
        $recPxH = (int) ceil($inchesH * self::RECOMMENDED_DPI);

        // Evaluate both standard and 90-degree rotated orientation so rotated uploads are fairly measured.
        // Never uses user-supplied EXIF/pHYs DPI headers.
        $dpiNormal  = min($pixelWidth / $inchesW, $pixelHeight / $inchesH);
        $dpiRotated = min($pixelHeight / $inchesW, $pixelWidth / $inchesH);
        $effectiveDpi = round(max($dpiNormal, $dpiRotated), 2);

        if ($effectiveDpi < self::MIN_ALLOWED_DPI) {
            $status = self::STATUS_REJECTED;
            $messageEn = sprintf(
                'Artwork resolution is %.1f DPI (below the 180 DPI minimum). Please upload an image of at least %d×%d px (recommended %d×%d px at 300 DPI).',
                $effectiveDpi,
                $minPxW,
                $minPxH,
                $recPxW,
                $recPxH
            );
            $messageFa = sprintf(
                'کیفیت فایل ارسالی %.1f DPI است (کمتر از حداقل ۱۸۰ DPI). لطفاً تصویری با ابعاد حداقل %d×%d پیکسل (پیشنهادی %d×%d پیکسل برای ۳۰۰ DPI) بارگذاری کنید.',
                $effectiveDpi,
                $minPxW,
                $minPxH,
                $recPxW,
                $recPxH
            );
        } elseif ($effectiveDpi < self::RECOMMENDED_DPI) {
            $status = self::STATUS_WARNING;
            $messageEn = sprintf(
                'Artwork resolution is %.1f DPI (between 180 and 299 DPI). Print quality may be slightly softer than 300 DPI (%d×%d px). Please acknowledge to continue or upload a higher-resolution file.',
                $effectiveDpi,
                $recPxW,
                $recPxH
            );
            $messageFa = sprintf(
                'کیفیت فایل ارسالی %.1f DPI است (بین ۱۸۰ تا ۲۹۹ DPI). برای بهترین کیفیت چاپ، ۳۰۰ DPI (%d×%d پیکسل) توصیه می‌شود. برای ادامه، هشدار کیفیت را تأیید کنید یا فایل باکیفیت‌تری بارگذاری نمایید.',
                $effectiveDpi,
                $recPxW,
                $recPxH
            );
        } else {
            $status = self::STATUS_PASS;
            $messageEn = sprintf(
                'Artwork resolution is %.1f DPI (300+ DPI). Print-ready.',
                $effectiveDpi
            );
            $messageFa = sprintf(
                'کیفیت فایل ارسالی %.1f DPI (بالاتر از ۳۰۰ DPI) و آماده چاپ است.',
                $effectiveDpi
            );
        }

        return [
            'pixel_width'            => $pixelWidth,
            'pixel_height'           => $pixelHeight,
            'target_width_mm'        => round($targetW, 2),
            'target_height_mm'       => round($targetH, 2),
            'computed_dpi'           => $effectiveDpi,
            'dpi_status'             => $status,
            'min_required_px_width'  => $minPxW,
            'min_required_px_height' => $minPxH,
            'recommended_px_width'   => $recPxW,
            'recommended_px_height'  => $recPxH,
            'message_en'             => $messageEn,
            'message_fa'             => $messageFa,
        ];
    }

    /**
     * Evaluate a stored file (PNG, JPEG, single-page TIFF, or PDF).
     *
     * @return array<string, mixed>
     */
    public function evaluateFile(
        string $filePath,
        string $mimeType,
        ?float $printWidthMm = null,
        ?float $printHeightMm = null,
    ): array {
        if ($mimeType === 'application/pdf') {
            return $this->evaluatePdf($filePath, $printWidthMm, $printHeightMm);
        }

        if ($mimeType === 'image/tiff') {
            $pageCount = $this->countTiffPages($filePath);
            if ($pageCount !== 1) {
                $targetW = ($printWidthMm !== null && $printWidthMm > 0.0) ? $printWidthMm : self::DEFAULT_PRINT_WIDTH_MM;
                $targetH = ($printHeightMm !== null && $printHeightMm > 0.0) ? $printHeightMm : self::DEFAULT_PRINT_HEIGHT_MM;

                return [
                    'pixel_width'      => null,
                    'pixel_height'     => null,
                    'target_width_mm'  => round($targetW, 2),
                    'target_height_mm' => round($targetH, 2),
                    'page_count'       => $pageCount,
                    'computed_dpi'     => 0.0,
                    'dpi_status'       => self::STATUS_REJECTED,
                    'message_en'       => sprintf('Multi-page or unreadable TIFF detected (%d pages). Please flatten and upload a single-page TIFF, PNG, or JPEG.', $pageCount),
                    'message_fa'       => sprintf('فایل TIFF چندصفحه‌ای یا نامعتبر تشخیص داده شد (%d صفحه). لطفاً یک فایل تک‌صفحه‌ای TIFF، PNG یا JPEG بارگذاری کنید.', $pageCount),
                ];
            }
        }

        $dims = $this->extractImageDimensions($filePath);
        if ($dims === null) {
            return [
                'pixel_width'      => null,
                'pixel_height'     => null,
                'target_width_mm'  => $printWidthMm ?? self::DEFAULT_PRINT_WIDTH_MM,
                'target_height_mm' => $printHeightMm ?? self::DEFAULT_PRINT_HEIGHT_MM,
                'computed_dpi'     => 0.0,
                'dpi_status'       => self::STATUS_REJECTED,
                'message_en'       => 'Unable to read image dimensions. Please upload a valid PNG, JPEG, single-page TIFF, or single-page PDF file.',
                'message_fa'       => 'خواندن ابعاد تصویر ممکن نشد. لطفاً یک فایل معتبر PNG، JPEG، TIFF تک‌صفحه‌ای یا PDF تک‌صفحه‌ای بارگذاری کنید.',
            ];
        }

        return $this->evaluateDimensions($dims['width'], $dims['height'], $printWidthMm, $printHeightMm);
    }

    /**
     * Inspect PDF structure, page count, physical page box (TrimBox/MediaBox), and embedded raster XObjects.
     * Never silently marks an unverified PDF as DPI-compliant (`pass`).
     *
     * @return array<string, mixed>
     */
    public function evaluatePdf(
        string $filePath,
        ?float $printWidthMm = null,
        ?float $printHeightMm = null,
    ): array {
        $targetW = ($printWidthMm !== null && $printWidthMm > 0.0) ? $printWidthMm : self::DEFAULT_PRINT_WIDTH_MM;
        $targetH = ($printHeightMm !== null && $printHeightMm > 0.0) ? $printHeightMm : self::DEFAULT_PRINT_HEIGHT_MM;

        $inchesW = $targetW / self::MM_PER_INCH;
        $inchesH = $targetH / self::MM_PER_INCH;
        $minPxW = (int) ceil($inchesW * self::MIN_ALLOWED_DPI);
        $minPxH = (int) ceil($inchesH * self::MIN_ALLOWED_DPI);
        $recPxW = (int) ceil($inchesW * self::RECOMMENDED_DPI);
        $recPxH = (int) ceil($inchesH * self::RECOMMENDED_DPI);

        $raw = is_readable($filePath) ? file_get_contents($filePath) : false;
        if (!is_string($raw) || !str_starts_with(ltrim($raw), '%PDF-')) {
            return [
                'pixel_width'      => null,
                'pixel_height'     => null,
                'target_width_mm'  => round($targetW, 2),
                'target_height_mm' => round($targetH, 2),
                'computed_dpi'     => 0.0,
                'dpi_status'       => self::STATUS_REJECTED,
                'message_en'       => 'Invalid or unreadable PDF file.',
                'message_fa'       => 'فایل PDF نامعتبر یا غیرقابل خواندن است.',
            ];
        }

        $pageCount = $this->countPdfPages($filePath, $raw);
        if ($pageCount !== 1) {
            return [
                'pixel_width'            => null,
                'pixel_height'           => null,
                'target_width_mm'        => round($targetW, 2),
                'target_height_mm'       => round($targetH, 2),
                'page_count'             => $pageCount,
                'computed_dpi'           => 0.0,
                'dpi_status'             => self::STATUS_REJECTED,
                'min_required_px_width'  => $minPxW,
                'min_required_px_height' => $minPxH,
                'recommended_px_width'   => $recPxW,
                'recommended_px_height'  => $recPxH,
                'message_en'             => sprintf('PDF must contain exactly 1 page (detected %d pages). Please export a single-page print PDF.', $pageCount),
                'message_fa'             => sprintf('فایل PDF باید دقیقاً ۱ صفحه داشته باشد (%d صفحه تشخیص داده شد). لطفاً فایل تک‌صفحه‌ای ارسال کنید.', $pageCount),
            ];
        }

        $pageBoxMm = $this->extractPdfPageDimensionsMm($raw);
        $embeddedImages = $this->extractPdfEmbeddedRasterDimensions($raw);
        $hasCompressedObjStreams = preg_match('/\/Type\s*\/ObjStm\b/', $raw) === 1;

        // If we found explicit embedded raster XObjects inside the PDF, inspect the lowest-DPI raster image
        // so low-resolution bitmaps wrapped inside a PDF CANNOT bypass the 180/300 DPI policy.
        if ($embeddedImages !== []) {
            $worstReport = null;
            foreach ($embeddedImages as $img) {
                $candidate = $this->evaluateDimensions($img['width'], $img['height'], $targetW, $targetH);
                if ($worstReport === null || $candidate['computed_dpi'] < $worstReport['computed_dpi']) {
                    $worstReport = $candidate;
                }
            }

            $worstReport['is_pdf'] = true;
            $worstReport['page_count'] = 1;
            $worstReport['pdf_page_mm'] = $pageBoxMm;
            $worstReport['embedded_image_count'] = count($embeddedImages);

            // If object streams were ALSO compressed (hidden objects we could not parse) and worst visible image passed,
            // downgrade to manual_review_required rather than blindly trusting unseen objects.
            if ($hasCompressedObjStreams && $worstReport['dpi_status'] === self::STATUS_PASS) {
                $worstReport['dpi_status'] = self::STATUS_MANUAL_REVIEW_REQUIRED;
                $worstReport['requires_manual_review'] = true;
                $worstReport['message_en'] = 'PDF contains compressed object streams; manual designer preflight review is required before printing. Please acknowledge to continue.';
                $worstReport['message_fa'] = 'فایل PDF شامل آبجکت‌های فشرده است و نیاز به بررسی دستی طراح قبل از چاپ دارد. برای ادامه، بررسی دستی را تأیید کنید.';
            }

            return $worstReport;
        }

        // No embedded raster dimensions could be certified (pure vector PDF or opaque/compressed streams).
        // NEVER silently mark an unmeasured PDF as DPI-compliant (`pass`): require explicit manual review acknowledgment.
        return [
            'pixel_width'            => null,
            'pixel_height'           => null,
            'target_width_mm'        => round($targetW, 2),
            'target_height_mm'       => round($targetH, 2),
            'is_pdf'                 => true,
            'page_count'             => 1,
            'pdf_page_mm'            => $pageBoxMm,
            'embedded_image_count'   => 0,
            'requires_manual_review' => true,
            'computed_dpi'           => null,
            'dpi_status'             => self::STATUS_MANUAL_REVIEW_REQUIRED,
            'min_required_px_width'  => $minPxW,
            'min_required_px_height' => $minPxH,
            'recommended_px_width'   => $recPxW,
            'recommended_px_height'  => $recPxH,
            'message_en'             => 'PDF artwork cannot be automatically certified for raster DPI and requires manual designer preflight review. Please acknowledge manual review to proceed, or upload a 300+ DPI PNG/JPEG.',
            'message_fa'             => 'فایل PDF به‌صورت خودکار قابل سنجش قطعی DPI نیست و نیازمند بررسی دستی طراح قبل از چاپ است. برای ادامه، بررسی دستی را تأیید کنید یا فایل PNG/JPEG با کیفیت ۳۰۰ DPI بارگذاری نمایید.',
        ];
    }

    /**
     * Count TIFF pages/IFDs via Imagick AND pure-PHP binary IFD chain walker so multi-page TIFFs are always detected.
     */
    public function countTiffPages(string $filePath): int
    {
        if (!is_readable($filePath)) {
            return 0;
        }

        $binaryCount = $this->walkTiffIfdCount($filePath);
        if ($binaryCount > 1) {
            return $binaryCount;
        }

        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick();
                $im->pingImage($filePath);
                $n = $im->getNumberImages();
                $im->clear();
                $im->destroy();
                if ($n > 0) {
                    return max($binaryCount, $n);
                }
            } catch (\Throwable) {
                // Fall back to binary IFD count
            }
        }

        return $binaryCount;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public function extractImageDimensions(string $filePath): ?array
    {
        if (!is_readable($filePath)) {
            return null;
        }

        $info = @getimagesize($filePath);
        if (is_array($info) && $info[0] > 0 && $info[1] > 0) {
            return [
                'width'  => (int) $info[0],
                'height' => (int) $info[1],
            ];
        }

        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick();
                $im->pingImage($filePath);
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                $im->clear();
                $im->destroy();
                if ($w > 0 && $h > 0) {
                    return ['width' => $w, 'height' => $h];
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Pure-PHP binary TIFF IFD chain walker supporting little-endian (II) and big-endian (MM) Classic TIFF.
     */
    private function walkTiffIfdCount(string $filePath): int
    {
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) {
            return 0;
        }

        try {
            $header = fread($fp, 8);
            if (!is_string($header) || strlen($header) < 8) {
                return 0;
            }

            $endian = substr($header, 0, 2);
            if ($endian === 'II') {
                $u16 = 'v';
                $u32 = 'V';
            } elseif ($endian === 'MM') {
                $u16 = 'n';
                $u32 = 'N';
            } else {
                return 0;
            }

            $magicArr = unpack($u16, substr($header, 2, 2));
            if (!is_array($magicArr) || ($magicArr[1] ?? 0) !== 42) {
                return 0;
            }

            $offsetArr = unpack($u32, substr($header, 4, 4));
            $offset = is_array($offsetArr) ? (int) ($offsetArr[1] ?? 0) : 0;

            $pages = 0;
            $visited = [];
            while ($offset > 0 && $pages < 100 && !isset($visited[$offset])) {
                $visited[$offset] = true;
                if (fseek($fp, $offset) !== 0) {
                    break;
                }
                $countBytes = fread($fp, 2);
                if (!is_string($countBytes) || strlen($countBytes) < 2) {
                    break;
                }
                $entriesArr = unpack($u16, $countBytes);
                $numEntries = is_array($entriesArr) ? (int) ($entriesArr[1] ?? 0) : 0;
                $pages++;

                // Jump past IFD directory entries (12 bytes each) to the 4-byte next-IFD offset
                if (fseek($fp, $offset + 2 + ($numEntries * 12)) !== 0) {
                    break;
                }
                $nextBytes = fread($fp, 4);
                if (!is_string($nextBytes) || strlen($nextBytes) < 4) {
                    break;
                }
                $nextArr = unpack($u32, $nextBytes);
                $offset = is_array($nextArr) ? (int) ($nextArr[1] ?? 0) : 0;
            }

            return $pages;
        } finally {
            fclose($fp);
        }
    }

    private function countPdfPages(string $filePath, string $raw): int
    {
        $imagickCount = 0;
        // Only invoke Imagick/Ghostscript ping on PDFs that actually contain a trailer /Root catalog
        if (class_exists(\Imagick::class) && preg_match('/\/Root\s+\d+\s+\d+\s+R/', $raw) === 1) {
            try {
                $im = new \Imagick();
                $im->pingImage($filePath);
                $imagickCount = $im->getNumberImages();
                $im->clear();
                $im->destroy();
            } catch (\Throwable) {
                $imagickCount = 0;
            }
        }

        // Count `/Type /Page` dictionaries (explicitly excluding `/Type /Pages`)
        $regexCount = (int) preg_match_all('/\/Type\s*\/Page(?![a-zA-Z0-9_])/', $raw);

        // Also inspect `/Pages` `/Count N` if present
        $pagesNodeCount = 0;
        if (preg_match('/\/Type\s*\/Pages\b[^>]*\/Count\s+(\d+)/s', $raw, $m) === 1) {
            $pagesNodeCount = (int) $m[1];
        }

        return max($imagickCount, $regexCount, $pagesNodeCount);
    }

    /**
     * @return array{width_mm: float, height_mm: float}|null
     */
    private function extractPdfPageDimensionsMm(string $raw): ?array
    {
        foreach (['TrimBox', 'CropBox', 'MediaBox'] as $boxName) {
            $pattern = '/\/' . $boxName . '\s*\[\s*(-?[\d\.]+)\s+(-?[\d\.]+)\s+(-?[\d\.]+)\s+(-?[\d\.]+)\s*\]/';
            if (preg_match($pattern, $raw, $m) === 1) {
                $wPt = abs((float) $m[3] - (float) $m[1]);
                $hPt = abs((float) $m[4] - (float) $m[2]);
                if ($wPt > 0.0 && $hPt > 0.0) {
                    return [
                        'width_mm'  => round(($wPt / self::PDF_POINTS_PER_INCH) * self::MM_PER_INCH, 2),
                        'height_mm' => round(($hPt / self::PDF_POINTS_PER_INCH) * self::MM_PER_INCH, 2),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Extract embedded raster `/Subtype /Image` XObject pixel dimensions from PDF dictionaries.
     *
     * @return list<array{width: int, height: int}>
     */
    private function extractPdfEmbeddedRasterDimensions(string $raw): array
    {
        $images = [];
        if (preg_match_all('/<<(.*?)>>/s', $raw, $matches) > 0) {
            foreach ($matches[1] as $dict) {
                if (preg_match('/\/Subtype\s*\/Image\b/', $dict) !== 1) {
                    continue;
                }
                if (preg_match('/\/Width\s+(\d+)/', $dict, $wm) === 1 && preg_match('/\/Height\s+(\d+)/', $dict, $hm) === 1) {
                    $w = (int) $wm[1];
                    $h = (int) $hm[1];
                    if ($w > 0 && $h > 0) {
                        $images[] = ['width' => $w, 'height' => $h];
                    }
                }
            }
        }

        return $images;
    }
}
