<?php
require_once __DIR__ . '/config/session.php';
payroll_session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/config/DBgetPDO.php';

dbEnsureUserRoleColumn();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
        $error = 'Session expired. Please try again.';
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $user = dbGetLoginUser($username, $password);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'] ?? 'admin';
            session_regenerate_id(true);
            header('Location: dashboard.php');
            exit();
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Payroll System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"
        integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
    <link href="assets/css/index_style.css" rel="stylesheet">
</head>

<body>
    <div class="login-card">
        <div class="login-header">
            <div class="logo-icon">
                <i class="bi bi-shield-lock"></i>
            </div>
            <h3>Payroll System</h3>
            <p>Sign in to access your account</p>
            <?php if ($_SERVER['SERVER_NAME'] == 'localhost'): ?>
            <span class="badge-dev">Development Mode</span>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
        <div class="alert-custom alert-custom-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close-custom" data-bs-dismiss="alert" aria-label="Close">&times;</button>
        </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="form-floating-custom">
                <input type="text" class="form-control-custom" id="username" name="username"
                    placeholder="Username or Email" required>
                <label class="form-label-custom" for="username">
                    <i class="bi bi-person"></i> Username or Email
                </label>
            </div>

            <div class="form-floating-custom">
                <input type="password" class="form-control-custom" id="password" name="password" placeholder="Password"
                    required>
                <label class="form-label-custom" for="password">
                    <i class="bi bi-key"></i> Password
                </label>
                <button type="button" class="password-toggle-custom" id="togglePassword"
                    aria-label="Toggle password visibility">
                    <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
            </div>

            <div class="login-options-custom">
                <label class="checkbox-wrapper-custom">
                    <input type="checkbox" class="checkbox-input-custom" id="remember" name="remember">
                    <span class="checkbox-label-custom">Remember me</span>
                </label>
                <a href="contact.php" class="forgot-link-custom">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-login-custom">
                <i class="bi bi-box-arrow-in-right"></i> Sign In
            </button>
        </form>

        <div class="divider-custom">
            <span>or</span>
        </div>

        <p class="signup-text-custom">
            Don't have an account? <a href="contact.php">Sign Up</a>
        </p>

        <div class="security-badge-custom">
            <i class="bi bi-shield-check"></i>
            <span class="divider-dot-custom">•</span>
            <i class="bi bi-lock"></i>
            <span class="divider-dot-custom">•</span>
            <i class="bi bi-key"></i>
            <span>Secured with BCRYPT encryption</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <script>
    const togglePassword = document.getElementById('togglePassword');
    const password = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    togglePassword.addEventListener('click', function(e) {
        e.preventDefault();
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        eyeIcon.classList.toggle('bi-eye');
        eyeIcon.classList.toggle('bi-eye-slash');
    });

    document.querySelectorAll('.alert-custom').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });

    document.querySelector('form').addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('.btn-login-custom');
        submitBtn.disabled = true;
        submitBtn.innerHTML =
            '<span class="spinner-custom-sm" role="status" aria-hidden="true"></span> Signing in...';
    });
    </script>
</body>

</html>