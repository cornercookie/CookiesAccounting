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
    requireSessionFresh();
}

/**
 * 檢查 Session 是否仍新鮮（FIND-013、FIND-016）。
 *
 * 若使用者的密碼在 Session 建立之後已被變更（password_changed_at > authed_at），
 * 則使既有 Session 失效並導向登入頁，防止已竊取的 Session 在密碼重設後仍可存取系統。
 *
 * 若 Session 超過閒置閾值（預設 30 分鐘）未有任何活動，也會導向登入頁，
 * 避免共享電腦上遺留的已登入 Session 被他人濫用。
 */
function requireSessionFresh(): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    $authedAt = (int) ($_SESSION['authed_at'] ?? 0);
    if ($authedAt <= 0) {
        return;
    }

    // FIND-016：閒置過期檢查（30 分鐘）
    $idleTimeout = 1800; // 秒
    $lastActivity = (int) ($_SESSION['last_activity'] ?? $authedAt);
    if (time() - $lastActivity > $idleTimeout) {
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        session_destroy();
        redirect('./login.php');
    }
    // 更新最後活動時間
    $_SESSION['last_activity'] = time();

    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT password_changed_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return;
    }
    $passwordChangedAt = (string) ($row['password_changed_at'] ?? '');
    if ($passwordChangedAt === '') {
        return; // 從未變更過密碼，不強制失效
    }
    $changedAt = strtotime($passwordChangedAt);
    if ($changedAt !== false && $authedAt < $changedAt) {
        // 密碼在 Session 建立後已變更，銷毀既有 Session
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        session_destroy();
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
 * 驗證密碼強度（FIND-018）。
 *
 * 規則：
 * - 長度至少 10 字元
 * - 至少包含大寫、小寫、數字、符號中的三種類別
 *
 * 回傳 ['ok' => bool, 'reason' => string]，ok 為 true 時 reason 為空字串。
 */
function validatePasswordStrength(string $password): array
{
    if (strlen($password) < 10) {
        return ['ok' => false, 'reason' => '新密碼至少需要 10 個字元。'];
    }
    $hasUpper   = (bool) preg_match('/[A-Z]/', $password);
    $hasLower   = (bool) preg_match('/[a-z]/', $password);
    $hasDigit   = (bool) preg_match('/[0-9]/', $password);
    $hasSymbol  = (bool) preg_match('/[^A-Za-z0-9]/', $password);
    $categoryCount = $hasUpper + $hasLower + $hasDigit + $hasSymbol;
    if ($categoryCount < 3) {
        return ['ok' => false, 'reason' => '新密碼需同時包含大寫、小寫、數字、符號中的至少三種類別。'];
    }
    return ['ok' => true, 'reason' => ''];
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
 * 寫入錯誤日誌（private_data/error.log）。
 * 用於需要明確記錄的錯誤點；PHP 層級的 errors/warnings 由 config.php 的
 * ini_set('error_log', ...) 自動導向同一個檔案。
 */
function logError(string $message): void
{
    error_log('[' . (new DateTime('now'))->format('Y-m-d H:i:s') . '] ' . $message);
}

/**
 * 寫入操作審計紀錄（audit_log 表）。
 *
 * - $userId / $username 未指定時，自動從目前 session 取得（未登入則記 NULL / 空字串）
 * - 僅供追蹤「誰做了什麼」，不記錄密碼、金鑰等機敏內容
 * - 任何失敗都不得影響主流程：失敗時改寫 error.log 並繼續執行
 */
function logAudit(string $action, string $detail = '', ?int $userId = null, ?string $username = null): void
{
    try {
        if ($userId === null && !empty($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
        }
        if ($username === null) {
            $username = (string) ($_SESSION['username'] ?? '');
        }
        // 截斷使用者可控的欄位，避免異常輸入灌爆 audit_log
        $truncate = static fn(string $s, int $max): string => function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
        $username = $truncate($username, 64);
        $detail   = $truncate($detail, 255);

        $pdo  = getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, username, action, detail, ip)
             VALUES (:user_id, :username, :action, :detail, :ip)'
        );
        $stmt->execute([
            ':user_id'  => $userId,
            ':username' => $username,
            ':action'   => $action,
            ':detail'   => $detail,
            ':ip'       => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (Throwable $e) {
        // 審計失敗不可中斷主流程，改寫 error.log 即可
        logError('audit log failed (action=' . $action . '): ' . $e->getMessage());
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
