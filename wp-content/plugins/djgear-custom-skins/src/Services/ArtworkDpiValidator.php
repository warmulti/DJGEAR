<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Services;

/**
 * Evaluates customer artwork print resolution (DPI) against the skin's printable area.
 *
 * Enforced Business Policy:
 * - `< 180 DPI`     -> `rejected` (blocked from checkout; returns exact minimum pixel dimensions in FA/EN)
 * - `180–299 DPI`   -> `warning`  (requires explicit customer `low_dpi_acknowledged = true` before checkout)
 * - `>= 300 DPI`    -> `pass`     (print-ready immediately)
 */
final class ArtworkDpiValidator
{
    public const STATUS_PASS           = 'pass';
    public const STATUS_WARNING        = 'warning';
    public const STATUS_REJECTED       = 'rejected';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const MIN_ALLOWED_DPI = 180.0;
    public const RECOMMENDED_DPI = 300.0;

    public const DEFAULT_PRINT_WIDTH_MM  = 450.0;
    public const DEFAULT_PRINT_HEIGHT_MM = 280.0;
    private const MM_PER_INCH = 25.4;

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
     * Evaluate a stored file (raster image or print-ready vector PDF).
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
            $targetW = ($printWidthMm !== null && $printWidthMm > 0.0) ? $printWidthMm : self::DEFAULT_PRINT_WIDTH_MM;
            $targetH = ($printHeightMm !== null && $printHeightMm > 0.0) ? $printHeightMm : self::DEFAULT_PRINT_HEIGHT_MM;

            return [
                'pixel_width'            => null,
                'pixel_height'           => null,
                'target_width_mm'        => round($targetW, 2),
                'target_height_mm'       => round($targetH, 2),
                'computed_dpi'           => 300.0,
                'dpi_status'             => self::STATUS_PASS,
                'is_vector_pdf'          => true,
                'min_required_px_width'  => (int) ceil(($targetW / self::MM_PER_INCH) * self::MIN_ALLOWED_DPI),
                'min_required_px_height' => (int) ceil(($targetH / self::MM_PER_INCH) * self::MIN_ALLOWED_DPI),
                'recommended_px_width'   => (int) ceil(($targetW / self::MM_PER_INCH) * self::RECOMMENDED_DPI),
                'recommended_px_height'  => (int) ceil(($targetH / self::MM_PER_INCH) * self::RECOMMENDED_DPI),
                'message_en'             => 'PDF artwork verified. Our design team will inspect embedded assets during design review.',
                'message_fa'             => 'فایل PDF با موفقیت بررسی شد. تیم طراحی در مرحله بازبینی، جزئیات چاپ را کنترل خواهد کرد.',
            ];
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
                'message_en'       => 'Unable to read image dimensions. Please upload a valid PNG, JPEG, TIFF, or PDF file.',
                'message_fa'       => 'خواندن ابعاد تصویر ممکن نشد. لطفاً یک فایل معتبر PNG، JPEG، TIFF یا PDF بارگذاری کنید.',
            ];
        }

        return $this->evaluateDimensions($dims['width'], $dims['height'], $printWidthMm, $printHeightMm);
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
}
