<?php
// 全家人記帳系統 — 類別預算設定（僅帳本擁有者）
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

requireLogin();
$user = currentUser();
$pdo  = getPdo();
$userId = (int) $user['id'];

// 解析 ledger_id
$ledgerIdRaw = $_GET['ledger_id'] ?? null;
$ledgerId = filter_var($ledgerIdRaw, FILTER_VALIDATE_INT);
if ($ledgerId === false || $ledgerId === null) {
    redirect('./ledgers.php?error=1');
}

// 驗證存取權限：owner/member/viewer 皆可檢視（含 archived 檢查）
$stmt = $pdo->prepare('
    SELECT l.name, la.role
    FROM ledgers l
    INNER JOIN ledger_access la ON la.ledger_id = l.id
    WHERE l.id = :ledger_id AND la.user_id = :user_id AND l.archived = 0
    LIMIT 1
');
$stmt->execute([':ledger_id' => $ledgerId, ':user_id' => $userId]);
$ledger = $stmt->fetch();
if ($ledger === false) {
    redirect('./ledgers.php?error=1');
}
$accessRole = $ledger['role']; // 'owner' | 'member' | 'viewer'
$isOwner = ($accessRole === 'owner');

// 寫入操作（POST）僅允許 owner
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$isOwner) {
    redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('僅帳本擁有者可管理類別預算。'));
}

// 審計日誌：檢視類別預算頁面（GET）
logAudit('category_budget_view', 'ledger_id=' . $ledgerId . ' user_id=' . $userId);

// POST 處理：新增/編輯/停用
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfRaw = $_POST['csrf_token'] ?? '';
    if (!checkCsrf(is_string($csrfRaw) ? $csrfRaw : '')) {
        redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('安全驗證失敗，請重新操作。'));
    }

    if ($action === 'create' || $action === 'update') {
        $category = trim($_POST['category'] ?? '');
        $budgetRaw = trim($_POST['monthly_budget'] ?? '');
        $budgetCents = toCents($budgetRaw);
        // 類別驗證：長度 ≤ 50、字元白名單（中英數、空格、-、_）
        if ($category === '' || mb_strlen($category, 'UTF-8') > 50 || !preg_match('/^[\p{L}\p{N}\s\-_]{1,50}$/u', $category)) {
            redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('類別名稱格式不正確（限 50 字內，中英數、空格、-、_）。'));
        }
        if ($budgetCents === false || $budgetCents <= 0) {
            redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('預算金額格式不正確，請輸入大於 0 的金額。'));
        }

        if ($action === 'create') {
            // 檢查是否已存在啟用中同類別預算（應用層檢查，雙重保障）
            $chk = $pdo->prepare('SELECT id FROM category_budgets WHERE ledger_id = :ledger_id AND category = :category AND is_active = 1 LIMIT 1');
            $chk->execute([':ledger_id' => $ledgerId, ':category' => $category]);
            if ($chk->fetch()) {
                redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('此類別預算已存在，請編輯現有記錄。'));
            }
            $stmt = $pdo->prepare('
                INSERT INTO category_budgets (ledger_id, category, monthly_budget_cents, is_active, updated_at)
                VALUES (:ledger_id, :category, :budget, 1, datetime("now","localtime"))
            ');
            // [#26] 捕獲唯一約束違反：雙重錯誤碼、獨立失敗審計、通用錯誤訊息、不 throw
            try {
                $stmt->execute([':ledger_id' => $ledgerId, ':category' => $category, ':budget' => $budgetCents]);
                logAudit('category_budget_create', 'ledger_id=' . $ledgerId . ' category=' . $category . ' budget_cents=' . $budgetCents);
            } catch (PDOException $e) {
                // 唯一約束違反 (SQLite: code 23000 / errorInfo[1] = 1555)
                if ($e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1555) {
                    logAudit('category_budget_create_conflict', "ledger_id=$ledgerId category=$category reason=unique_constraint");
                    redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('此類別預算已存在，請編輯現有記錄。'));
                    return;
                }
                // 其他資料庫錯誤：記錄細節，導向通用錯誤頁，不外洩堆疊/ SQL
                logError('category_budget_create PDOException: ' . $e->getMessage()
                    . ' | SQL: INSERT INTO category_budgets ...'
                    . ' | Params: ledger_id=' . $ledgerId . ' category=' . $category . ' budget=' . $budgetCents);
                redirect('./error.php?code=db_error');
                return;
            }
        } else { // update
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false || $id === null) {
                redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('無效的預算編號。'));
            }
            $stmt = $pdo->prepare('
                UPDATE category_budgets
                SET monthly_budget_cents = :budget, updated_at = datetime("now","localtime")
                WHERE id = :id AND ledger_id = :ledger_id
            ');
            $stmt->execute([':budget' => $budgetCents, ':id' => $id, ':ledger_id' => $ledgerId]);
            logAudit('category_budget_update', 'ledger_id=' . $ledgerId . ' id=' . $id . ' budget_cents=' . $budgetCents);
        }
        redirect('./category_budget.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('類別預算已儲存。'));
    }

    if ($action === 'disable') {
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if ($id === false || $id === null) {
            redirect('./category_budget.php?ledger_id=' . $ledgerId . '&error=1&msg=' . urlencode('無效的預算編號。'));
        }
        $stmt = $pdo->prepare('
            UPDATE category_budgets
            SET is_active = 0, updated_at = datetime("now","localtime")
            WHERE id = :id AND ledger_id = :ledger_id
        ');
        $stmt->execute([':id' => $id, ':ledger_id' => $ledgerId]);
        logAudit('category_budget_disable', 'ledger_id=' . $ledgerId . ' id=' . $id);
        redirect('./category_budget.php?ledger_id=' . $ledgerId . '&success=1&msg=' . urlencode('類別預算已停用。'));
    }
}

// GET：查詢現有類別預算列表
$budgetStmt = $pdo->prepare('
    SELECT id, category, monthly_budget_cents, is_active, created_at, updated_at
    FROM category_budgets
    WHERE ledger_id = :ledger_id AND is_active = 1
    ORDER BY category
');
$budgetStmt->execute([':ledger_id' => $ledgerId]);
$budgets = $budgetStmt->fetchAll();

// 查詢全站啟用中的分類（供 select 選項；參考 categories 表）
$catStmt = $pdo->prepare('SELECT name FROM categories WHERE is_active = 1 ORDER BY name');
$catStmt->execute();
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// 編輯模式：若有 edit_id 參數，預填表單
$editBudget = null;
$editIdRaw = $_GET['edit_id'] ?? null;
$editId = filter_var($editIdRaw, FILTER_VALIDATE_INT);
if ($editId !== false && $editId !== null) {
    $editStmt = $pdo->prepare('SELECT id, category, monthly_budget_cents FROM category_budgets WHERE id = :id AND ledger_id = :ledger_id LIMIT 1');
    $editStmt->execute([':id' => $editId, ':ledger_id' => $ledgerId]);
    $editBudget = $editStmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>類別預算設定 - 全家人記帳系統</title>
    <link rel="stylesheet" href="./assets/style.css?v=20261122-1">
</head>
<body>
    <div class="app">
        <!-- 側欄 -->
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
                <a class="nav-item" href="./multi_currency.php">多幣別記帳</a>
                <a class="nav-item" href="./calendar.php">行事曆檢視</a>
                <a class="nav-item active" href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>">帳本詳情</a>
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
                        <h1>類別預算設定</h1>
                        <p class="sub"><?php echo h($ledger['name']); ?>　·　<a href="./ledger.php?ledger_id=<?php echo h((string) $ledgerId); ?>">← 返回帳本詳情</a></p>
                    </div>
                </div>

                <?php if (($_GET['success'] ?? '') === '1'): ?>
                    <p class="alert success" role="status"><?php echo h($_GET['msg'] ?? '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if (($_GET['error'] ?? '') === '1'): ?>
                    <p class="alert error" role="alert"><?php echo h($_GET['msg'] ?? '操作失敗。'); ?></p>
                <?php endif; ?>

                <!-- 新增/編輯表單：僅 owner 顯示 -->
                <?php if ($isOwner): ?>
                <div class="recurring-card">
                    <h3><?php echo $editBudget ? '編輯類別預算' : '新增類別預算'; ?></h3>
                    <form method="POST" action="./category_budget.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="form">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                        <input type="hidden" name="action" value="<?php echo $editBudget ? 'update' : 'create'; ?>">
                        <?php if ($editBudget): ?>
                            <input type="hidden" name="id" value="<?php echo h((string) $editBudget['id']); ?>">
                        <?php endif; ?>
                        <div class="form-grid">
                            <div class="field field-full">
                                <label for="category" class="label">類別</label>
                                <?php if ($editBudget): ?>
                                    <select name="category" id="category" class="select" required disabled>
                                        <option value="<?php echo h($editBudget['category']); ?>" selected><?php echo h($editBudget['category']); ?></option>
                                    </select>
                                    <input type="hidden" name="category" value="<?php echo h($editBudget['category']); ?>">
                                    <p class="meta">編輯模式下類別不可變更，請停用後重新新增。</p>
                                <?php else: ?>
                                    <select name="category" id="category" class="select" required>
                                        <option value="">請選擇類別</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo h($cat); ?>"><?php echo h($cat); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div class="field field-full">
                                <label for="monthly_budget" class="label">每月預算（元）</label>
                                <input type="text" name="monthly_budget" id="monthly_budget" class="input mono" inputmode="decimal" pattern="\d+(\.\d{1,2})?" placeholder="例：6000" required
                                    value="<?php echo $editBudget ? h(formatCents((int) $editBudget['monthly_budget_cents'])) : ''; ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn primary"><?php echo $editBudget ? '更新' : '設定'; ?></button>
                        <?php if ($editBudget): ?>
                            <a href="./category_budget.php?ledger_id=<?php echo h((string) $ledgerId); ?>" class="btn secondary" style="margin-left: var(--space-2);">取消編輯</a>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>

                <!-- 現有類別預算列表 -->
                <?php if ($budgets): ?>
                <div class="recurring-card">
                    <h3>現有類別預算</h3>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>類別</th>
                                    <th class="num">每月預算</th>
                                    <th class="ctr">狀態</th>
                                    <?php if ($isOwner): ?>
                                    <th class="ctr">操作</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($budgets as $b): ?>
                                <tr>
                                    <td><?php echo h($b['category']); ?></td>
                                    <td class="num mono"><?php echo h(formatCents((int) $b['monthly_budget_cents'])); ?> 元</td>
                                    <td class="ctr"><span class="budget-chip ok">啟用中</span></td>
                                    <?php if ($isOwner): ?>
                                    <td class="ctr">
                                        <div class="ctr-actions">
                                            <a href="./category_budget.php?ledger_id=<?php echo h((string) $ledgerId); ?>&edit_id=<?php echo h((string) $b['id']); ?>" class="btn secondary sm">編輯</a>
                                            <form method="POST" action="./category_budget.php?ledger_id=<?php echo h((string) $ledgerId); ?>" onsubmit="return confirm('確定要停用這個類別預算嗎？');" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                <input type="hidden" name="action" value="disable">
                                                <input type="hidden" name="id" value="<?php echo h((string) $b['id']); ?>">
                                                <button type="submit" class="btn danger sm">停用</button>
                                            </form>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <p class="empty">
                    <?php if ($isOwner): ?>
                        尚無類別預算。請上方新增第一筆。
                    <?php else: ?>
                        尚無類別預算。
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <script src="./assets/app.js"></script>
</body>
</html>