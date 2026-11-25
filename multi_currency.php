<?php
// 全家人記帳系統 — 多幣別記帳重導向器（#28 合併至 add_entry.php）
// 僅保留：requireLogin() → 保留所有查詢參數 → 302 重導向至 add_entry.php → exit
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取
requireLogin();

// 保留所有查詢參數（防禦性編碼，Security S-03）
$qs = http_build_query($_GET);
$targetUrl = './add_entry.php' . ($qs !== '' ? '?' . $qs : '');

// 302 重導向
header('Location: ' . $targetUrl, true, 302);
exit;