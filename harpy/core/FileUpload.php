<?php
// ══════════════════════════════════════════════════════
// core/FileUpload.php — Helper upload gambar (logo, foto karyawan)
// Validasi: jpg/png/webp, max 2MB, rename unique
// ══════════════════════════════════════════════════════

class FileUpload
{
    const MAX_SIZE  = 2097152; // 2 MB
    const ALLOWED   = ['image/jpeg','image/png','image/webp','image/gif'];
    const EXT_MAP   = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];

    /**
     * Upload gambar ke folder relative dari ROOT.
     *
     * @return array{path:string,error:string|null}  Path relatif kalau sukses, error message kalau gagal.
     */
    public static function uploadImage(array $file, string $folder, string $prefix = ''): array
    {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['path'=>'', 'error'=>'Upload gagal (kode ' . ($file['error'] ?? '?') . ')'];
        }
        if ($file['size'] > self::MAX_SIZE) {
            return ['path'=>'', 'error'=>'Ukuran maksimal 2 MB. File: ' . round($file['size']/1024/1024, 1) . ' MB'];
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : null;
        if (!$mime) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
            if ($finfo) finfo_close($finfo);
        }
        if (!in_array($mime, self::ALLOWED, true)) {
            return ['path'=>'', 'error'=>'Format tidak didukung. Pakai JPG, PNG, WebP, atau GIF.'];
        }

        $ext = self::EXT_MAP[$mime] ?? 'bin';
        $dir = defined('ROOT') ? ROOT . '/' . trim($folder, '/') : trim($folder, '/');
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ['path'=>'', 'error'=>'Folder upload tidak bisa dibuat: ' . $folder];
            }
        }
        $name = ($prefix ? rtrim($prefix, '_') . '_' : '') . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $dir . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            return ['path'=>'', 'error'=>'Gagal menyimpan file. Cek permission folder.'];
        }
        // Return path relatif dari ROOT (untuk disimpan di DB & dipakai di URL)
        return ['path'=>trim($folder, '/') . '/' . $name, 'error'=>null];
    }

    /** Hapus file lama (kalau ada) */
    public static function deleteIfExists(?string $relativePath): bool
    {
        if (!$relativePath) return false;
        $full = defined('ROOT') ? ROOT . '/' . ltrim($relativePath, '/') : ltrim($relativePath, '/');
        if (is_file($full)) return @unlink($full);
        return false;
    }

    /** URL untuk akses public (relative ke web root /ERP/harpy/) */
    public static function publicUrl(?string $relativePath): string
    {
        if (!$relativePath) return '';
        return '/ERP/harpy/' . ltrim($relativePath, '/');
    }
}
