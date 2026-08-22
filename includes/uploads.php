<?php
// ============================================================
// File upload validation
// ------------------------------------------------------------
// One implementation for every upload in the system, because the
// three that existed before had drifted badly apart:
//
//   * Product images  - checked the real MIME, capped the size and
//                       derived the extension from the detected type.
//                       Correct.
//   * Profile photos  - checked only the file NAME's extension.
//   * PO invoices     - checked only the file NAME's extension.
//   * Cropped avatars - checked NOTHING. The extension came straight
//                       out of the posted data URI, so a request
//                       carrying "data:image/php;base64,…" wrote a
//                       .php file into a web-served directory. That
//                       was remote code execution, not weak
//                       validation.
//
// THE RULES ENFORCED HERE
//   1. The file's real type is read from its CONTENT, never from its
//      name or from anything the client asserts.
//   2. The type must be on an allow-list.
//   3. Images must additionally decode as images (getimagesize), so a
//      payload that merely starts with image magic bytes is rejected.
//   4. The size is capped server-side.
//   5. The stored filename is generated here and its extension comes
//      from the DETECTED type - the client never influences it.
//
// Paired with assets/uploads/.htaccess, which stops the web server
// executing anything in the upload tree even if a bad file lands
// there by some route not yet imagined.
// ============================================================

/** Image types accepted anywhere in the system. MIME => extension. */
function uploadImageTypes(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
}

/** Documents accepted for purchase-order invoices. MIME => extension. */
function uploadDocumentTypes(): array {
    return uploadImageTypes() + ['application/pdf' => 'pdf'];
}

/** Caps. Generous enough for a phone photo, small enough to bound disk use. */
function uploadMaxImageBytes(): int    { return 3 * 1024 * 1024; }   // 3 MB
function uploadMaxDocumentBytes(): int { return 8 * 1024 * 1024; }   // 8 MB

/** Human-readable size, for error messages. */
function uploadFormatBytes(int $bytes): string {
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1) . ' MB'
        : round($bytes / 1024) . ' KB';
}

/**
 * The real MIME type of a file on disk, read from its content.
 *
 * finfo is preferred; mime_content_type() is the fallback. Both read
 * magic bytes rather than trusting the name.
 */
function uploadDetectMime(string $path): string {
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = @finfo_file($fi, $path);
            finfo_close($fi);
            if ($mime) { return strtolower((string)$mime); }
        }
    }
    return strtolower((string)@mime_content_type($path));
}

/**
 * Confirm a file really is one of the allowed image types.
 * Returns [ok, extensionOrErrorMessage].
 */
function uploadCheckImage(string $path, ?int $maxBytes = null): array {
    $maxBytes = $maxBytes ?? uploadMaxImageBytes();
    $allowed  = uploadImageTypes();

    $size = @filesize($path);
    if ($size === false || $size <= 0) { return [false, 'That file is empty.']; }
    if ($size > $maxBytes) {
        return [false, 'That image is ' . uploadFormatBytes((int)$size)
                     . '. The limit is ' . uploadFormatBytes($maxBytes) . '.'];
    }

    $mime = uploadDetectMime($path);
    if (!isset($allowed[$mime])) {
        return [false, 'That file is not a JPEG, PNG or WebP image.'];
    }

    // Second opinion: a real image decodes. This rejects a file that
    // merely begins with image magic bytes and continues with script.
    $info = @getimagesize($path);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return [false, 'That file is not a readable image.'];
    }
    // ...and the two opinions must agree.
    if (isset($info['mime']) && strtolower($info['mime']) !== $mime) {
        return [false, 'That file\'s contents do not match its image type.'];
    }

    return [true, $allowed[$mime]];
}

/** Confirm a file is an allowed invoice document. Returns [ok, ext|error]. */
function uploadCheckDocument(string $path, ?int $maxBytes = null): array {
    $maxBytes = $maxBytes ?? uploadMaxDocumentBytes();
    $allowed  = uploadDocumentTypes();

    $size = @filesize($path);
    if ($size === false || $size <= 0) { return [false, 'That file is empty.']; }
    if ($size > $maxBytes) {
        return [false, 'That file is ' . uploadFormatBytes((int)$size)
                     . '. The limit is ' . uploadFormatBytes($maxBytes) . '.'];
    }

    $mime = uploadDetectMime($path);
    if (!isset($allowed[$mime])) {
        return [false, 'The invoice must be a PDF, JPEG, PNG or WebP file.'];
    }

    // A PDF must actually start with %PDF-; images go through the
    // stricter image check.
    if ($mime === 'application/pdf') {
        $head = (string)@file_get_contents($path, false, null, 0, 5);
        if (strncmp($head, '%PDF-', 5) !== 0) {
            return [false, 'That file claims to be a PDF but is not one.'];
        }
        return [true, 'pdf'];
    }

    return uploadCheckImage($path, $maxBytes);
}

/** Translate PHP's own upload error codes into something a user can act on. */
function uploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'That file is too large to upload.';
        case UPLOAD_ERR_PARTIAL:    return 'The upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE:    return 'No file was chosen.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE: return 'The server could not save the file.';
        case UPLOAD_ERR_EXTENSION:  return 'That upload was blocked by the server.';
        default:                    return 'The file could not be uploaded.';
    }
}

/**
 * Validate and store one uploaded image.
 *
 * $file is an entry from $_FILES. $prefix names the file; the
 * extension is appended from the DETECTED type, so the client cannot
 * choose it.
 *
 * Returns [ok, filenameOrErrorMessage].
 */
function uploadStoreImage(array $file, string $dirFs, string $prefix, ?int $maxBytes = null): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))];
    }
    // Guarantees the path came from PHP's upload handling and is not an
    // arbitrary path the request pointed us at.
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return [false, 'That upload was not valid.'];
    }

    [$ok, $result] = uploadCheckImage($file['tmp_name'], $maxBytes);
    if (!$ok) { return [false, $result]; }

    return uploadPlace($file['tmp_name'], $dirFs, $prefix, $result, true);
}

/** Validate and store one uploaded document. Returns [ok, filename|error]. */
function uploadStoreDocument(array $file, string $dirFs, string $prefix, ?int $maxBytes = null): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))];
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return [false, 'That upload was not valid.'];
    }

    [$ok, $result] = uploadCheckDocument($file['tmp_name'], $maxBytes);
    if (!$ok) { return [false, $result]; }

    return uploadPlace($file['tmp_name'], $dirFs, $prefix, $result, true);
}

/**
 * Validate and store an image supplied as a base64 data URI - what the
 * avatar cropper produces.
 *
 * The declared type in the URI is IGNORED entirely. The payload is
 * written to a temporary file and then put through exactly the same
 * content checks as a normal upload, so this path is no weaker than
 * any other.
 *
 * Returns [ok, filenameOrErrorMessage].
 */
function uploadStoreDataUriImage(string $dataUri, string $dirFs, string $prefix, ?int $maxBytes = null): array {
    $maxBytes = $maxBytes ?? uploadMaxImageBytes();

    if (strpos($dataUri, ';base64,') === false) {
        return [false, 'The cropped image was not sent correctly.'];
    }
    [, $payload] = explode(';base64,', $dataUri, 2);

    // Reject on the encoded length before decoding, so an enormous
    // string cannot be expanded into memory first.
    if (strlen($payload) > (int)ceil($maxBytes * 4 / 3) + 1024) {
        return [false, 'That image is larger than ' . uploadFormatBytes($maxBytes) . '.'];
    }

    $binary = base64_decode($payload, true);   // strict
    if ($binary === false || $binary === '') {
        return [false, 'The cropped image could not be read.'];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'mira_up_');
    if ($tmp === false) { return [false, 'The server could not process the image.']; }
    file_put_contents($tmp, $binary);

    [$ok, $result] = uploadCheckImage($tmp, $maxBytes);
    if (!$ok) { @unlink($tmp); return [false, $result]; }

    $placed = uploadPlace($tmp, $dirFs, $prefix, $result, false);
    @unlink($tmp);
    return $placed;
}

/**
 * Move a validated file into place under a generated name.
 * $isUpload selects move_uploaded_file() vs rename(), so an uploaded
 * temp file is still moved through PHP's own checked path.
 */
function uploadPlace(string $tmpPath, string $dirFs, string $prefix, string $ext, bool $isUpload): array {
    if (!is_dir($dirFs) && !@mkdir($dirFs, 0755, true) && !is_dir($dirFs)) {
        return [false, 'The upload folder could not be created.'];
    }

    // Prefix is ours, not the client's, but keep it boring regardless.
    $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?: 'file';
    $name   = $prefix . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest   = rtrim($dirFs, '/\\') . DIRECTORY_SEPARATOR . $name;

    $moved = $isUpload ? @move_uploaded_file($tmpPath, $dest) : @copy($tmpPath, $dest);
    if (!$moved) { return [false, 'The file could not be saved.']; }

    @chmod($dest, 0644);
    return [true, $name];
}

/**
 * Delete a previously stored upload.
 *
 * Takes a BARE FILENAME and refuses anything that looks like a path.
 * The old code did `unlink($dir . $_POST['current_photo'])`, so a
 * request could walk out of the upload folder with "../" and delete
 * any file the web server could reach.
 */
function uploadDeleteFile(string $dirFs, string $filename): bool {
    $filename = trim($filename);
    if ($filename === '') { return false; }

    // basename() strips any directory part; the comparison then rejects
    // anything that had one, rather than silently operating on the
    // stripped name.
    if (basename($filename) !== $filename) { return false; }
    if (strpos($filename, "\0") !== false) { return false; }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) { return false; }

    $path = rtrim($dirFs, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) { return false; }

    // Final belt-and-braces: the resolved path must still be inside the
    // intended directory.
    $realDir  = realpath($dirFs);
    $realFile = realpath($path);
    if ($realDir === false || $realFile === false) { return false; }
    if (strncmp($realFile, $realDir, strlen($realDir)) !== 0) { return false; }

    return @unlink($realFile);
}
