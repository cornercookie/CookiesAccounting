<?php
// 全家人記帳系統 — 後台：分類管理（僅管理員可存取）
// 新增、編輯、停用（軟刪除）、合併常用分類；全站共用分類（ledger_id 固定為 NULL）。
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

// 僅管理員可存取後台頁面
requireAdmin();

$user = currentUser();
$pdo  = getPdo();
$myId = (int) $user['id'];

$success = (($_GET['success'] ?? '') === '1');
$error   = (($_GET['error'] ?? '') === '1');
$msg     = $_GET['msg'] ?? '';

// ---------------- POST：處理所有動作（CSRF 驗證 + 預備陳述式）---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf_token'] ?? '')) {
        redirect('./category_management.php?error=1&msg=' . urlencode('請求驗證失敗，請重新提交表單。'));
    }

    $action = $_POST['action'] ?? '';

    // ---------- 新增分類 ----------
    if ($action === 'create_category') {
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            redirect('./category_management.php?error=1&msg=' . urlencode('請輸入分類名稱。'));
        }
        if (mb_strlen($name, 'UTF-8') > 50) {
            redirect('./category_management.php?error=1&msg=' . urlencode('分類名稱最多 50 個字元。'));
        }

        // 檢查名稱是否重複（全站唯一）
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        if ($stmt->fetch() !== false) {
            redirect('./category_management.php?error=1&msg=' . urlencode('該分類名稱已經存在，請使用其他名稱。'));
        }

        $now = date('Y-m-d H:i:s'); // 應用層寫入，依賴 config.php 已設定 Asia/Taipei
        $stmt = $pdo->prepare(
            'INSERT INTO categories (name, is_active, ledger_id, created_at, updated_at)
             VALUES (:name, 1, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name'        => $name,
            ':created_at'  => $now,
            ':updated_at'  => $now,
        ]);
        $newCategoryId = (int) $pdo->lastInsertId();
        logAudit('category_create', 'category_id=' . $newCategoryId . ' name=' . $name);
        redirect('./category_management.php?success=1&msg=' . urlencode('分類「' . $name . '」已新增。'));
    }

    // ---------- 編輯分類 ----------
    if ($action === 'edit_category') {
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $name       = trim($_POST['name'] ?? '');

        if ($categoryId === false || $categoryId === null) {
            redirect('./category_management.php?error=1&msg=' . urlencode('指定的分類不存在。'));
        }
        if ($name === '') {
            redirect('./category_management.php?error=1&msg=' . urlencode('請輸入分類名稱。'));
        }
        if (mb_strlen($name, 'UTF-8') > 50) {
            redirect('./category_management.php?error=1&msg=' . urlencode('分類名稱最多 50 個字元。'));
        }

        // 檢查分類是否存在
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $categoryId]);
        $category = $stmt->fetch();
        if ($category === false) {
            redirect('./category_management.php?error=1&msg=' . urlencode('指定的分類不存在。'));
        }

        // 檢查名稱是否與其他分類重複（排除自身）
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = :name AND id <> :id LIMIT 1');
        $stmt->execute([':name' => $name, ':id' => $categoryId]);
        if ($stmt->fetch() !== false) {
            redirect('./category_management.php?error=1&msg=' . urlencode('該分類名稱已經存在，請使用其他名稱。'));
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE categories SET name = :name, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':name'      => $name,
            ':updated_at'=> $now,
            ':id'        => $categoryId,
        ]);
        logAudit('category_edit', 'category_id=' . $categoryId . ' name=' . $name . ' old_name=' . (string) $category['name']);
        redirect('./category_management.php?success=1&msg=' . urlencode('分類名稱已更新。'));
    }

    // ---------- 停用分類 ----------
    if ($action === 'toggle_category') {
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);

        if ($categoryId === false || $categoryId === null) {
            redirect('./category_management.php?error=1&msg=' . urlencode('指定的分類不存在。'));
        }

        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $categoryId]);
        $category = $stmt->fetch();
        if ($category === false) {
            redirect('./category_management.php?error=1&msg=' . urlencode('指定的分類不存在。'));
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE categories SET is_active = 0, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':updated_at'=> $now,
            ':id'        => $categoryId,
        ]);
        logAudit('category_toggle', 'category_id=' . $categoryId . ' name=' . (string) $category['name'] . ' action=disable');
        redirect('./category_management.php?success=1&msg=' . urlencode('分類「' . $category['name'] . '」已停用。'));
    }

    // ---------- 合併分類 ----------
    if ($action === 'merge_category') {
        $sourceId  = filter_var($_POST['source_category_id'] ?? null, FILTER_VALIDATE_INT);
        $targetId  = filter_var($_POST['target_category_id'] ?? null, FILTER_VALIDATE_INT);

        if ($sourceId === false || $sourceId === null || $targetId === false || $targetId === null) {
            redirect('./category_management.php?error=1&msg=' . urlencode('請指定來源分類與目標分類。'));
        }
        if ($sourceId === $targetId) {
            redirect('./category_management.php?error=1&msg=' . urlencode('來源分類與目標分類不可相同。'));
        }

        // 驗證來源分類存在
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sourceId]);
        $source = $stmt->fetch();
        if ($source === false) {
            redirect('./category_management.php?error=1&msg=' . urlencode('來源分類不存在。'));
        }

        // 驗證目標分類存在
        $stmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $targetId]);
        $target = $stmt->fetch();
        if ($target === false) {
            redirect('./category_management.php?error=1&msg=' . urlencode('目標分類不存在。'));
        }

        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            // 將所有使用來源分類名稱的 entries 更新為目標分類名稱（全站共用分類，ledger_id 為 NULL，更新所有帳本）
            $stmt = $pdo->prepare('UPDATE entries SET category = :target_name WHERE category = :source_name');
            $stmt->execute([
                ':target_name' => (string) $target['name'],
                ':source_name' => (string) $source['name'],
            ]);
            // 將來源分類標記為停用
            $stmt = $pdo->prepare('UPDATE categories SET is_active = 0, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                ':updated_at'=> $now,
                ':id'        => $sourceId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            logError('category_merge failed for source_id=' . $sourceId . ' target_id=' . $targetId . ': ' . $e->getMessage());
            redirect('./category_management.php?error=1&msg=' . urlencode('合併失敗，請稍後再試。'));
        }
        // commit 後寫入 audit_log（logAudit 內部會開新 PDO 連線，不參與當前 transaction）
        logAudit('category_merge', 'source_category_id=' . $sourceId . ' source_name=' . (string) $source['name'] . ' target_category_id=' . $targetId . ' target_name=' . (string) $target['name']);
        redirect('./category_management.php?success=1&msg=' . urlencode('分類「' . $source['name'] . '」已合併至「' . $target['name'] . '」。'));
    }

    redirect('./category_management.php?error=1');
}

// ---------------- GET：顯示分類列表 ----------------
$stmt = $pdo->prepare('
    SELECT id, name, is_active, created_at, updated_at
    FROM categories
    ORDER BY is_active DESC, name ASC
');
$stmt->execute();
$categories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>後台 - 分類管理 - 全家人記帳系統</title>
    <link rel="stylesheet" href="../assets/style.css?v=20261115-8">
</head>
<body>
    <div class="app">
        <aside class="side">
            <a class="brand" href="../dashboard.php">
                <span class="brand-kicker">FAMILY VOUCHER</span>
                <b>全家人記帳系統</b>
                <span class="brand-mark" aria-hidden="true"><i class="in"></i><i class="out"></i></span>
            </a>
            <nav class="nav" aria-label="主要導覽">
                <p class="nav-h">帳務</p>
                <a class="nav-item" href="../dashboard.php">儀表板</a>
                <a class="nav-item" href="../ledgers.php">帳本列表</a>
                <a class="nav-item" href="../ledgers.php">新增記錄</a>
                <a class="nav-item" href="../search.php">全站搜尋</a>
                <a class="nav-item" href="../change_password.php">變更密碼</a>
                <p class="nav-h">後台</p>
                <a class="nav-item" href="./users.php">使用者管理</a>
                <a class="nav-item active" href="./category_management.php">分類管理</a>
                <a class="nav-item" href="./ledgers.php">帳本管理</a>
                <a class="nav-item" href="./audit_log.php">操作紀錄</a>
            </nav>
            <div class="side-foot">
                <div class="who"><b><?php echo h($user['username']); ?></b><span class="role-chip"><?php echo h($user['role']); ?></span></div>
                <a class="logout" href="../logout.php">登出</a>
            </div>
        </aside>

        <div class="main">
            <main class="sheet">
                <div class="page-head">
                    <div>
                        <h1>分類管理</h1>
                        <p class="sub">新增、編輯、停用、合併常用分類——只限管理員操作。</p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <p class="alert success" role="status"><?php echo h($msg !== '' ? $msg : '操作成功完成。'); ?></p>
                <?php endif; ?>
                <?php if ($error): ?>
                    <p class="alert error" role="alert"><?php echo h($msg !== '' ? $msg : '操作失敗，請檢查輸入後重試。'); ?></p>
                <?php endif; ?>

                <!-- 新增分類 -->
                <h3>新增分類</h3>
                <form method="POST" action="./category_management.php" autocomplete="off" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                    <input type="hidden" name="action" value="create_category">
                    <div class="field"><label for="create_category_name" class="label">分類名稱</label>
                    <input
                        type="text"
                        name="name"
                        id="create_category_name"
                        required
                        maxlength="50"
                        autocapitalize="none"
                        spellcheck="false"
                        class="input"
                        placeholder="例如 餐費、交通、醫療"
                    >
                    <p class="meta" style="margin-top: var(--space-2);">分類名稱全站唯一，最多 50 個字元。</p>
                    </div>
                    <button type="submit" class="btn primary btn-block">新增分類</button>
                </form>

                <!-- 分類列表 -->
                <h3>分類列表</h3>
                <?php if ($categories): ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>分類名稱</th>
                                    <th>狀態</th>
                                    <th>建立時間</th>
                                    <th class="ctr">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $c): ?>
                                    <?php
                                        $isActive = (int) ($c['is_active'] ?? 1) === 1;
                                        $statusLabel = $isActive ? '啟用' : '停用';
                                        $statusClass = $isActive ? 'ok' : 'inactive';
                                    ?>
                                    <tr>
                                        <td><?php echo h((string) $c['id']); ?></td>
                                        <td><?php echo h($c['name']); ?></td>
                                        <td><span class="category-chip <?php echo $statusClass; ?>"><?php echo h($statusLabel); ?></span></td>
                                        <td><?php echo h($c['created_at']); ?></td>
                                        <td class="ctr">
                                            <!-- 編輯分類 -->
                                            <form method="POST" action="./category_management.php" style="margin: 0 0 var(--space-2) 0; display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                <input type="hidden" name="action" value="edit_category">
                                                <input type="hidden" name="category_id" value="<?php echo h((string) $c['id']); ?>">
                                                <input type="text" name="name" required maxlength="50" value="<?php echo h($c['name']); ?>" class="input" style="width: 120px; margin-right: var(--space-2);">
                                                <button type="submit" class="btn sm secondary" onclick="return confirm('確定要將分類名稱改為「<?php echo h($c['name']); ?>」嗎？');">編輯</button>
                                            </form>
                                            <!-- 停用分類 -->
                                            <?php if ($isActive): ?>
                                            <form method="POST" action="./category_management.php" style="margin: 0; display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                                                <input type="hidden" name="action" value="toggle_category">
                                                <input type="hidden" name="category_id" value="<?php echo h((string) $c['id']); ?>">
                                                <button type="submit" class="btn sm danger" onclick="return confirm('確定要停用分類「<?php echo h($c['name']); ?>」嗎？停用後不會刪除既有記帳記錄。');">停用</button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>目前沒有任何分類。</p>
                <?php endif; ?>

                <!-- 合併分類 -->
                <h3>合併分類</h3>
                <p class="meta">將一個分類合併到另一個分類，所有使用來源分類名稱的記帳記錄將更新為目標分類名稱。此操作會影響全站所有帳本。</p>
                <form method="POST" action="./category_management.php" autocomplete="off" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrfToken()); ?>">
                    <input type="hidden" name="action" value="merge_category">
                    <div class="field">
                        <label for="merge_source_category_id" class="label">來源分類（將被停用）</label>
                        <select name="source_category_id" id="merge_source_category_id" class="input" required>
                            <option value="">請選擇來源分類</option>
                            <?php foreach ($categories as $c): ?>
                                <?php if ((int) ($c['is_active'] ?? 1) === 1): ?>
                                    <option value="<?php echo h((string) $c['id']); ?>"><?php echo h($c['name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="merge_target_category_id" class="label">目標分類（合併至）</label>
                        <select name="target_category_id" id="merge_target_category_id" class="input" required>
                            <option value="">請選擇目標分類</option>
                            <?php foreach ($categories as $c): ?>
                                <?php if ((int) ($c['is_active'] ?? 1) === 1): ?>
                                    <option value="<?php echo h((string) $c['id']); ?>"><?php echo h($c['name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn primary btn-block" onclick="return confirm('確定要合併分類嗎？合併後來源分類將被停用，所有相關記帳記錄將更新為目標分類名稱。');">合併分類</button>
                </form>

                <p class="meta" style="border-top: 1px solid var(--line); padding-top: var(--space-4); margin-top: var(--space-5);">
                    說明：分類為全站共用，所有帳本使用同一組分類名稱。停用分類不會刪除既有記帳記錄；合併分類會將來源分類下的所有記錄更新為目標分類名稱。
                </p>
            </main>
        </div>
    </div>
</body>
</html>
