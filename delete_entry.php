<?php
// 全家人記帳系統 — 作廢記錄（Task 2）：僅接受 POST，軟刪除指定帳本下的記錄
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('./ledgers.php?error=1');
}

// CSRF 驗證
if (!checkCsrf($_POST['csrf_token'] ?? '')) {
    redirect('./ledgers.php?error=1');
}

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

$idRaw      = $_POST['id'] ?? null;
$ledgerIdRaw = $_POST['ledger_id'] ?? null;
$id     = filter_var($idRaw, FILTER_VALIDATE_INT);
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);

if ($id === false || $id === null || $ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（帳本權限檢查，且此頁僅記 owner / member 可作廢）
$stmt = $pdo->prepare('
    SELECT l.archived, la.role
    FROM ledgers l
    JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.id = :ledger_id
      AND la.user_id = :user_id
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$access = $stmt->fetch();
if ($access === false) {
    redirect('./ledgers.php?error=1');
}
if ((int) ($access['archived'] ?? 0) === 1) {
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1');
}
if (!in_array($access['role'], ['owner', 'member'], true)) {
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1');
}

// 權限矩陣「僅限自己建立的記錄」：owner 可作廢帳本內所有記錄；member 僅限自己建立的記錄
$isLedgerOwner = ($access['role'] === 'owner') ? 1 : 0;

// 確認記錄存在、所屬帳本可存取，且尚未刪除（member 僅能作廢自己建立的記錄）
$stmt = $pdo->prepare('
    SELECT id
    FROM entries
    WHERE id = :id
      AND ledger_id = :ledger_id
      AND deleted = 0
      AND (:is_owner = 1 OR user_id = :user_id)
    LIMIT 1
');
$stmt->execute([':id' => $id, ':ledger_id' => $ledgerId, ':is_owner' => $isLedgerOwner, ':user_id' => $userId]);
if ($stmt->fetch() === false) {
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1');
}

// 軟刪除：prepared statement，並且只作廢尚未刪除的記錄（SELECT 與 UPDATE 套用同一權限條件）
$stmt = $pdo->prepare('
    UPDATE entries
    SET deleted = 1
    WHERE id = :id
      AND ledger_id = :ledger_id
      AND deleted = 0
      AND (:is_owner = 1 OR user_id = :user_id)
');
$stmt->execute([':id' => $id, ':ledger_id' => $ledgerId, ':is_owner' => $isLedgerOwner, ':user_id' => $userId]);
$deleted = $stmt->rowCount() > 0;
if ($deleted) {
    // 清理關聯收據圖片（若有）
    $stmt = $pdo->prepare('SELECT receipt_path FROM entries WHERE id = :id AND ledger_id = :ledger_id LIMIT 1');
    $stmt->execute([':id' => $id, ':ledger_id' => $ledgerId]);
    $row = $stmt->fetch();
    if ($row !== false && !empty($row['receipt_path'])) {
        $fullPath = __DIR__ . '/' . $row['receipt_path'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
    logAudit('entry_delete', 'entry #' . $id . ' · ledger ' . $ledgerId);
}

redirect('./ledger.php?ledger_id=' . $ledgerId . '&' . ($deleted ? 'success=1' : 'error=1'));
