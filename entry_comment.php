<?php
// 全家人記帳系統 — 新增留言端點（[#21] 家庭訊息留言板）
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 必須登入
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 僅接受 POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('./ledgers.php?error=1&msg=' . urlencode('不支援的請求方式。'));
}

// 取得並驗證輸入
$ledgerIdRaw = $_POST['ledger_id'] ?? '';
$entryIdRaw  = $_POST['entry_id'] ?? '';
$commentRaw  = $_POST['comment'] ?? '';
$csrfRaw     = $_POST['csrf_token'] ?? '';

// 保留分頁/篩選參數以便 redirect 回原檢視
$ym         = isset($_POST['ym'])         && is_string($_POST['ym'])         ? trim($_POST['ym'])         : '';
$type       = isset($_POST['type'])       && is_string($_POST['type'])       ? trim($_POST['type'])       : '';
$category   = isset($_POST['category'])   && is_string($_POST['category'])   ? trim($_POST['category'])   : '';
$page       = isset($_POST['page'])       && is_string($_POST['page'])       ? trim($_POST['page'])       : '';
$entryDate  = isset($_POST['entry_date']) && is_string($_POST['entry_date']) ? trim($_POST['entry_date']) : '';

// 組建 redirect 基礎參數
$redirectParams = ['ledger_id' => $ledgerIdRaw];
if ($ym !== '')         $redirectParams['ym']         = $ym;
if ($type !== '')       $redirectParams['type']       = $type;
if ($category !== '')   $redirectParams['category']   = $category;
if ($page !== '')       $redirectParams['page']       = $page;
if ($entryDate !== '')  $redirectParams['entry_date'] = $entryDate;
$redirectBase = './ledger.php?' . http_build_query($redirectParams);

// CSRF 驗證
if (!checkCsrf(is_string($csrfRaw) ? $csrfRaw : '')) {
    redirect($redirectBase . '&error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
}

// ledger_id 驗證
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId <= 0) {
    redirect($redirectBase . '&error=1&msg=' . urlencode('帳本編號不正確。'));
}

// entry_id 驗證
$entryId = filter_var($entryIdRaw, FILTER_VALIDATE_INT);
if ($entryId === false || $entryId <= 0) {
    redirect($redirectBase . '&error=1&msg=' . urlencode('記錄編號不正確。'));
}

// 留言內容驗證：trim 後 1~200 字元（mb_strlen，UTF-8）
$comment = trim($commentRaw);
$commentLen = function_exists('mb_strlen') ? mb_strlen($comment, 'UTF-8') : strlen($comment);
if ($comment === '' || $commentLen < 1 || $commentLen > 200) {
    redirect($redirectBase . '&error=1&msg=' . urlencode('請輸入留言內容（1~200 字元）。'));
}

// 驗證 entry_id 存在、屬於該 ledger_id、未刪除
$stmt = $pdo->prepare('SELECT id FROM entries WHERE id = :entry_id AND ledger_id = :ledger_id AND deleted = 0 LIMIT 1');
$stmt->execute([':entry_id' => $entryId, ':ledger_id' => $ledgerId]);
if ($stmt->fetch() === false) {
    redirect($redirectBase . '&error=1&msg=' . urlencode('記錄不存在或已被刪除。'));
}

// 驗證帳本存取權限（ledger_access）
$stmt = $pdo->prepare('
    SELECT la.role
    FROM ledger_access la
    WHERE la.ledger_id = :ledger_id
      AND la.user_id = :user_id
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$access = $stmt->fetch();
if ($access === false) {
    redirect($redirectBase . '&error=1&msg=' . urlencode('您沒有該帳本的存取權限。'));
}
// owner/member/viewer 皆可留言，無需額外角色檢查

// 寫入留言
try {
    $stmt = $pdo->prepare('
        INSERT INTO entry_comments (entry_id, user_id, comment)
        VALUES (:entry_id, :user_id, :comment)
    ');
    $stmt->execute([
        ':entry_id' => $entryId,
        ':user_id'  => $userId,
        ':comment'  => $comment,
    ]);
} catch (Throwable $e) {
    error_log('[' . (new DateTime('now'))->format('Y-m-d H:i:s') . '] entry_comment insert failed: ' . $e->getMessage());
    redirect($redirectBase . '&error=1&msg=' . urlencode('留言儲存失敗，請稍後再試。'));
}

// 審計日誌：action=entry_comment，detail JSON 不含留言內容
logAudit('entry_comment', json_encode(['entry_id' => $entryId]));

// 成功 redirect 回原檢視
redirect($redirectBase . '&success=1&msg=' . urlencode('留言已新增。'));