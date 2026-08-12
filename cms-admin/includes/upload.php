<?php
declare(strict_types=1);

/**
 * Reusable file-upload helper for the CMS admin panel.
 *
 * Usage
 * -----
 * Build a $specs array (one entry per $_FILES input name) and pass it together
 * with the current stored paths and the project root:
 *
 *   $result = cms_process_file_uploads($specs, $currentPaths, $projectRoot);
 *
 * On success  → $result['errors'] is empty; $result['paths'] contains the new
 *               (or unchanged) stored paths ready to be written to the DB.
 * On failure  → $result['errors'] is non-empty; any files that were successfully
 *               moved before the error occurred are listed in $result['new_files']
 *               so the caller can unlink them on rollback.
 *
 * Spec keys
 * ---------
 * path_field   string        Key used in both $currentPaths and the returned paths array.
 * label        string        Human-readable field name used in error messages.
 * disk_dir     string        Upload directory relative to $projectRoot (e.g. 'uploads/banners').
 * web_prefix   string        Prefix prepended to the filename when building the stored path
 *                            (e.g. '/uploads/banners/'). Preserves the leading-slash convention
 *                            of each caller — do not normalise here.
 * max_bytes    int           Maximum allowed file size in bytes.
 * extensions   list<string>  Allowed file extensions (lowercase, no dot).
 * mimes        list<string>  Allowed MIME types as reported by finfo.
 * basename     string        Optional. Custom base name for the saved filename (e.g. a product
 *                            slug). When omitted the sanitised original filename is used.
 * extra_mimes  list<string>  Optional. Additional MIME types to accept for this field only
 *                            (e.g. 'application/octet-stream' for .ico files).
 *
 * @param array<string, array{
 *   path_field:   string,
 *   label:        string,
 *   disk_dir:     string,
 *   web_prefix:   string,
 *   max_bytes:    int,
 *   extensions:   list<string>,
 *   mimes:        list<string>,
 *   basename?:    string,
 *   extra_mimes?: list<string>,
 * }> $specs           Keyed by the $_FILES input name.
 * @param array<string, string> $currentPaths  Existing stored paths, keyed by path_field.
 *                                             Pass an empty array when creating (no old file to delete).
 * @param string $projectRoot  Absolute path to the project root (no trailing slash).
 *
 * @return array{
 *   paths:        array<string, string>,
 *   errors:       list<string>,
 *   new_files:    list<string>,
 *   delete_after: list<string>,
 * }
 */
function cms_process_file_uploads(array $specs, array $currentPaths, string $projectRoot): array
{
    /** @var array<string, string> $paths */
    $paths        = $currentPaths;
    $errors       = [];
    $newFiles     = [];
    $deleteAfter  = [];

    foreach ($specs as $fileKey => $config) {
        // Skip entirely if this input was not submitted.
        if (!isset($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) {
            continue;
        }

        $file      = $_FILES[$fileKey];
        $uploadErr = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        // No file chosen — not an error, just skip.
        if ($uploadErr === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $label = $config['label'];

        // PHP-level upload error.
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errors[] = $label . ' upload failed (error code ' . $uploadErr . ').';
            continue;
        }

        $tmpName      = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? '');
        $size         = (int)    ($file['size']     ?? 0);

        // Security: confirm the file really came from an HTTP upload.
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errors[] = $label . ' upload is invalid.';
            continue;
        }

        // Size sanity checks.
        if ($size <= 0) {
            $errors[] = $label . ' is empty.';
            continue;
        }
        if ($size > $config['max_bytes']) {
            $errors[] = $label . ' exceeds the maximum allowed file size.';
            continue;
        }

        // Extension check (client-supplied, so only a first-pass guard).
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $config['extensions'], true)) {
            $errors[] = $label . ' has a disallowed file extension.';
            continue;
        }

        // MIME check via finfo (reads the actual file bytes — more reliable).
        $finfo        = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) ($finfo->file($tmpName) ?: '');
        $allowedMimes = array_merge($config['mimes'], $config['extra_mimes'] ?? []);
        if ($detectedMime === '' || !in_array($detectedMime, $allowedMimes, true)) {
            $errors[] = $label . ' has a disallowed file type.';
            continue;
        }

        // Ensure upload directory exists.
        $diskDir = $projectRoot . '/' . $config['disk_dir'];
        if (!is_dir($diskDir) && !mkdir($diskDir, 0755, true) && !is_dir($diskDir)) {
            $errors[] = $label . ' upload folder is not writable.';
            continue;
        }

        // Build a safe, collision-free filename.
        // Use caller-supplied basename (e.g. product slug) when provided;
        // otherwise fall back to the sanitised original filename.
        if (isset($config['basename']) && $config['basename'] !== '') {
            $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $config['basename']) ?? '';
        } else {
            $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?? '';
        }
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'upload';
        }

        do {
            $safeFilename = $base . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
            $targetPath   = $diskDir . '/' . $safeFilename;
        } while (file_exists($targetPath));

        if (!move_uploaded_file($tmpName, $targetPath)) {
            $errors[] = $label . ' could not be saved.';
            continue;
        }

        // Normalise permissions (octal literal, not string).
        @chmod($targetPath, 0644);

        $newFiles[] = $targetPath;

        // Queue the previously stored file for deletion after a successful DB write.
        $pathField    = $config['path_field'];
        $previousPath = $paths[$pathField] ?? '';
        $webPrefix    = $config['web_prefix'];
        if ($previousPath !== '' && str_starts_with($previousPath, $webPrefix)) {
            $previousDisk = $projectRoot . '/' . ltrim($previousPath, '/');
            if ($previousDisk !== $targetPath) {
                $deleteAfter[] = $previousDisk;
            }
        }

        // Record the new stored path using the caller's web_prefix convention.
        $paths[$pathField] = $webPrefix . $safeFilename;
    }

    return [
        'paths'        => $paths,
        'errors'       => $errors,
        'new_files'    => $newFiles,
        'delete_after' => $deleteAfter,
    ];
}
