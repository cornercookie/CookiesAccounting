<?php
// 全家人記帳系統 — 帳本詳情（Task 2）：本月收支彙總與最近 50 筆記錄
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// 未登入不得存取內頁
requireLogin();

$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 ledger_id（亦相容舊式 id 參數）
$ledgerIdRaw = $_GET['ledger_id'] ?? $_GET['id'] ?? null;
if ($ledgerIdRaw === null || $ledgerIdRaw === '') {
    redirect('./ledgers.php?error=1');
}
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId <= 0) {
    redirect('./ledgers.php?error=1');
}

// 驗證目前使用者對該帳本的存取權限（查詢 ledger_access）
$stmt = $pdo->prepare('
    SELECT l.name, l.archived, l.monthly_budget_cents, la.role
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
if ((int) ($ledger['archived'] ?? 0) === 1) {
    redirect('./ledgers.php?error=1&msg=' . urlencode('此帳本已封存，無法檢視或新增記錄。'));
}
$accessRole = $ledger['role'];

// 每月預算設定（SEC-04）：僅帳本 Owner（ledger_access.role='owner'）可修改。
// 站台管理員（users.role='admin'）不在本帳本成員名單時同樣被拒（無 ledger_access 列即查無此帳）。
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'set_budget') {
    $csrfRaw = $_POST['csrf_token'] ?? '';
    if (!checkCsrf(is_string($csrfRaw) ? $csrfRaw : '')) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
    }
    if ($accessRole !== 'owner') {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('僅帳本擁有者（owner）可以設定預算。'));
    }
    $budgetRaw = $_POST['monthly_budget'] ?? '';
    if (!is_string($budgetRaw)) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('預算金額格式不正確。'));
    }
    $budgetRaw = trim($budgetRaw);
    if ($budgetRaw === '') {
        // 清空欄位 = 取消預算（設為 0，回復未設定狀態）
        $budgetCents = 0;
    } else {
        $budgetCents = toCents($budgetRaw); // 字串正則解析，非浮點；不接受負數
        if ($budgetCents === false || $budgetCents <= 0) {
            redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('預算金額格式不正確，請輸入大於 0 的金額。'));
        }
    }
    $stmt = $pdo->prepare('UPDATE ledgers SET monthly_budget_cents = :budget WHERE id = :ledger_id');
    $stmt->execute([':budget' => $budgetCents, ':ledger_id' => $ledgerId]);
    logAudit('ledger_budget_set', 'ledger_id=' . $ledgerId . ' monthly_budget_cents=' . $budgetCents);
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('已更新每月預算。'));
}

// 重複記錄：建立（Feature 11）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create_recurring') {
    if (!checkCsrf(is_string($_POST['csrf_token'] ?? '') ? $_POST['csrf_token'] : '')) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
    }
    if ($accessRole !== 'owner' && $accessRole !== 'admin') {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('僅帳本擁有者或管理員可建立重複記錄。'));
    }
    $name      = trim($_POST['name'] ?? '');
    $amountRaw = trim($_POST['amount'] ?? '');
    $category  = trim($_POST['category'] ?? '');
    $type      = $_POST['type'] ?? '';
    $startDate = trim($_POST['start_date'] ?? '');
    $nextDue   = trim($_POST['next_due_date'] ?? '');

    if ($name === '') {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('請輸入重複記錄名稱。'));
    }
    $cents = toCents($amountRaw);
    if ($cents === false || $cents <= 0) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('金額必須為大於 0 的正整數分。'));
    }
    if (!in_array($type, ['income', 'expense'], true)) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('類別必須為收入或支出。'));
    }
    if (!isValidDate($startDate) || !isValidDate($nextDue)) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('起始日期與下次預計日期必須為有效日期。'));
    }
    if (mb_strlen($category, 'UTF-8') > 50) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('類別長度超過 50 字元限制。'));
    }

    $stmt = $pdo->prepare('
        INSERT INTO recurring_entries
            (ledger_id, user_id, name, amount_cents, type, category, start_date, next_due_date, is_active)
        VALUES
            (:ledger_id, :user_id, :name, :amount_cents, :type, :category, :start_date, :next_due_date, 1)
    ');
    $stmt->execute([
        ':ledger_id'   => $ledgerId,
        ':user_id'     => $userId,
        ':name'        => $name,
        ':amount_cents'=> $cents,
        ':type'        => $type,
        ':category'    => $category,
        ':start_date'  => $startDate,
        ':next_due_date'=> $nextDue,
    ]);
    $recurringId = (int) $pdo->lastInsertId();
    logAudit('recurring_create', 'recurring_id=' . $recurringId . ' ledger_id=' . $ledgerId . ' name=' . $name . ' amount=' . formatCents($cents));
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('重複記錄已建立。'));
}

// 重複記錄：編輯（Feature 11）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'edit_recurring') {
    if (!checkCsrf(is_string($_POST['csrf_token'] ?? '') ? $_POST['csrf_token'] : '')) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
    }
    $recurringId = filter_var($_POST['recurring_id'] ?? '', FILTER_VALIDATE_INT);
    if ($recurringId === false || $recurringId === null) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('無效的重複記錄編號。'));
    }
    // 先驗證使用者對該帳本的存取權，再驗證是否為建立者或 owner/admin
    $ownStmt = $pdo->prepare('SELECT user_id FROM recurring_entries WHERE id = :id AND ledger_id = :ledger_id LIMIT 1');
    $ownStmt->execute([':id' => $recurringId, ':ledger_id' => $ledgerId]);
    $ownRow = $ownStmt->fetch();
    if ($ownRow === false) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('找不到該重複記錄。'));
    }
    $isOwner = ((int) $ownRow['user_id'] === $userId);
    if (!$isOwner && $accessRole !== 'owner' && $accessRole !== 'admin') {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('僅建立者或帳本擁有者可編輯重複記錄。'));
    }

    $name      = trim($_POST['name'] ?? '');
    $amountRaw = trim($_POST['amount'] ?? '');
    $category  = trim($_POST['category'] ?? '');
    $type      = $_POST['type'] ?? '';
    $startDate = trim($_POST['start_date'] ?? '');
    $nextDue   = trim($_POST['next_due_date'] ?? '');

    if ($name === '') {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('請輸入重複記錄名稱。'));
    }
    $cents = toCents($amountRaw);
    if ($cents === false || $cents <= 0) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('金額必須為大於 0 的正整數分。'));
    }
    if (!in_array($type, ['income', 'expense'], true)) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('類別必須為收入或支出。'));
    }
    if (!isValidDate($startDate) || !isValidDate($nextDue)) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('起始日期與下次預計日期必須為有效日期。'));
    }
    if (mb_strlen($category, 'UTF-8') > 50) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('類別長度超過 50 字元限制。'));
    }

    $stmt = $pdo->prepare('
        UPDATE recurring_entries
        SET name = :name, amount_cents = :amount_cents, type = :type, category = :category,
            start_date = :start_date, next_due_date = :next_due_date, updated_at = datetime('now', 'localtime')
        WHERE id = :id AND ledger_id = :ledger_id
    ');
    $stmt->execute([
        ':name'        => $name,
        ':amount_cents'=> $cents,
        ':type'        => $type,
        ':category'    => $category,
        ':start_date'  => $startDate,
        ':next_due_date'=> $nextDue,
        ':id'          => $recurringId,
        ':ledger_id'   => $ledgerId,
    ]);
    logAudit('recurring_edit', 'recurring_id=' . $recurringId . ' ledger_id=' . $ledgerId);
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('重複記錄已更新。'));
}

// 重複記錄：停用（Feature 11）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'toggle_recurring') {
    if (!checkCsrf(is_string($_POST['csrf_token'] ?? '') ? $_POST['csrf_token'] : '')) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
    }
    $recurringId = filter_var($_POST['recurring_id'] ?? '', FILTER_VALIDATE_INT);
    if ($recurringId === false || $recurringId === null) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('無效的重複記錄編號。'));
    }
    $ownStmt = $pdo->prepare('SELECT user_id FROM recurring_entries WHERE id = :id AND ledger_id = :ledger_id LIMIT 1');
    $ownStmt->execute([':id' => $recurringId, ':ledger_id' => $ledgerId]);
    $ownRow = $ownStmt->fetch();
    if ($ownRow === false) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('找不到該重複記錄。'));
    }
    $isOwner = ((int) $ownRow['user_id'] === $userId);
    if (!$isOwner && $accessRole !== 'owner' && $accessRole !== 'admin') {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('僅建立者或帳本擁有者可停用重複記錄。'));
    }

    $stmt = $pdo->prepare('UPDATE recurring_entries SET is_active = 0, updated_at = datetime('now', 'localtime') WHERE id = :id AND ledger_id = :ledger_id');
    $stmt->execute([':id' => $recurringId, ':ledger_id' => $ledgerId]);
    logAudit('recurring_toggle', 'recurring_id=' . $recurringId . ' ledger_id=' . $ledgerId . ' action=disable');
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('重複記錄已停用。'));
}

// 重複記錄：立即記一筆（Feature 11）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'generate_recurring') {
    if (!checkCsrf(is_string($_POST['csrf_token'] ?? '') ? $_POST['csrf_token'] : '')) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
    }
    $recurringId = filter_var($_POST['recurring_id'] ?? '', FILTER_VALIDATE_INT);
    if ($recurringId === false || $recurringId === null) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('無效的重複記錄編號。'));
    }
    // 驗證使用者對該帳本的存取權（ledger_access 已在開頭驗證，這裡再確認 recurring_entries 歸屬）
    $ownStmt = $pdo->prepare('SELECT user_id, amount_cents, type, category, name FROM recurring_entries WHERE id = :id AND ledger_id = :ledger_id AND is_active = 1 LIMIT 1');
    $ownStmt->execute([':id' => $recurringId, ':ledger_id' => $ledgerId]);
    $recurring = $ownStmt->fetch();
    if ($recurring === false) {
        redirect('./ledger.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('找不到該啟用中的重複記錄。'));
    }

    $entryDate = $today->format('Y-m-d');
    $stmt = $pdo->prepare('
        INSERT INTO entries
            (ledger_id, user_id, amount_cents, type, category, entry_date, note, receipt_path, deleted, recurring_source_id)
        VALUES
            (:ledger_id, :user_id, :amount_cents, :type, :category, :entry_date, :note, '', 0, :recurring_source_id)
    ');
    $stmt->execute([
        ':ledger_id'       => $ledgerId,
        ':user_id'         => $userId,
        ':amount_cents'    => (int) $recurring['amount_cents'],
        ':type'            => $recurring['type'],
        ':category'        => $recurring['category'],
        ':entry_date'      => $entryDate,
        ':note'            => '來自重複記錄：' . $recurring['name'],
        ':recurring_source_id'=> $recurringId,
    ]);
    $entryId = (int) $pdo->lastInsertId();
    logAudit('recurring_generate', 'entry_id=' . $entryId . ' recurring_id=' . $recurringId . ' ledger_id=' . $ledgerId);
    $_SESSION['voice_played'] = 1;
    $_SESSION['voice_text'] = ($recurring['type'] === 'income' ? '收入' : '支出') . ' ' . formatCents((int) $recurring['amount_cents']) . ' 元已儲存';
    redirect('./ledger.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('已從重複記錄產生一筆記錄。'));
}

// 本帳查詢參數：月份（預設本月 Asia/Taipei）、單日（entry_date）、類別、類型、頁碼——皆為唯讀查詢
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei'));
$ymRaw = $_GET['ym'] ?? '';
if (!is_string($ymRaw) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ymRaw)) {
    $ym = $today->format('Y-m');
} else {
    $ym = $ymRaw;
}
$monthStart = $ym . '-01';
$monthEnd   = (new DateTimeImmutable($monthStart . ' 00:00:00', new DateTimeZone('Asia/Taipei')))->format('Y-m-t');

// 單日參數解析（格式 Y-m-d，透過 isValidDate() 驗證）
$entryDateRaw = $_GET['entry_date'] ?? '';
$entryDate = null;
if ($entryDateRaw !== '' && isValidDate($entryDateRaw)) {
    $entryDate = $entryDateRaw;
}

$typeSel = $_GET['type'] ?? '';
if (!in_array($typeSel, ['', 'income', 'expense', 'transfer'], true)) {
    $typeSel = '';
}
$catSel = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

// 所選月份的收入／支出／轉帳彙總（不含類別/類型篩選；整數分儲存，顯示除以 100）
// 轉帳（type='transfer'）不計入收入或支出，僅單獨統計
// 若指定單日（entry_date），則以該日彙總取代月份範圍
if ($entryDate !== null) {
    $stmt = $pdo->prepare('
        SELECT type, SUM(amount_cents) AS total_cents
        FROM entries
        WHERE ledger_id = :ledger_id
          AND deleted = 0
          AND entry_date = :entryDate
        GROUP BY type
    ');
    $stmt->execute([':ledger_id' => $ledgerId, ':entryDate' => $entryDate]);
} else {
    $stmt = $pdo->prepare('
        SELECT type, SUM(amount_cents) AS total_cents
        FROM entries
        WHERE ledger_id = :ledger_id
          AND deleted = 0
          AND entry_date BETWEEN :monthStart AND :monthEnd
        GROUP BY type
    ');
    $stmt->execute([':ledger_id' => $ledgerId, ':monthStart' => $monthStart, ':monthEnd' => $monthEnd]);
}
$incomeCents  = 0;
$expenseCents = 0;
$transferCents = 0;
while ($row = $stmt->fetch()) {
    $total = (int) $row['total_cents'];
    if ($row['type'] === 'income') {
        $incomeCents = $total;
    } elseif ($row['type'] === 'expense') {
        $expenseCents = $total;
    } elseif ($row['type'] === 'transfer') {
        $transferCents = $total;
    }
}
$balanceCents = $incomeCents - $expenseCents;

// 預算紅線（僅當月顯示；budget=0 未設定時不顯示任何預算區塊，維持原版面）
$budgetCents = (int) ($ledger['monthly_budget_cents'] ?? 0);
$isCurrentMonth = ($ym === $today->format('Y-m'));
$budgetProgress = null; // ['percent'=>int, 'status'=>'ok|warn|danger', 'remaining'=>int]
if ($isCurrentMonth && $budgetCents > 0) {
    // 整數交叉相乘判定，避免浮點誤差：≥100% 危險、≥80% 警告
    if ($expenseCents * 100 >= $budgetCents * 100) {
        $budgetStatus = 'danger';
    } elseif ($expenseCents * 100 >= $budgetCents * 80) {
        $budgetStatus = 'warn';
    } else {
        $budgetStatus = 'ok';
    }
    $budgetProgress = [
        'percent'   => (int) floor($expenseCents * 100 / $budgetCents), // 僅顯示用
        'status'    => $budgetStatus,
        'remaining' => $budgetCents - $expenseCents,
    ];
}

// 預算輸入框回填值：不加千分位逗號，確保可原樣提交並被 toCents() 接受
$budgetInputValue = '';
if ($budgetCents > 0) {
    $bDollars = intdiv($budgetCents, 100);
    $bCents   = $budgetCents % 100;
    $budgetInputValue = (string) $bDollars . ($bCents !== 0 ? '.' . str_pad((string) $bCents, 2, '0', STR_PAD_LEFT) : '');
}

// 重複記錄列表：查詢本帳本啟用中的重複記錄（Feature 11）
$recurringList = [];
if ($accessRole === 'owner' || $accessRole === 'admin' || $accessRole === 'member') {
    $recStmt = $pdo->prepare('
        SELECT re.id, re.name, re.amount_cents, re.type, re.category, re.start_date, re.next_due_date, re.is_active, re.created_at, u.username AS creator
        FROM recurring_entries re
        INNER JOIN users u ON u.id = re.user_id
        WHERE re.ledger_id = :ledger_id
          AND re.is_active = 1
        ORDER BY re.next_due_date ASC, re.id DESC
    ');
    $recStmt->execute([':ledger_id' => $ledgerId]);
    $recurringList = $recStmt->fetchAll();
}

// 條件子句（預備陳述式，具名佔位符）
// 若指定單日（entry_date），則以該日篩選；否則以月份範圍篩選
if ($entryDate !== null) {
    $where  = 'WHERE e.ledger_id = :ledger_id AND e.deleted = 0 AND e.entry_date = :entryDate';
    $params = [':ledger_id' => $ledgerId, ':entryDate' => $entryDate];
} else {
    $where  = 'WHERE e.ledger_id = :ledger_id AND e.deleted = 0 AND e.entry_date BETWEEN :ms AND :me';
    $params = [':ledger_id' => $ledgerId, ':ms' => $monthStart, ':me' => $monthEnd];
}
if ($typeSel !== '') {
    $where .= ' AND e.type = :type';
    $params[':type'] = $typeSel;
}
if ($catSel !== '') {
    $where .= ' AND e.category = :category';
    $params[':category'] = $catSel;
}

// 符合條件總筆數 → 分頁
$stmt = $pdo->prepare("SELECT COUNT(*) FROM entries e $where");
$stmt->execute($params);
$totalEntries = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalEntries / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT e.id, e.entry_date, e.type, e.category, e.amount_cents, e.note, e.receipt_path, e.ack_status, e.ack_user_id, e.ack_at, e.user_id, u.username
    FROM entries e
    INNER JOIN users u ON u.id = e.user_id
    $where
    ORDER BY e.entry_date DESC, e.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll();

// 該帳本出現過的類別（供篩選下拉使用；查詢條件以 h() 輸出）
$catStmt = $pdo->prepare('SELECT DISTINCT category FROM entries WHERE ledger_id = :ledger_id AND deleted = 0 ORDER BY category');
$catStmt->execute([':ledger_id' => $ledgerId]);
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// 產生帶篩選的相對連結（沿用既有參數，覆寫指定鍵）
$filterBase = ['ledger_id' => $ledgerId, 'ym' => $ym];
if ($entryDate !== null) {
    $filterBase['entry_date'] = $entryDate;
}
if ($typeSel !== '') {
    $filterBase['type'] = $typeSel;
}
if ($catSel !== '') {
    $filterBase['category'] = $catSel;
}
$filterUrl = function (array $overrides = []) use ($filterBase) {
    return './ledger.php?' . http_build_query(array_merge($filterBase, $overrides));
};
$ymLabel = (new DateTimeImmutable($monthStart))->format('Y 年 n 月');
$isFiltered = ($typeSel !== '' || $catSel !== '' || $entryDate !== null);

// 側欄「這本帳」：帳本編號、名稱與成員名單（含角色），讓共用帳本的面孔一眼可見
$memberStmt = $pdo->prepare('SELECT u.username, la.role FROM ledger_access la INNER JOIN users u ON u.id = la.user_id WHERE la.ledger_id = :ledger_id ORDER BY u.username');
$memberStmt->execute([':ledger_id' => $ledgerId]);
$members = $memberStmt->fetchAll();

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msgRaw  = $_GET['msg'] ?? '';
$flashMsg = is_string($msgRaw) ? $msgRaw : ''; // 來自 redirect 的自訂提示文字（顯示時經 h() 轉義）
$canWrite = in_array($accessRole, ['owner', 'member'], true);

// 傳票號碼：新增記錄成功後以 lastInsertId 導回，組出與分錄表一致的編號（收-008／支-012）
$voucherIdRaw = $_GET['voucher'] ?? null;
$voucherId = filter_var($voucherIdRaw, FILTER_VALIDATE_INT);
$voucherType = $_GET['vtype'] ?? '';
$voucherMsg = '';
if ($voucherId !== false && $voucherId !== null && $voucherId > 0 && in_array($voucherType, ['income', 'expense'], true)) {
    $voucherNo = ($voucherType === 'income' ? '收-' : '支-') . str_pad((string) $voucherId, 3, '0', STR_PAD_LEFT);
    $voucherMsg = '傳票 ' . $voucherNo . ' 已立，請核對。';
}

// 語音確認回饋：檢查 session 標記，若為 1 則準備語音內容並隨即清除標記
$voiceText = '';
if (!empty($_SESSION['voice_played'])) {
    $voiceText = (string) ($_SESSION['voice_text'] ?? '');
    unset($_SESSION['voice_played']);
    unset($_SESSION['voice_text']);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>帳本詳情 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261115-8">
    <style>
        @media print {
            .side, .nav, .ctr-actions, .quick, .budget-form, .filters, .pager, .font-size-toggle {
                display: none !important;
            }
            .main { margin: 0; padding: 0; }
            .sheet { width: 100%; max-width: 100%; }
            .plates, .table-wrap { page-break-inside: avoid; }
            body { background: #fff; color: #000; }
            .plate { border: 1px solid #000; }
            .v { color: #000; }
        }
    </style>
</head>
<body data-voice-text="<?php echo h($voiceText); ?>">
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
                <a class="nav-item" href="./calendar.php">行事曆檢視</a>
                <a class="nav-item" href="./search.php">全站搜尋</a>
                <a class="nav-item" href="./change_password.php">變更密碼</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <p class="nav-h">後台</p>
                    <a class="nav-item" href="./admin/users.php">使用者管理</a>
                    <a class="nav-item" href="./admin/ledgers.php">帳本管理</a>
                    <a class="nav-item" href="./admin/audit_log.php">操作紀錄</a>
                <?php endif; ?>
            </nav>
            <div class="rail">
                <p class="nav-h">這本帳 No.<?php echo h(str_pad((string) $ledgerId, 3, '0', STR_PAD_LEFT)); ?></p>
                <div class="rail-name"><?php echo h($ledger['name']); ?></div>
                <ul class="rail-members">
                    <?php foreach ($members as $m): ?>
                        <li><span class="rail-user"><?php echo h($m['username']); ?></span><span class="role-chip small"><?php echo h($m['role']); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
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
                        <h1><?php echo h($ledger['name']); ?></h1>
                        <p class="sub">您的身份：<span class="role-tag <?php echo h($accessRole); ?>"><?php echo h($accessRole); ?></span>　·　檢視：<strong><?php echo h($ymLabel); ?></strong>　·　<a href="./ledgers.php">← 返回帳本列表</a></p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="./export_csv.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn">📥 匯出明細 CSV</a>
                        <?php if ($canWrite): ?>
                            <a href="./import_csv.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn">📤 匯入 CSV 明細</a>
                        <?php endif; ?>
                        <a href="./export_pdf.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn">📄 匯出 PDF 月結單</a>
                        <a href="./yearly_report.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn">📊 年度報表</a>
                        <a href="./quarterly_report.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn">📈 季度報表</a>
                        <a href="./comparison_report.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn">📊 收支比較報表</a>
                        <?php if ($canWrite): ?>
                            <a href="./add_entry.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn primary">＋ 立傳票（新增記錄）</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($flashMsg !== '' ? $flashMsg : ($voucherMsg !== '' ? $voucherMsg : '操作成功完成。')); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($flashMsg !== '' ? $flashMsg : '操作失敗：記錄不存在或您沒有該帳本的權限。'); ?></p>
                <?php endif; ?>

                <div class="plates">
                    <div class="plate in"><small><?php echo h($ymLabel); ?>收入</small><div class="v">+<?php echo h(formatCents($incomeCents)); ?></div></div>
                    <div class="plate out"><small><?php echo h($ymLabel); ?>支出</small><div class="v">-<?php echo h(formatCents($expenseCents)); ?></div></div>
                    <div class="plate net"><small><?php echo h($ymLabel); ?>結餘</small><div class="v"><?php echo h($balanceCents >= 0 ? '+' : '') . h(formatCents($balanceCents)); ?></div></div>
                </div>

                <?php
                // 預算超支橫幅：僅當月且預算>0且支出≥80%時顯示（比照 dashboard.php 邏輯）
                if ($budgetProgress !== null && in_array($budgetProgress['status'], ['warn', 'danger'], true)): ?>
                <div class="budget-banner budget-banner-<?php echo h($budgetProgress['status']); ?>" role="alert">
                    <strong><?php echo h($ledger['name']); ?></strong> 本月預算已使用 <strong class="num"><?php echo h((string) $budgetProgress['percent']); ?>%</strong>：
                    <?php echo $budgetProgress['status'] === 'danger' ? '已超過預算' : '接近預算上限'; ?>
                    （預算 <?php echo h(formatCents($budgetCents)); ?> 元，已支出 <strong class="num"><?php echo h(formatCents($expenseCents)); ?></strong> 元）
                </div>
                <?php endif; ?>

                <?php if ($budgetProgress !== null): ?>
                    <?php
                        $barWidth = min(100, $budgetProgress['percent']); // 進度條寬度上限 100%
                        $budgetRemain = $budgetProgress['remaining'];
                    ?>
                    <div class="budget-box budget-<?php echo h($budgetProgress['status']); ?>" role="status">
                        <div class="budget-head">
                            <span class="budget-title">本月預算 <?php echo h(formatCents($budgetCents)); ?> 元</span>
                            <?php if ($budgetProgress['status'] === 'danger'): ?>
                                <span class="budget-chip danger">已超過預算</span>
                            <?php elseif ($budgetProgress['status'] === 'warn'): ?>
                                <span class="budget-chip warn">接近預算上限</span>
                            <?php else: ?>
                                <span class="budget-chip ok">預算尚充足</span>
                            <?php endif; ?>
                        </div>
                        <div class="budget-bar" aria-hidden="true"><span class="budget-fill" style="width: <?php echo h((string) $barWidth); ?>%;"></span></div>
                        <p class="budget-meta">
                            已花費 <strong class="mono">-<?php echo h(formatCents($expenseCents)); ?></strong> 元 ·
                            用了 <strong class="mono"><?php echo h((string) $budgetProgress['percent']); ?>%</strong> ·
                            <?php if ($budgetRemain >= 0): ?>
                                還剩 <strong class="mono"><?php echo h(formatCents($budgetRemain)); ?></strong> 元
                            <?php else: ?>
                                <strong>已超支 <?php echo h(formatCents(-$budgetRemain)); ?> 元</strong>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($budgetProgress !== null && in_array($budgetProgress['status'], ['warn', 'danger'], true)): ?>
                <div class="alert <?php echo $budgetProgress['status'] === 'danger' ? 'danger' : 'warn'; ?>" role="status">
                    <?php echo h($ledger['name']); ?> 本月預算已使用
                    <strong class="num"><?php echo h((string) $budgetProgress['percent']); ?>%</strong>：
                    <?php echo $budgetProgress['status'] === 'danger' ? '已超過預算' : '接近預算上限'; ?>
                </div>
                <?php endif; ?>

                <?php if ($accessRole === 'owner'): ?>
                    <form method="POST" action="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="budget-form">
                        <label class="budget-form-label" for="monthly_budget">每月預算（元，清空即取消）</label>
                        <div class="budget-form-row">
                            <input type="text" inputmode="decimal" name="monthly_budget" id="monthly_budget" class="input mono budget-form-input"
                                   value="<?php echo h($budgetInputValue); ?>" placeholder="例：30000">
                            <button type="submit" class="btn sm">設定</button>
                        </div>
                        <input type="hidden" name="action" value="set_budget">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                    </form>
                <?php endif; ?>

                <!-- 重複記錄：固定每月開銷（Feature 11） -->
                <?php if ($accessRole === 'owner' || $accessRole === 'admin'): ?>
                <div class="recurring-card">
                    <h3>建立重複記錄</h3>
                    <form method="POST" action="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                        <input type="hidden" name="action" value="create_recurring">
                        <div class="form-grid">
                            <div class="field field-full">
                                <label for="recurring_name" class="label">名稱</label>
                                <input type="text" name="name" id="recurring_name" required class="input" placeholder="例如 水費、電費、房租">
                            </div>
                            <div class="field">
                                <label for="recurring_amount" class="label">金額（元）</label>
                                <input type="text" name="amount" id="recurring_amount" required class="input mono" inputmode="decimal" pattern="\d+(\.\d{1,2})?" placeholder="例如 100 或 100.50">
                            </div>
                            <div class="field">
                                <label for="recurring_type" class="label">類型</label>
                                <select name="type" id="recurring_type" class="select">
                                    <option value="expense">支出聯（藍）</option>
                                    <option value="income">收入聯（紅）</option>
                                </select>
                            </div>
                            <div class="field field-full">
                                <label for="recurring_category" class="label">類別</label>
                                <input type="text" name="category" id="recurring_category" class="input" placeholder="例如 水電瓦斯">
                            </div>
                            <div class="field">
                                <label for="recurring_start_date" class="label">起始日期</label>
                                <input type="date" name="start_date" id="recurring_start_date" required class="input mono" value="<?php echo h($today->format('Y-m-d')); ?>">
                            </div>
                            <div class="field">
                                <label for="recurring_next_due" class="label">下次預計記錄日期</label>
                                <input type="date" name="next_due_date" id="recurring_next_due" required class="input mono" value="<?php echo h($today->modify('+1 month')->format('Y-m-d')); ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn primary">建立重複記錄</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (!empty($recurringList)): ?>
                <div class="recurring-card">
                    <h3>固定開銷列表</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>名稱</th>
                                <th class="num">金額</th>
                                <th>類別</th>
                                <th>類型</th>
                                <th class="num">下次預計日期</th>
                                <th class="ctr">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recurringList as $rec): ?>
                            <tr>
                                <td><?php echo h($rec['name']); ?></td>
                                <td class="num <?php echo $rec['type'] === 'income' ? 'amt-in' : 'amt-out'; ?>"><?php echo h($rec['type'] === 'income' ? '+' : '-') . h(formatCents((int) $rec['amount_cents'])); ?></td>
                                <td><?php echo h($rec['category']); ?></td>
                                <td><?php echo h($rec['type'] === 'income' ? '收入' : '支出'); ?></td>
                                <td class="num mono"><?php echo h($rec['next_due_date']); ?></td>
                                <td class="ctr">
                                    <?php $isRecurringOwner = ((int) $rec['user_id'] === $userId); ?>
                                    <?php if ($isRecurringOwner || $accessRole === 'owner' || $accessRole === 'admin'): ?>
                                    <div class="ctr-actions">
                                        <button type="button" class="btn secondary sm" data-recurring-id="<?php echo (int) $rec['id']; ?>" data-name="<?php echo h($rec['name']); ?>" data-amount-cents="<?php echo h((string) $rec['amount_cents']); ?>" data-type="<?php echo h($rec['type']); ?>" data-category="<?php echo h($rec['category']); ?>" data-start-date="<?php echo h($rec['start_date']); ?>" data-next-due-date="<?php echo h($rec['next_due_date']); ?>" onclick="openEditRecurringFromElement(this);">編輯</button>
                                        <form method="POST" action="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" onsubmit="return confirm('確定要停用這個重複記錄嗎？');" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                            <input type="hidden" name="action" value="toggle_recurring">
                                            <input type="hidden" name="recurring_id" value="<?php echo h((string) $rec['id']); ?>">
                                            <button type="submit" class="btn danger sm">停用</button>
                                        </form>
                                        <form method="POST" action="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                            <input type="hidden" name="action" value="generate_recurring">
                                            <input type="hidden" name="recurring_id" value="<?php echo h((string) $rec['id']); ?>">
                                            <button type="submit" class="btn primary sm">立即記一筆</button>
                                        </form>
                                    </div>
                                    <?php else: ?>
                                    <span class="meta">檢視者</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <form method="GET" action="./ledger.php" class="filters" aria-label="查帳篩選">
                    <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledgerId); ?>">
                    <label class="filter">
                        <span class="filter-label">月份</span>
                        <input type="month" name="ym" class="input mono filter-input" value="<?php echo h($ym); ?>">
                    </label>
                    <label class="filter">
                        <span class="filter-label">類別</span>
                        <select name="category" class="select filter-input">
                            <option value="">全部類別</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo h($cat); ?>"<?php echo $cat === $catSel ? ' selected' : ''; ?>><?php echo h($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="filter">
                        <span class="filter-label">類型</span>
                        <select name="type" class="select filter-input">
                            <option value="">全部</option>
                            <option value="expense"<?php echo $typeSel === 'expense' ? ' selected' : ''; ?>>支出聯（藍）</option>
                            <option value="income"<?php echo $typeSel === 'income' ? ' selected' : ''; ?>>收入聯（紅）</option>
                            <option value="transfer"<?php echo $typeSel === 'transfer' ? ' selected' : ''; ?>>轉帳</option>
                        </select>
                    </label>
                    <button type="submit" class="btn primary sm">查帳</button>
                    <a class="btn secondary sm" href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>">清除</a>
                </form>

                <?php
                // === 類別預算查詢（新增）===
                // 權限已在檔案開頭驗證（$ledger 含 role、archived=0），此處直接用 $ledgerId 查詢
                $categoryBudgets = [];
                $budgetStmt = $pdo->prepare('
                    SELECT category, monthly_budget_cents
                    FROM category_budgets
                    WHERE ledger_id = :ledger_id AND is_active = 1
                ');
                $budgetStmt->execute([':ledger_id' => $ledgerId]);
                while ($row = $budgetStmt->fetch()) {
                    $categoryBudgets[$row['category']] = (int) $row['monthly_budget_cents'];
                }

                // 類別統計 SVG 圖表：本月各類別支出佔比
                $categoryStats = [];
                $categoryTotalCents = 0;
                $categoryBudgetStatus = []; // ['category' => ['expense_cents', 'budget_cents', 'percent', 'status']]
                if (!$isFiltered && $typeSel !== 'income') {
                    $catStmt = $pdo->prepare('
                        SELECT e.category, SUM(e.amount_cents) AS total_cents
                        FROM entries e
                        INNER JOIN ledger_access la ON la.ledger_id = e.ledger_id AND la.user_id = :user_id
                        WHERE e.ledger_id = :ledger_id
                          AND e.deleted = 0
                          AND e.type = :type
                          AND e.entry_date BETWEEN :ms AND :me
                        GROUP BY e.category
                        ORDER BY total_cents DESC
                    ');
                    $catStmt->execute([
                        ':user_id' => $userId,
                        ':ledger_id' => $ledgerId,
                        ':type' => 'expense',
                        ':ms' => $monthStart,
                        ':me' => $monthEnd,
                    ]);
                    while ($catRow = $catStmt->fetch()) {
                        $cents = (int) $catRow['total_cents'];
                        $cat = $catRow['category'];
                        $categoryStats[] = ['category' => $cat, 'cents' => $cents];
                        $categoryTotalCents += $cents;

                        // 類別預算比較（新增）
                        if (isset($categoryBudgets[$cat])) {
                            $budgetCents = $categoryBudgets[$cat];
                            if ($budgetCents > 0) {
                                $status = calcBudgetStatus($cents, $budgetCents);
                                $percent = getBudgetPercent($cents, $budgetCents);
                                $categoryBudgetStatus[$cat] = [
                                    'expense_cents' => $cents,
                                    'budget_cents' => $budgetCents,
                                    'percent' => $percent,
                                    'status' => $status,
                                ];
                            }
                        }
                    }
                }
                ?>
                <?php if ($categoryTotalCents > 0): ?>
                <div class="category-stats" aria-label="本月類別支出統計">
                    <h3>本月類別支出統計</h3>
                    <?php
                    try {
                    ?>
                    <svg class="category-chart" viewBox="0 0 600 300" role="img" aria-labelledby="category-chart-title">
                        <title id="category-chart-title">本月各類別支出佔比</title>
                        <?php
                        $barHeight = 30;
                        $gap = 10;
                        $maxWidth = 500;
                        $y = 20;
                        foreach ($categoryStats as $stat) {
                            $percent = $categoryTotalCents > 0 ? ($stat['cents'] / $categoryTotalCents) * 100 : 0;
                            $barWidth = $maxWidth * ($percent / 100);
                            $label = h($stat['category']);
                            $amount = h(formatCents($stat['cents']));
                            echo '<rect x="80" y="' . (int) $y . '" width="' . (int) $barWidth . '" height="' . (int) $barHeight . '" fill="var(--blue)" rx="2" />';
                            echo '<text x="75" y="' . (int) ($y + $barHeight / 2 + 5) . '" text-anchor="end" font-size="12" fill="#23272b">' . $label . '</text>';
                            echo '<text x="' . (int) (80 + $barWidth + 5) . '" y="' . (int) ($y + $barHeight / 2 + 5) . '" font-size="12" fill="#23272b">' . $amount . ' 元 (' . (int) $percent . '%)</text>';
                            $y += $barHeight + $gap;
                        }
                        ?>
                    </svg>
                    <?php
                    } catch (Throwable $e) {
                        logAudit('category_svg_fallback', 'ledger_id=' . $ledgerId . ' error=' . $e->getMessage());
                    ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>類別</th>
                                    <th class="num">金額</th>
                                    <th class="num">佔比</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoryStats as $stat): ?>
                                <tr>
                                    <td><?php echo h($stat['category']); ?></td>
                                    <td class="num amt-out mono">-<?php echo h(formatCents($stat['cents'])); ?></td>
                                    <td class="num mono"><?php echo h((string) ($categoryTotalCents > 0 ? (int) (($stat['cents'] / $categoryTotalCents) * 100) : 0)); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    }
                    ?>
                </div>
                <?php if (!empty($categoryBudgetStatus)): ?>
                <div class="category-budget-status" aria-label="類別預算狀態">
                    <h3>類別預算狀態</h3>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>類別</th>
                                    <th class="num">本月支出</th>
                                    <th class="num">預算金額</th>
                                    <th class="num">使用率</th>
                                    <th class="ctr">狀態</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoryBudgetStatus as $cat => $bs): ?>
                                <tr>
                                    <td><?php echo h($cat); ?></td>
                                    <td class="num amt-out mono">-<?php echo h(formatCents($bs['expense_cents'])); ?></td>
                                    <td class="num mono"><?php echo h(formatCents($bs['budget_cents'])); ?></td>
                                    <td class="num mono"><?php echo h((string) $bs['percent']); ?>%</td>
                                    <td class="ctr">
                                        <span class="budget-chip <?php echo h($bs['status']); ?>">
                                            <?php
                                                echo $bs['status'] === 'danger' ? '已超過預算' :
                                                     ($bs['status'] === 'warn' ? '接近預算上限' : '預算尚充足');
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php elseif (!$isFiltered && $typeSel !== 'income' && $totalEntries === 0): ?>
                <p class="empty">尚無支出紀錄。</p>
                <?php endif; ?>

                <h3><?php echo $isFiltered ? '符合條件的記錄' : '分錄記錄'; ?><span class="h3-meta">共 <?php echo h((string) $totalEntries); ?> 筆 · 每頁 50 筆 · 第 <?php echo h((string) $page); ?>/<?php echo h((string) $totalPages); ?> 頁</span></h3>
                <?php if ($entries): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>編號</th>
                                    <th>日期</th>
                                    <th>成員</th>
                                    <th>類別</th>
                                    <th class="num">金額</th>
                                    <th class="receipt-col hide-m">收據</th>
                                    <th class="hide-m">備註</th>
                                    <th class="ctr">確認狀態</th>
                                    <th class="ctr">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $entry): ?>
                                    <tr>
                                        <td><span class="vno <?php echo $entry['type'] === 'income' ? 'in' : ($entry['type'] === 'expense' ? 'out' : 'plain'); ?>"><?php echo $entry['type'] === 'income' ? '收' : ($entry['type'] === 'expense' ? '支' : '轉'); ?>-<?php echo h(str_pad((string) $entry['id'], 3, '0', STR_PAD_LEFT)); ?></span></td>
                                        <td class="mono"><?php echo h($entry['entry_date']); ?></td>
                                        <td><?php echo h($entry['username']); ?></td>
                                        <td><?php echo h($entry['category']); ?></td>
                                        <td class="num <?php echo $entry['type'] === 'income' ? 'amt-in' : ($entry['type'] === 'expense' ? 'amt-out' : 'amt-neutral'); ?>"><?php echo h(($entry['type'] === 'income' ? '+' : ($entry['type'] === 'expense' ? '-' : '')) . formatCents((int) $entry['amount_cents'])); ?></td>
                                        <td class="receipt-col hide-m ctr">
                                            <?php if (!empty($entry['receipt_path'])): ?>
                                                <a href="./view_receipt.php?id=<?php echo h((string) $entry['id']); ?>" class="receipt-thumb" target="_blank" rel="noopener noreferrer" title="檢視收據">
                                                    <img src="./view_receipt.php?id=<?php echo h((string) $entry['id']); ?>&mode=inline" alt="收據" loading="lazy" width="48" height="48">
                                                </a>
                                            <?php else: ?>
                                                <span class="meta">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="hide-m"><?php echo h($entry['note']); ?></td>
                                        <td class="ctr">
                                            <?php
                                            $ackStatus = $entry['ack_status'] ?? 'pending';
                                            $ackClass = match ($ackStatus) {
                                                'confirmed' => 'ack-confirmed',
                                                'disputed'  => 'ack-disputed',
                                                default     => 'ack-pending',
                                            };
                                            $ackLabel = match ($ackStatus) {
                                                'confirmed' => '已確認',
                                                'disputed'  => '有爭議',
                                                default     => '待確認',
                                            };
                                            ?>
                                            <span class="ack-chip <?php echo h($ackClass); ?>"><?php echo h($ackLabel); ?></span>
                                        </td>
                                        <td class="ctr">
                                            <?php
                                            $isEntryCreator = ((int) $entry['user_id'] === $userId);
                                            $isLedgerOwner  = ($accessRole === 'owner');
                                            $canAck = $isEntryCreator || $isLedgerOwner;
                                            ?>
                                            <?php if ($canAck): ?>
                                            <div class="ctr-actions">
                                                <form method="POST" action="./entry_ack.php" style="display:inline;" onsubmit="return confirm('確定要確認這筆記錄是您花的嗎？');">
                                                    <input type="hidden" name="entry_id" value="<?php echo h((string) $entry['id']); ?>">
                                                    <input type="hidden" name="action" value="confirm">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                    <button type="submit" class="btn secondary sm">確認</button>
                                                </form>
                                                <form method="POST" action="./entry_ack.php" style="display:inline;" onsubmit="return confirm('確定要標記這筆記錄為有爭議嗎？');">
                                                    <input type="hidden" name="entry_id" value="<?php echo h((string) $entry['id']); ?>">
                                                    <input type="hidden" name="action" value="dispute">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                    <button type="submit" class="btn danger sm">爭議</button>
                                                </form>
                                                <a href="./edit_entry.php?ledger_id=<?php echo h((string) $ledgerId); ?>&id=<?php echo h((string) $entry['id']); ?>" class="btn secondary sm">編輯</a>
                                                <form method="POST" action="./delete_entry.php" onsubmit="return confirm('確定要作廢這筆記錄嗎？');" style="display:inline;">
                                                    <input type="hidden" name="id" value="<?php echo h((string) $entry['id']); ?>">
                                                    <input type="hidden" name="ledger_id" value="<?php echo h((string) $ledgerId); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                    <button type="submit" class="btn danger sm">作廢</button>
                                                </form>
                                            </div>
                                            <?php else: ?>
                                            <span class="meta">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pager" aria-label="分頁">
                            <?php if ($page > 1): ?>
                                <a class="btn secondary sm" href="<?php echo h($filterUrl(['page' => $page - 1])); ?>">← 上一頁</a>
                            <?php else: ?><span></span><?php endif; ?>
                            <span class="pager-info">第 <?php echo h((string) $page); ?> / <?php echo h((string) $totalPages); ?> 頁</span>
                            <?php if ($page < $totalPages): ?>
                                <a class="btn secondary sm" href="<?php echo h($filterUrl(['page' => $page + 1])); ?>">下一頁 →</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="empty"><?php echo $isFiltered ? '沒有符合篩選條件的記錄。調整月份或清除篩選再試。' : '尚無記錄。按上方「立傳票」新增第一筆。'; ?></p>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($voiceText !== ''): ?>
    <?php endif; ?>

    <!-- 編輯重複記錄表單（隱藏，由 JavaScript 填入數據） -->
    <div id="editRecurringModal" style="display:none;">
        <form method="POST" action="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="form">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
            <input type="hidden" name="action" value="edit_recurring">
            <input type="hidden" name="recurring_id" id="edit_recurring_id">
            <h3>編輯重複記錄</h3>
            <div class="form-grid">
                <div class="field field-full">
                    <label for="edit_recurring_name" class="label">名稱</label>
                    <input type="text" name="name" id="edit_recurring_name" required class="input">
                </div>
                <div class="field">
                    <label for="edit_recurring_amount" class="label">金額（元）</label>
                    <input type="text" name="amount" id="edit_recurring_amount" required class="input mono" inputmode="decimal" pattern="\d+(\.\d{1,2})?">
                </div>
                <div class="field">
                    <label for="edit_recurring_type" class="label">類型</label>
                    <select name="type" id="edit_recurring_type" class="select">
                        <option value="expense">支出聯（藍）</option>
                        <option value="income">收入聯（紅）</option>
                    </select>
                </div>
                <div class="field field-full">
                    <label for="edit_recurring_category" class="label">類別</label>
                    <input type="text" name="category" id="edit_recurring_category" class="input">
                </div>
                <div class="field">
                    <label for="edit_recurring_start_date" class="label">起始日期</label>
                    <input type="date" name="start_date" id="edit_recurring_start_date" required class="input mono">
                </div>
                <div class="field">
                    <label for="edit_recurring_next_due" class="label">下次預計記錄日期</label>
                    <input type="date" name="next_due_date" id="edit_recurring_next_due" required class="input mono">
                </div>
            </div>
            <div style="margin-top: var(--space-4); display:flex; gap: var(--space-3);">
                <button type="submit" class="btn primary">儲存變更</button>
                <button type="button" class="btn secondary" onclick="closeEditRecurring()">取消</button>
            </div>
        </form>
    </div>

    <style>
        #editRecurringModal {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(35, 39, 43, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        #editRecurringModal .form {
            background: #fffdf1;
            padding: var(--space-6);
            border-radius: var(--radius);
            border: 1px solid var(--line);
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        #editRecurringModal h3 {
            margin-top: 0;
        }
    </style>
    <script src="./assets/app.js"></script>
</body>
</html>
