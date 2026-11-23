<?php
// 全家人記帳系統 — 收據圖片安全串流（SEC-02）
// 僅允許擁有該帳本存取權限的使用者檢視收據；不直接公開 private_data/uploads/
// 支援查詢參數 ?mode=inline 以內嵌顯示縮圖；預設 (mode=download) 強制下載
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 entry_id
$entryIdRaw = $_GET['id'] ?? null;
$entryId = filter_var($entryIdRaw, FILTER_VALIDATE_INT);
if ($entryId === false || $entryId === null) {
    http_response_code(400);
    exit('無效的記錄編號。');
}

// 解析模式參數：inline = 內嵌顯示（縮圖用），download = 強制下載（大圖用）
$mode = $_GET['mode'] ?? 'download';
if (!in_array($mode, ['inline', 'download'], true)) {
    $mode = 'download';
}

// 查詢收據路徑，同時驗證使用者對該帳本的存取權限（IDOR 防護）
$stmt = $pdo->prepare('
    SELECT e.receipt_path, l.name AS ledger_name
    FROM entries e
    INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id
    INNER JOIN ledgers l ON l.id = e.ledger_id
    WHERE e.id = :entry_id
      AND la.user_id = :user_id
      AND e.deleted = 0
    LIMIT 1
');
$stmt->execute([':entry_id' => $entryId, ':user_id' => $userId]);
$row = $stmt->fetch();
if ($row === false) {
    http_response_code(403);
    exit('無權存取此收據。');
}

$receiptPath = (string) ($row['receipt_path'] ?? '');
if ($receiptPath === '') {
    http_response_code(404);
    exit('此記錄沒有關聯的收據圖片。');
}

// 路徑僅允許 private_data/uploads/ 下的相對路徑，防止路徑遍歷
if (!str_starts_with($receiptPath, 'private_data/uploads/')) {
    http_response_code(403);
    exit('無效的收據路徑。');
}

$fullPath = __DIR__ . '/' . $receiptPath;

// 深度防禦：確認解析後的實體路徑仍位於 uploads 目錄內，防止萬一資料庫內容被竄改
$realPath = realpath($fullPath);
$uploadDir = realpath(__DIR__ . '/private_data/uploads');
if ($realPath === false || $uploadDir === false || !str_starts_with($realPath, $uploadDir . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    exit('無效的收據路徑。');
}
if (!is_file($realPath)) {
    http_response_code(404);
    exit('收據圖片不存在。');
}

// MIME 型別與副檔名
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? finfo_file($finfo, $realPath) : '';
if ($finfo) {
    finfo_close($finfo);
}
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(403);
    exit('不允許的檔案型別。');
}

// MIME 對應副檔名，用於 Content-Disposition 預設檔名
$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
$receiptExt = $mimeToExt[$mime] ?? 'bin';

// 條件快取：ETag (md5_file + size) 與 Last-Modified
$fileSize = filesize($realPath);
$fileMtime = filemtime($realPath);
$etag = '"' . md5_file($realPath) . '-' . $fileSize . '"';
$lastModified = gmdate('D, d M Y H:i:s', $fileMtime) . ' GMT';

header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);

// 檢查 If-None-Match / If-Modified-Since
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
$etagMatches = ($ifNoneMatch !== '' && $ifNoneMatch === $etag);
$modifiedSinceMatches = ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $fileMtime);
if ($etagMatches || $modifiedSinceMatches) {
    http_response_code(304);
    exit;
}

// 串流輸出：inline 內嵌顯示（縮圖），attachment 強制下載（大圖）
$disposition = ($mode === 'inline') ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="receipt-' . $entryId . '.' . $receiptExt . '"');
header('Content-Length: ' . (string) $fileSize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($realPath);
logAudit('entry_receipt_view', 'entry #' . $entryId . ' · receipt ' . $receiptPath);
exit;
