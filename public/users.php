<?php
// E:\PAYROLL\users.php
// Admin-only: create accounts and set their role (admin | finance).

$page_title = 'Manage Users';
$active_page = 'users';
require_once __DIR__ . '/../src/inc/header.php';
require_once __DIR__ . '/../src/config/actions.php';
requireRole('admin');

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = run_action((string)($_POST['action'] ?? ''), [
        'post'     => $_POST,
        'is_admin' => true,
        'user_id'  => (int)($_SESSION['user_id'] ?? 0),
        'site_id'  => 0,
    ]);
    if ($res['msg'] !== '') {
        $flash[] = [$res['type'], htmlspecialchars($res['msg'])];
    }
}

$users = dbGetAllUsers();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0"><i class="bi bi-people-fill"></i> Manage Users</h3>
        <small class="text-muted">Finance can use the DTR and view/print payrolls; admin can do everything.</small>
    </div>
</div>

<?php foreach ($flash as $f): ?>
    <div class="alert alert-<?php echo $f[0]; ?> flash-toast alert-dismissible fade show" role="alert">
        <?php echo $f[1]; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="row">
    <div class="col-lg-4">
        <div class="content-card">
            <h4><i class="bi bi-person-plus"></i> New User</h4>
            <form method="POST" action="users.php" autocomplete="off" data-api>
                <input type="hidden" name="action" value="user.create">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required
                        placeholder="e.g. finance" autocomplete="new-username" readonly
                        onfocus="this.removeAttribute('readonly');" onmousedown="this.removeAttribute('readonly');">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required
                        placeholder="name@example.com" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required
                        autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="role">Role</label>
                    <select class="form-select" id="role" name="role">
                        <option value="finance" selected>Finance (DTR + view/print only)</option>
                        <option value="admin">Admin (full access)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create User</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="content-card">
            <h4><i class="bi bi-list-ul"></i> Users (<?php echo count($users); ?>)</h4>
            <?php if (!$users): ?>
                <p class="text-muted mb-0">No users yet.</p>
            <?php else: ?>
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th class="text-center">Role</th>
                                <th class="text-center">Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u):
                                $is_self = (int)$u['id'] === (int)$_SESSION['user_id'];
                            ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <?php echo htmlspecialchars($u['username']); ?>
                                        <?php if ($is_self): ?>
                                            <span class="badge bg-secondary">you</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td class="text-center">
                                        <form method="POST" action="users.php" class="d-inline-flex gap-1 align-items-center">
                                            <input type="hidden" name="action" value="user.set_role">
                                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                            <?php echo csrf_field(); ?>
                                            <select class="form-select form-select-sm" name="role" <?php echo $is_self ? 'disabled' : ''; ?>>
                                                <option value="admin" <?php echo ($u['role'] ?? 'admin') === 'admin' ? 'selected' : ''; ?>>admin</option>
                                                <option value="finance" <?php echo ($u['role'] ?? 'admin') === 'finance' ? 'selected' : ''; ?>>finance</option>
                                            </select>
                                            <?php if (!$is_self): ?>
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Save role">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                    <td class="text-center small"><?php echo htmlspecialchars(date('M d, Y', strtotime($u['created_at'] ?? 'now'))); ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Rename / Reset password"
                                            data-bs-toggle="modal" data-bs-target="#userModal<?php echo (int)$u['id']; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if (!$is_self): ?>
                                            <form method="POST" action="users.php" class="d-inline"
                                                data-api data-confirm="Delete this user?">
                                                <input type="hidden" name="action" value="user.delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-lg-none">
                    <?php foreach ($users as $u):
                        $is_self = (int)$u['id'] === (int)$_SESSION['user_id'];
                    ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="fw-semibold">
                                    <?php echo htmlspecialchars($u['username']); ?>
                                    <?php if ($is_self): ?>
                                        <span class="badge bg-secondary">you</span>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-<?php echo ($u['role'] ?? 'admin') === 'admin' ? 'primary' : 'info'; ?>">
                                    <?php echo htmlspecialchars($u['role'] ?? 'admin'); ?>
                                </span>
                            </div>
                            <div class="text-muted small"><?php echo htmlspecialchars($u['email']); ?></div>
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-toggle="modal"
                                    data-bs-target="#userModal<?php echo (int)$u['id']; ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <?php if (!$is_self): ?>
                                    <form method="POST" action="users.php" class="flex-fill"
                                        data-api data-confirm="Delete this user?">
                                        <input type="hidden" name="action" value="user.delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-outline-danger w-100" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // Per-user Edit modals (rename / reset password / role) ?>
<?php foreach ($users as $u):
    $is_self = (int)$u['id'] === (int)$_SESSION['user_id'];
?>
    <div class="modal fade" id="userModal<?php echo (int)$u['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-gear"></i> Edit User - <?php echo htmlspecialchars($u['username']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (!$is_self): ?>
                        <h6 class="text-muted">Role</h6>
                        <form method="POST" action="users.php" class="d-flex gap-2 mb-3">
                            <input type="hidden" name="action" value="user.set_role">
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <?php echo csrf_field(); ?>
                            <select class="form-select" name="role">
                                <option value="admin" <?php echo ($u['role'] ?? 'admin') === 'admin' ? 'selected' : ''; ?>>Admin (full access)</option>
                                <option value="finance" <?php echo ($u['role'] ?? 'admin') === 'finance' ? 'selected' : ''; ?>>Finance (DTR + view/print)</option>
                            </select>
                            <button type="submit" class="btn btn-outline-primary text-nowrap">Set Role</button>
                        </form>
                        <hr>
                    <?php endif; ?>
                    <h6 class="text-muted">Rename</h6>
                    <form method="POST" action="users.php" autocomplete="off">
                        <input type="hidden" name="action" value="user.rename">
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                        <?php echo csrf_field(); ?>
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" name="username" autocomplete="new-username"
                                value="<?php echo htmlspecialchars($u['username']); ?>" required>
                            <button type="submit" class="btn btn-primary">Rename</button>
                        </div>
                    </form>
                    <h6 class="text-muted">Reset Password</h6>
                    <form method="POST" action="users.php" autocomplete="off">
                        <input type="hidden" name="action" value="user.set_password">
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                        <?php echo csrf_field(); ?>
                        <div class="input-group">
                            <input type="text" class="form-control" name="password" autocomplete="new-password"
                                placeholder="New password" required>
                            <button type="submit" class="btn btn-warning">Set Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../src/inc/footer.php'; ?>
