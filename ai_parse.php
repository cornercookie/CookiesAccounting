<?php
// 全家人記帳系統 — AI 一句話記帳後端代理（SEC-01）
// 接收前端文字，以 GEMINI_API_KEY（僅後端持有）呼叫 Gemini API 解析為結構化記帳 JSON，
// 前端自行填入表單，不直接寫入資料庫。
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 僅允許登入使用者呼叫
requireLogin();

// JSON API 狀態改寫（寫入 api_rate_limits），需 CSRF 防護
if (!checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF 驗證失敗，請重新操作。'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 僅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '僅接受 POST 請求。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 讀取前端傳入的文字（JSON body 或 form）
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?? [];
$text = isset($data['text']) ? trim((string) $data['text']) : '';
if ($text === '' && isset($_POST['text'])) {
    $text = trim((string) $_POST['text']);
}

// 輸入長度上限（防 prompt 注入與濫用）
if ($text === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '請輸入要記帳的內容。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($text, 'UTF-8') > 500) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '內容過長，請縮短至 500 字以內。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// API Key 僅於後端讀取，絕不外洩給前端
$apiKey = (string) env('GEMINI_API_KEY');
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '系統尚未設定 Gemini API 金鑰，請洽管理員。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 單使用者速率限制（FIND-014）：避免濫用耗盡 Gemini API 配額。
// 以 user_id 為鍵，最近 1 分鐘內最多 20 次呼叫；超過則回傳 HTTP 429。
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId > 0) {
    $pdo = getPdo();
    $pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS api_rate_limits (
        identifier TEXT    NOT NULL,
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX IF NOT EXISTS idx_api_rate_limits_identifier ON api_rate_limits(identifier);
    CREATE INDEX IF NOT EXISTS idx_api_rate_limits_created_at ON api_rate_limits(created_at);
    SQL);
    // 清理超過 24 小時的舊紀錄
    $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $pdo->prepare('DELETE FROM api_rate_limits WHERE created_at < :cutoff')
        ->execute([':cutoff' => $cutoff]);
    // 檢查最近 1 分鐘內的呼叫次數
    $windowStart = date('Y-m-d H:i:s', strtotime('-1 minute'));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM api_rate_limits
         WHERE identifier = :identifier AND created_at >= :windowStart'
    );
    $stmt->execute([
        ':identifier' => (string) $userId,
        ':windowStart' => $windowStart,
    ]);
    $callCount = (int) ($stmt->fetchColumn() ?? 0);
    if ($callCount >= 20) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => '呼叫過於頻繁，請稍後再試。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 記錄本次呼叫
    $pdo->prepare(
        'INSERT INTO api_rate_limits (identifier, created_at) VALUES (:identifier, datetime(\'now\',\'localtime\'))'
    )->execute([':identifier' => (string) $userId]);
}

// 組建 Gemini prompt：要求回傳純 JSON，不加 Markdown 代碼區塊
$prompt = <<<'PROMPT'
你是一個記帳助手，請將使用者說的一句話解析為結構化 JSON，且只輸出 JSON，不要加 Markdown 代碼區塊（```json ... ```）或任何說明文字。

欄位：
- type：收入或支出，請用 "income" 或 "expense"
- amount：金額（元），數字，至多小數點後兩位，必須大於 0
- category：分類中文，例如 餐費、薪資、交通、娛樂
- date：日期 Y-m-dd，若未提及則用今天（Asia/Taipei）
- description：備註，可為空字串

使用者輸入：__TEXT__

PROMPT;
$prompt = str_replace('__TEXT__', $text, $prompt);

// 呼叫 Gemini API（後端代理，前端不直接連 Google 端點）
$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;
$payload = [
    'contents' => [
        ['parts' => [['text' => $prompt]]]
    ],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 256,
    ],
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $geminiUrl,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    error_log('Gemini API error (http=' . $httpCode . '): ' . $curlError . ' response=' . (string) $response);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'AI 解析服務暫時無法使用，請稍後再試或手動填寫。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$geminiResult = json_decode($response, true);
$geminiText = '';
if (is_array($geminiResult)
    && isset($geminiResult['candidates']) && is_array($geminiResult['candidates'])
    && isset($geminiResult['candidates'][0]['content']['parts'][0]['text'])) {
    $geminiText = (string) $geminiResult['candidates'][0]['content']['parts'][0]['text'];
}

if ($geminiText === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'AI 未回傳有效內容，請手動填寫。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 清除 Markdown 代碼區塊（```json ... ```），僅保留純 JSON 內容
$cleaned = preg_replace('/```(?:json)?\s*\n?/i', '', $geminiText);
$cleaned = trim($cleaned);
// 移除可能的尾部 ```
$cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);

$parsed = json_decode($cleaned, true);
if (!is_array($parsed)) {
    error_log('Gemini JSON decode failed: ' . $cleaned);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'AI 回傳格式錯誤，請手動填寫。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 嚴格驗證欄位型別
$result = [];
$result['type'] = isset($parsed['type']) && in_array($parsed['type'], ['income', 'expense'], true)
    ? $parsed['type'] : null;
$amountCents = isset($parsed['amount']) ? toCents((string) $parsed['amount']) : null;
$result['amount_cents'] = ($amountCents !== false && $amountCents > 0) ? $amountCents : null;
$result['category'] = isset($parsed['category']) && is_string($parsed['category']) && $parsed['category'] !== ''
    ? trim($parsed['category']) : null;
$result['date'] = isset($parsed['date']) && is_string($parsed['date']) && isValidDate($parsed['date'])
    ? $parsed['date'] : (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d');
$result['description'] = isset($parsed['description']) && is_string($parsed['description'])
    ? trim($parsed['description']) : '';

echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
exit;
