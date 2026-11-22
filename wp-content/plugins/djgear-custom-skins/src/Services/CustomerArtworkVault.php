<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Services;

use DJGear\CustomSkins\Domain\SkinValidationException;

/**
 * Private file vault for customer artwork uploads and designer proof previews.
 *
 * Security Guarantees:
 * - Stored strictly under `/wp-content/uploads/djgear-private/customer-artwork/{YYYY}/{MM}/{job_uuid}/{uuid}.{ext}`
 *   which is denied by Nginx (`location ^~ /wp-content/uploads/djgear-private/ { deny all; }`),
 *   plus `.htaccess` and `index.php` defense-in-depth.
 * - Magic-byte MIME verification via `finfo_file` (never trusts client MIME or filename extension).
 * - Explicitly blocks PHP, HTML, JS, ZIP, and raw SVG uploads.
 * - Enforces 25 MiB max file size and 15000x15000 decompression bomb limit.
 * - Computes SHA-256 hash and enforces `realpath()` containment on every read.
 */
final class CustomerArtworkVault
{
    public const MAX_FILE_SIZE_BYTES = 26214400; // 25 MiB
    public const MAX_IMAGE_DIMENSION = 15000;

    /** @var array<string, string> Allowed MIME -> canonical extension */
    public const ALLOWED_MIME_EXTENSIONS = [
        'image/png'       => 'png',
        'image/jpeg'      => 'jpg',
        'image/tiff'      => 'tif',
        'application/pdf' => 'pdf',
    ];

    /** @var array<string, string> Allowed MIME for designer proofs */
    public const ALLOWED_PROOF_MIME_EXTENSIONS = [
        'image/png'       => 'png',
        'image/jpeg'      => 'jpg',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly ?string $customVaultBaseDir = null,
    ) {
    }

    /**
     * Store a validated file into the private vault and return immutable metadata.
     *
     * @param string $sourcePath Local temporary upload path
     * @param string $originalFilename Client-supplied filename (used only as sanitized display label)
     * @param string $jobUuid Canonical job UUID
     * @param bool   $isProof True for designer proof previews, false for customer artwork
     * @return array{
     *     storage_rel_path: string,
     *     absolute_path: string,
     *     original_filename: string,
     *     mime_type: string,
     *     file_size_bytes: int,
     *     sha256_hash: string,
     *     pixel_width: ?int,
     *     pixel_height: ?int
     * }
     */
    public function storeFile(
        string $sourcePath,
        string $originalFilename,
        string $jobUuid,
        bool $isProof = false,
    ): array {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw SkinValidationException::badRequest('Uploaded file could not be read.', 'djgear_skins_unreadable_file');
        }

        $fileSize = filesize($sourcePath);
        if ($fileSize === false || $fileSize <= 0) {
            throw SkinValidationException::badRequest('Uploaded file is empty.', 'djgear_skins_empty_file');
        }
        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw SkinValidationException::badRequest(
                'Uploaded file exceeds the maximum allowed size of 25 MiB.',
                'djgear_skins_file_too_large',
                ['max_bytes' => self::MAX_FILE_SIZE_BYTES]
            );
        }

        $detectedMime = $this->detectMimeType($sourcePath);
        $allowedMap = $isProof ? self::ALLOWED_PROOF_MIME_EXTENSIONS : self::ALLOWED_MIME_EXTENSIONS;

        if (!isset($allowedMap[$detectedMime])) {
            throw SkinValidationException::badRequest(
                sprintf(
                    'Unsupported or unsafe file type "%s". Allowed types: %s.',
                    $detectedMime,
                    implode(', ', array_keys($allowedMap))
                ),
                'djgear_skins_disallowed_mime',
                ['detected_mime' => $detectedMime, 'allowed' => array_keys($allowedMap)]
            );
        }

        // Extra defense against polyglot files containing PHP tags or raw SVG/script payloads (head 64 KiB + tail 4 KiB)
        $head = (string) file_get_contents($sourcePath, false, null, 0, 65536);
        $tail = $fileSize > 65536 ? (string) file_get_contents($sourcePath, false, null, max(0, $fileSize - 4096), 4096) : '';
        $sample = $head . "\n" . $tail;
        if (stripos($sample, '<?php') !== false || stripos($sample, '<svg') !== false || stripos($sample, '<script') !== false) {
            throw SkinValidationException::badRequest(
                'File contains disallowed script or markup signatures.',
                'djgear_skins_unsafe_payload'
            );
        }

        if ($detectedMime === 'application/pdf' && !str_starts_with(ltrim($head), '%PDF-')) {
            throw SkinValidationException::badRequest(
                'File does not have a valid PDF header.',
                'djgear_skins_invalid_pdf'
            );
        }

        $pixelWidth = null;
        $pixelHeight = null;
        if (str_starts_with($detectedMime, 'image/')) {
            $validator = new ArtworkDpiValidator();
            if ($detectedMime === 'image/tiff') {
                $tiffPages = $validator->countTiffPages($sourcePath);
                if ($tiffPages !== 1) {
                    throw SkinValidationException::badRequest(
                        sprintf('Multi-page or unreadable TIFF detected (%d pages). Only single-page TIFF files are accepted.', $tiffPages),
                        'djgear_skins_multipage_tiff_unsupported',
                        ['page_count' => $tiffPages]
                    );
                }
            }
            $dims = $validator->extractImageDimensions($sourcePath);
            if ($dims === null) {
                throw SkinValidationException::badRequest(
                    'Unable to decode raster image dimensions.',
                    'djgear_skins_corrupt_image'
                );
            }
            if ($dims['width'] > self::MAX_IMAGE_DIMENSION || $dims['height'] > self::MAX_IMAGE_DIMENSION) {
                throw SkinValidationException::badRequest(
                    sprintf('Image exceeds maximum allowed dimensions (%d×%d px).', self::MAX_IMAGE_DIMENSION, self::MAX_IMAGE_DIMENSION),
                    'djgear_skins_image_dimensions_exceeded'
                );
            }
            $pixelWidth = $dims['width'];
            $pixelHeight = $dims['height'];
        }

        $safeJobUuid = preg_replace('/[^a-zA-Z0-9\-]/', '', $jobUuid) ?: 'unknown';
        $ext = $allowedMap[$detectedMime];
        $year = gmdate('Y');
        $month = gmdate('m');
        $fileUuid = $this->generateUuidV4();

        $vaultRoot = $this->ensureVaultDirectories();
        $subDirRel = sprintf('%s/%s/%s', $year, $month, $safeJobUuid);
        $targetDir = $vaultRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subDirRel);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
            throw new SkinValidationException('Failed to create private artwork storage directory.', 500, 'djgear_skins_storage_error');
        }

        $storageRelPath = sprintf('%s/%s.%s', $subDirRel, $fileUuid, $ext);
        $destination = $targetDir . DIRECTORY_SEPARATOR . $fileUuid . '.' . $ext;

        $copied = @copy($sourcePath, $destination);
        if (!$copied) {
            throw new SkinValidationException('Failed to persist file in private artwork vault.', 500, 'djgear_skins_storage_error');
        }
        @chmod($destination, 0640);

        $hash = hash_file('sha256', $destination);
        if ($hash === false) {
            @unlink($destination);
            throw new SkinValidationException('Failed to compute SHA-256 digest.', 500, 'djgear_skins_storage_error');
        }

        return [
            'storage_rel_path'  => $storageRelPath,
            'absolute_path'     => $destination,
            'original_filename' => $this->sanitizeDisplayFilename($originalFilename, $ext),
            'mime_type'         => $detectedMime,
            'file_size_bytes'   => $fileSize,
            'sha256_hash'       => $hash,
            'pixel_width'       => $pixelWidth,
            'pixel_height'      => $pixelHeight,
        ];
    }

    /**
     * Resolve a stored relative path safely within the private vault root using `realpath()`.
     */
    public function resolveSafePath(string $storageRelPath): string
    {
        if ($storageRelPath === '' || str_contains($storageRelPath, '..') || str_contains($storageRelPath, "\0")) {
            throw SkinValidationException::forbidden('Invalid artwork storage path.');
        }

        $vaultRoot = $this->vaultBaseDir();
        $realRoot = realpath($vaultRoot);
        if ($realRoot === false) {
            throw SkinValidationException::notFound('artwork', 'Artwork storage vault not found.');
        }

        $candidate = $realRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($storageRelPath, '/\\'));
        $realCandidate = realpath($candidate);

        if ($realCandidate === false || !is_file($realCandidate)) {
            throw SkinValidationException::notFound('artwork', 'Artwork file not found.');
        }

        $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realCandidate, $prefix)) {
            throw SkinValidationException::forbidden('Path traversal blocked.');
        }

        return $realCandidate;
    }

    /**
     * Delete a stored file cleanly (used only in test teardown or rejected-upload cleanup).
     */
    public function deleteFile(string $storageRelPath): bool
    {
        try {
            $path = $this->resolveSafePath($storageRelPath);
            return @unlink($path);
        } catch (SkinValidationException) {
            return false;
        }
    }

    public function vaultBaseDir(): string
    {
        if ($this->customVaultBaseDir !== null && $this->customVaultBaseDir !== '') {
            return rtrim($this->customVaultBaseDir, '/\\');
        }

        $baseUploads = sys_get_temp_dir();
        if (function_exists('wp_upload_dir')) {
            $uploadDir = wp_upload_dir();
            if ($uploadDir['basedir'] !== '') {
                $baseUploads = $uploadDir['basedir'];
            }
        }

        return rtrim($baseUploads, '/\\') . DIRECTORY_SEPARATOR . 'djgear-private' . DIRECTORY_SEPARATOR . 'customer-artwork';
    }

    public function ensureVaultDirectories(): string
    {
        $vaultDir = $this->vaultBaseDir();
        $privateParent = dirname($vaultDir);

        foreach ([$privateParent, $vaultDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
            }
            $indexPhp = $dir . DIRECTORY_SEPARATOR . 'index.php';
            if (!is_file($indexPhp)) {
                @file_put_contents($indexPhp, "<?php\n// Silence is golden.\n");
            }
        }

        return $vaultDir;
    }

    public function detectMimeType(string $filePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower(trim($mime));
                }
            }
        }

        return 'application/octet-stream';
    }

    private function sanitizeDisplayFilename(string $filename, string $fallbackExt): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $clean = (string) preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '', $base);
        $clean = trim($clean);
        if ($clean === '') {
            return 'artwork.' . $fallbackExt;
        }

        return mb_substr($clean, 0, 200);
    }

    public function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
