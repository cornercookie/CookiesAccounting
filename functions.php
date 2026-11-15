<?php
// 全家人記帳系統 — 共用輔助函數

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * 將字串安全地輸出為 HTML（XSS 防護）。頁面上所有輸出皆經本函數。
 */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 導向相對網址（./ 開頭），讓應用程式可部署於任意子目錄。
 * 呼叫後務必 exit()。
 */
function redirect(string $url): void
{
    if (str_starts_with($url, '/')) {
        $url = '.' . $url;
    }
    if (!str_starts_with($url, './') && !str_starts_with($url, '../')) {
        $url = './' . $url;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * 要求已登入；未登入則導向登入頁。
 */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('./login.php');
    }
}

/**
 * 回傳目前登入使用者；未登入回傳 null。
 */
function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'       => (int) $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['role'],
    ];
}

/**
 * 要求管理員權限；非管理員導回登入頁。
 */
function requireAdmin(): void
{
    requireLogin();
    if ((string) ($_SESSION['role'] ?? '') !== 'admin') {
        redirect('./login.php');
    }
}

/**
 * 取得表單 CSRF 欄位值。
 */
function csrfToken(): string
{
    return (string) ($_SESSION['csrf_token'] ?? '');
}

/**
 * 驗證 CSRF 欄位（constant-time 比較）。
 */
function checkCsrf(string $token): bool
{
    if ($token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 將整數分金額格式化為顯示用字串（不使用 FLOAT）。
 * 例如 12345 -> '123.45'，-500 -> '-5.00'。
 */
function formatCents(int $cents): string
{
    $negative = $cents < 0;
    $abs      = abs($cents);
    $dollars  = intdiv($abs, 100);
    $centsPart = $abs % 100;
    // 千分位：長輩辨識大面額金額（純顯示，解析不受影響）
    return ($negative ? '-' : '') . number_format($dollars) . '.' . str_pad((string) $centsPart, 2, '0', STR_PAD_LEFT);
}

/**
 * 將金額字串解析為整數分（不使用 FLOAT）。
 * 接受 '100' 或 '100.50'（至多小數點後兩位），否則回傳 false。
 */
function toCents(string $amount): int|false
{
    $amount = trim($amount);
    if ($amount === '' || !preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $amount, $m)) {
        return false;
    }
    $dollars = (int) $m[1];
    $cents   = isset($m[2]) ? (int) str_pad($m[2], 2, '0', STR_PAD_RIGHT) : 0;
    return $dollars * 100 + $cents;
}

/**
 * 驗證字串是否為合法的 Y-m-d 曆日。
 */
function isValidDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

/**
 * 判斷指定識別碼（帳號|IP）是否處於登入鎖定期間。
 *
 * locked_until 設定且未過期時回傳 true，否則回傳 false。
 */
function isLoginLocked(string $identifier): bool
{
    $pdo = getPdo();
    $stmt = $pdo->prepare(
        'SELECT locked_until FROM login_attempts WHERE identifier = :identifier'
    );
    $stmt->execute([':identifier' => $identifier]);
    $row = $stmt->fetch();
    if ($row === false || $row['locked_until'] === null || $row['locked_until'] === '') {
        return false;
    }
    return (string) $row['locked_until'] > (new DateTime('now'))->format('Y-m-d H:i:s');
}

/**
 * 記錄一次登入失敗。
 *
 * failed_count 遞增；累計達 5 次時，將 locked_until 設為 15 分鐘後，鎖定該識別碼。
 * 全部採用預備陳述式，不拼字串 SQL。
 */
function recordLoginFailure(string $identifier): void
{
    $pdo = getPdo();
    $now = (new DateTime('now'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(<<<'SQL'
    INSERT INTO login_attempts (identifier, failed_count, updated_at)
    VALUES (:identifier, 1, :updated_at)
    ON CONFLICT(identifier) DO UPDATE SET
        failed_count = failed_count + 1,
        updated_at   = :updated_at
    SQL);
    $stmt->execute([
        ':identifier' => $identifier,
        ':updated_at' => $now,
    ]);

    $stmt = $pdo->prepare(
        'SELECT failed_count FROM login_attempts WHERE identifier = :identifier'
    );
    $stmt->execute([':identifier' => $identifier]);
    $row = $stmt->fetch();
    if ($row !== false && (int) $row['failed_count'] >= 5) {
        $lockedUntil = (new DateTime('now'))->modify('+15 minutes')->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE login_attempts
             SET locked_until = :locked_until, updated_at = :updated_at
             WHERE identifier = :identifier'
        );
        $stmt->execute([
            ':locked_until' => $lockedUntil,
            ':updated_at'   => $now,
            ':identifier'   => $identifier,
        ]);
    }
}

/**
 * 登入成功後清除指定識別碼的失敗紀錄與鎖定狀態。
 */
function clearLoginAttempts(string $identifier): void
{
    $pdo = getPdo();
    $stmt = $pdo->prepare(<<<'SQL'
    INSERT INTO login_attempts (identifier, failed_count, locked_until, updated_at)
    VALUES (:identifier, 0, NULL, :updated_at)
    ON CONFLICT(identifier) DO UPDATE SET
        failed_count  = 0,
        locked_until  = NULL,
        updated_at    = :updated_at
    SQL);
    $stmt->execute([
        ':identifier' => $identifier,
        ':updated_at' => (new DateTime('now'))->format('Y-m-d H:i:s'),
    ]);
}
