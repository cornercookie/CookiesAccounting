<?php
// 全家人記帳系統 — 記錄確認/認領機制：確認或爭議分錄記錄
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 僅接受 POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('./ledgers.php?error=1&msg=' . urlencode('僅接受 POST 請求。'));
}

// CSRF 驗證
$csrfRaw = $_POST['csrf_token'] ?? '';
if (!checkCsrf(is_string($csrfRaw) ? $csrfRaw : '')) {
    redirect('./ledger.php?error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
}

// 解析 entry_id
$entryIdRaw = $_POST['entry_id'] ?? '';
$entryId = filter_var($entryIdRaw, FILTER_VALIDATE_INT);
if ($entryId === false || $entryId === null || $entryId <= 0) {
    redirect('./ledger.php?error=1&msg=' . urlencode('無效的記錄編號。'));
}

// 解析 action
$action = $_POST['action'] ?? '';
if (!in_array($action, ['confirm', 'dispute'], true)) {
    redirect('./ledger.php?error=1&msg=' . urlencode('無效的操作類型。'));
}

// 查詢 entries 取得 ledger_id、user_id（記錄建立者）；記錄不存在或已軟刪除 → 302 重導回列表頁
$stmt = $pdo->prepare('
    SELECT e.ledger_id, e.user_id
    FROM entries e
    WHERE e.id = :entry_id
      AND e.deleted = 0
    LIMIT 1
');
$stmt->execute([':entry_id' => $entryId]);
$entry = $stmt->fetch();
if ($entry === false) {
    redirect('./ledger.php?error=1&msg=' . urlencode('找不到該記錄。'));
}
$ledgerId = (int) $entry['ledger_id'];
$entryCreatorId = (int) $entry['user_id'];

// 確認目前使用者對該帳本的存取權限
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
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('您沒有權限變更此記錄的確認狀態。'));
}
$accessRole = $access['role'];

// 驗證操作者是否為記錄建立者或帳本 owner
$isCreator = ($entryCreatorId === $userId);
$isOwner   = ($accessRole === 'owner');
if (!$isCreator && !$isOwner) {
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('您沒有權限變更此記錄的確認狀態。'));
}

// 執行更新：confirm -> ack_status=confirmed, dispute -> ack_status=disputed
$ackStatus = ($action === 'confirm') ? 'confirmed' : 'disputed';

try {
    $stmt = $pdo->prepare('
        UPDATE entries
        SET ack_status = :ack_status,
            ack_user_id = :ack_user_id,
            ack_at = datetime('now', 'localtime')
        WHERE id = :entry_id
          AND ledger_id = :ledger_id
          AND deleted = 0
    ');
    $stmt->execute([
        ':ack_status'   => $ackStatus,
        ':ack_user_id'  => $userId,
        ':entry_id'     => $entryId,
        ':ledger_id'    => $ledgerId,
    ]);

    if ($stmt->rowCount() === 0) {
        // 並發情況下記錄被軟刪除，視為找不到記錄
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('找不到該記錄。'));
    }
} catch (Throwable $e) {
    logError('entry_ack update failed: ' . $e->getMessage());
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('操作失敗，請稍後再試。'));
}

// 寫入審計日誌
$auditAction = ($action === 'confirm') ? 'entry_confirm' : 'entry_dispute';
logAudit($auditAction, 'entry #' . $entryId . ' · ledger ' . $ledgerId . ' · ack_status=' . $ackStatus);

// 重導回分錄列表頁
$msg = ($action === 'confirm') ? '已確認此筆記錄。' : '已標記此筆記錄為有爭議。';
redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode($msg));