<?php
// 全家人記帳系統 — 匯出 PDF 月結單無記錄頁面
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 讀取 ledger_id，驗證為整數
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（IDOR 防護）
$stmt = $pdo->prepare('
    SELECT l.name
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.id = :ledger_id
      AND la.user_id = :user_id
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$ledger = $stmt->fetch();
if ($ledger === false) {
    redirect('./ledgers.php?error=1');
}

$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>匯出 PDF 月結單 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
</head>
<body>
    <div class="app">
        <aside class="side">
            <a class="brand" href="./dashboard.php">
                <span class="brand-kicker">FAMILY VOUCHER</span>
                <b>全家人記帳系統</b>
                <span class="brand-mark" aria-hidden="true"><i class="in"></i><i class="out"></i></span>
            </a>
            <nav class="nav" aria-label="主要導覽">
                <p class="nav-h">帳務</p>
                <a class="nav-item" href="./dashboard.php">儀表板</a>
                <a class="nav-item" href="./ledgers.php">帳本列表</a>
                <a class="nav-item" href="./change_password.php">變更密碼</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <p class="nav-h">後台</p>
                    <a class="nav-item" href="./admin/users.php">使用者管理</a>
                    <a class="nav-item" href="./admin/ledgers.php">帳本管理</a>
                    <a class="nav-item" href="./admin/audit_log.php">操作紀錄</a>
                <?php endif; ?>
            </nav>
            <div class="side-foot">
                <div class="who"><b><?php echo h($user['username']); ?></b><span class="role-chip"><?php echo h($user['role']); ?></span></div>
                <button type="button" class="font-size-toggle" id="fontSizeToggle" title="切換大字模式">大字 A</button>
                <a class="logout" href="./logout.php">登出</a>
            </div>
        </aside>

        <div class="main">
            <main class="sheet">
                <div class="page-head">
                    <div>
                        <h1>匯出 PDF 月結單</h1>
                        <p class="sub">帳本：<strong><?php echo h($ledger['name']); ?></strong></p>
                    </div>
                </div>

                <p class="alert info" role="status">該期間無記錄。</p>

                <p><a href="./export_pdf.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn primary">返回匯出頁面</a></p>
                <p><a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary">返回帳本詳情</a></p>
            </main>
        </div>
    </div>

    <script>
        // 字級切換：Cookie 讀寫與 .font-large 切換
        (function () {
            var toggle = document.getElementById('fontSizeToggle');
            if (!toggle) return;

            function getCookie(name) {
                var value = '; ' + document.cookie;
                var parts = value.split('; ' + name + '=');
                if (parts.length === 2) return parts.pop().split(';').shift();
                return null;
            }

            function setCookie(name, value, days) {
                var expires = new Date();
                expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000);
                document.cookie = name + '=' + value + '; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
            }

            var fontSize = getCookie('font_size');
            if (fontSize === 'large') {
                document.body.classList.add('font-large');
                toggle.textContent = '一般 A';
            }

            toggle.addEventListener('click', function () {
                if (document.body.classList.contains('font-large')) {
                    document.body.classList.remove('font-large');
                    setCookie('font_size', 'normal', 90);
                    toggle.textContent = '大字 A';
                } else {
                    document.body.classList.add('font-large');
                    setCookie('font_size', 'large', 90);
                    toggle.textContent = '一般 A';
                }
            });
        })();
    </script>
</body>
</html>
