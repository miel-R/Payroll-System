<?php
// E:\PAYROLL\users.php
// Admin-only: create accounts and set their role (admin | finance).

$page_title = 'Manage Users';
$active_page = 'users';
require_once __DIR__ . '/inc/header.php';
requireRole('admin');

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'finance');

            if ($username === '' || $email === '' || $password === '') {
                $flash[] = ['danger', 'Username, email and password are all required.'];
            } elseif (dbUsernameExists($username)) {
                $flash[] = ['danger', 'That username is already taken.'];
            } elseif (dbEmailExists($email)) {
                $flash[] = ['danger', 'That email is already in use.'];
            } else {
                dbCreateUser($username, $email, $password, $role);
                $flash[] = ['success', 'User "' . htmlspecialchars($username) . '" created with role ' . htmlspecialchars($role) . '.'];
            }
        } elseif ($action === 'set_role') {
            $id = (int)($_POST['id'] ?? 0);
            $role = (string)($_POST['role'] ?? 'finance');
            if ($id > 0 && $id !== (int)$_SESSION['user_id']) {
                dbUpdateUserRole($id, $role);
                $flash[] = ['success', 'Role updated.'];
            } else {
                $flash[] = ['warning', 'You cannot change your own role.'];
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0 && $id !== (int)$_SESSION['user_id']) {
                dbDeleteUser($id);
                $flash[] = ['success', 'User deleted.'];
            } else {
                $flash[] = ['warning', 'You cannot delete your own account.'];
            }
        } elseif ($action === 'rename') {
            $id = (int)($_POST['id'] ?? 0);
            $newname = trim($_POST['username'] ?? '');
            if ($id > 0 && $newname === '') {
                $flash[] = ['danger', 'New username is required.'];
            } elseif ($id > 0 && dbUsernameExists($newname)) {
                $flash[] = ['danger', 'That username is already taken.'];
            } elseif ($id > 0) {
                dbRenameUser($id, $newname);
                $flash[] = ['success', 'Username updated to "' . htmlspecialchars($newname) . '".'];
            }
        } elseif ($action === 'set_password') {
            $id = (int)($_POST['id'] ?? 0);
            $newpass = (string)($_POST['password'] ?? '');
            if ($id > 0 && $newpass === '') {
                $flash[] = ['danger', 'New password is required.'];
            } elseif ($id > 0) {
                dbUpdateUserPassword($id, $newpass);
                $flash[] = ['success', 'Password updated.'];
            }
        }
    } catch (PDOException $e) {
        $flash[] = ['danger', 'Could not save: ' . htmlspecialchars($e->getMessage())];
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
            <form method="POST" action="users.php" autocomplete="off" data-ajax>
                <input type="hidden" name="action" value="create">
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
                                            <input type="hidden" name="action" value="set_role">
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
                                                data-ajax data-confirm="Delete this user?">
                                                <input type="hidden" name="action" value="delete">
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
                                        data-ajax data-confirm="Delete this user?">
                                        <input type="hidden" name="action" value="delete">
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
                            <input type="hidden" name="action" value="set_role">
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
                        <input type="hidden" name="action" value="rename">
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
                        <input type="hidden" name="action" value="set_password">
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

<?php require_once __DIR__ . '/inc/footer.php'; ?>
