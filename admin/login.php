<?php
// admin/login.php
// Pure White & Simple, Gorgeous Admin Portal Login with Advanced Security

// 1. Secure Session Setup
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session cookie params
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path'     => '/',
        'domain'   => $cookieParams['domain'],
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// If already logged in, redirect straight to dashboard
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

// 2. Initialize Security & Rate Limiting
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['lockout_until'] = 0;
}

// Generate CSRF Token if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$remaining_lockout = 0;

// Check if currently locked out
if ($_SESSION['lockout_until'] > time()) {
    $remaining_lockout = $_SESSION['lockout_until'] - time();
    $error = "Too many failed attempts. Security lockout active for another {$remaining_lockout} seconds.";
}

// Check for redirect message
$msg = $_GET['msg'] ?? '';
if ($msg === 'logged_out') {
    $success = 'You have been securely signed out. Have a great day!';
} elseif ($msg === 'session_expired') {
    $error = 'Your session has expired. Please sign in again.';
} elseif ($msg === 'unauthorized') {
    $error = 'Please sign in to access the administrator panel.';
}

// 3. Process Login Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    
    // Verify Lockout State
    if ($_SESSION['lockout_until'] > time()) {
        $remaining_lockout = $_SESSION['lockout_until'] - time();
        $error = "Access temporarily locked. Please wait {$remaining_lockout} seconds before retrying.";
    } 
    // Verify CSRF Token
    elseif (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $error = "Security validation failed. Please refresh the page and try again.";
    } else {
        $username_or_email = trim($_POST['username_or_email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username_or_email) || empty($password)) {
            $error = "Please enter both username/email and password.";
        } else {
            // Prepared statement to prevent SQL Injection
            $stmt = $conn->prepare("SELECT id, name, username, email, password, role FROM admins WHERE (username = ? OR email = ?) LIMIT 1");
            
            if ($stmt) {
                $stmt->bind_param("ss", $username_or_email, $username_or_email);
                $stmt->execute();
                $res = $stmt->get_result();

                if ($res && $res->num_rows === 1) {
                    $admin = $res->fetch_assoc();

                    // Verify hashed password
                    if (password_verify($password, $admin['password'])) {
                        // Prevent Session Fixation attacks
                        session_regenerate_id(true);

                        // Reset rate-limiting counters
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['lockout_until'] = 0;

                        // Set authorized session variables
                        $_SESSION['admin_id']       = (int)$admin['id'];
                        $_SESSION['admin_name']     = $admin['name'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_email']    = $admin['email'];
                        $_SESSION['admin_role']     = $admin['role'] ?? 'admin';
                        $_SESSION['logged_in_at']   = time();
                        $_SESSION['last_activity']  = time();

                        // Refresh CSRF token for next session actions
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $stmt->close();
                        header('Location: dashboard.php');
                        exit;
                    }
                }

                $stmt->close();
            }

            // Authentication Failed
            $_SESSION['login_attempts']++;

            if ($_SESSION['login_attempts'] >= 5) {
                // Lock out for 60 seconds
                $_SESSION['lockout_until'] = time() + 60;
                $error = "Too many incorrect attempts! For security, login is locked for 60 seconds.";
            } else {
                $attempts_left = 5 - $_SESSION['login_attempts'];
                $error = "Invalid username or password. ({$attempts_left} attempt" . ($attempts_left > 1 ? 's' : '') . " remaining)";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Sign In &bull; Eco Divine Beach Resort</title>
  <link rel="icon" href="../favicon.png">

  <!-- Google Fonts: Poppins -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome 6 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <style>
    :root {
      --primary-color: #059669;
      --primary-hover: #047857;
      --primary-light: #ecfdf5;
      --primary-glow: rgba(5, 150, 105, 0.18);
      --accent-color: #10b981;
      --bg-canvas: #f8fafc;
      --card-bg: #ffffff;
      --text-dark: #0f172a;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --border-focus: #10b981;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background-color: var(--bg-canvas);
      background-image: 
        radial-gradient(at 0% 0%, rgba(16, 185, 129, 0.08) 0px, transparent 50%),
        radial-gradient(at 100% 100%, rgba(5, 150, 105, 0.06) 0px, transparent 50%);
      background-attachment: fixed;
      color: var(--text-dark);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
    }

    .login-container {
      width: 100%;
      max-width: 440px;
      animation: fadeInScale 0.45s ease-out;
    }

    @keyframes fadeInScale {
      from {
        opacity: 0;
        transform: translateY(16px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    /* Gorgeous White Card */
    .login-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 24px;
      padding: 42px 36px;
      box-shadow: 
        0 20px 40px -15px rgba(15, 23, 42, 0.07),
        0 0 1px 1px rgba(15, 23, 42, 0.02);
      position: relative;
      overflow: hidden;
    }

    .login-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #059669 0%, #10b981 50%, #34d399 100%);
    }

    /* Logo & Header */
    .brand-badge {
      width: 64px;
      height: 64px;
      border-radius: 18px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 8px 20px var(--primary-glow);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 18px;
      padding: 8px;
      transition: transform 0.3s ease;
    }

    .brand-badge:hover {
      transform: scale(1.05);
    }

    .brand-badge img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .brand-title {
      font-size: 1.45rem;
      font-weight: 700;
      color: var(--text-dark);
      letter-spacing: -0.3px;
      margin-bottom: 4px;
    }

    .brand-title span {
      color: var(--primary-color);
    }

    .brand-subtitle {
      font-size: 0.84rem;
      color: var(--text-muted);
      margin-bottom: 24px;
    }

    .security-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--primary-light);
      color: var(--primary-color);
      font-size: 0.72rem;
      font-weight: 600;
      padding: 4px 12px;
      border-radius: 20px;
      border: 1px solid rgba(16, 185, 129, 0.2);
      text-transform: uppercase;
      letter-spacing: 0.6px;
      margin-bottom: 22px;
    }

    /* Form Inputs */
    .form-label {
      font-size: 0.82rem;
      font-weight: 600;
      color: #334155;
      margin-bottom: 6px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .input-shell {
      position: relative;
      margin-bottom: 20px;
    }

    .input-shell .input-icon {
      position: absolute;
      top: 50%;
      left: 16px;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 0.95rem;
      transition: color 0.2s ease;
      pointer-events: none;
    }

    .input-shell .form-control {
      height: 50px;
      padding-left: 46px;
      padding-right: 46px;
      font-size: 0.92rem;
      color: #0f172a;
      background-color: #ffffff;
      border: 1.5px solid var(--border-color);
      border-radius: 12px;
      transition: all 0.25s ease;
    }

    .input-shell .form-control:focus {
      background-color: #ffffff;
      border-color: var(--border-focus);
      box-shadow: 0 0 0 4px var(--primary-glow);
      outline: none;
    }

    .input-shell .form-control:focus + .input-icon {
      color: var(--primary-color);
    }

    .toggle-password-btn {
      position: absolute;
      top: 50%;
      right: 14px;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #94a3b8;
      font-size: 0.95rem;
      cursor: pointer;
      padding: 6px;
      border-radius: 6px;
      transition: color 0.2s ease;
      line-height: 1;
    }

    .toggle-password-btn:hover {
      color: var(--primary-color);
    }

    /* Primary Submit Button */
    .btn-login {
      height: 50px;
      width: 100%;
      background: linear-gradient(135deg, #059669 0%, #10b981 100%);
      color: #ffffff;
      font-weight: 600;
      font-size: 0.95rem;
      border: none;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      box-shadow: 0 8px 20px rgba(5, 150, 105, 0.24);
      transition: all 0.25s ease;
      cursor: pointer;
    }

    .btn-login:hover:not(:disabled) {
      background: linear-gradient(135deg, #047857 0%, #059669 100%);
      box-shadow: 0 10px 24px rgba(5, 150, 105, 0.32);
      transform: translateY(-1px);
      color: #ffffff;
    }

    .btn-login:active:not(:disabled) {
      transform: translateY(1px);
      box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2);
    }

    .btn-login:disabled {
      opacity: 0.65;
      cursor: not-allowed;
      box-shadow: none;
    }

    /* Alert Styling */
    .custom-alert {
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 0.84rem;
      margin-bottom: 20px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      line-height: 1.4;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .custom-alert-danger {
      background-color: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }

    .custom-alert-success {
      background-color: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #166534;
    }

    .custom-alert i {
      font-size: 1rem;
      margin-top: 2px;
      flex-shrink: 0;
    }

    /* Card Footer Info */
    .card-meta-footer {
      margin-top: 28px;
      padding-top: 20px;
      border-top: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.74rem;
      color: #94a3b8;
    }

    .card-meta-footer .encrypt-badge {
      display: flex;
      align-items: center;
      gap: 5px;
      color: #64748b;
      font-weight: 500;
    }

    .card-meta-footer .encrypt-badge i {
      color: var(--primary-color);
    }

    .bottom-copyright {
      margin-top: 20px;
      text-align: center;
      font-size: 0.75rem;
      color: #94a3b8;
    }
  </style>
</head>
<body>

  <div class="login-container">
    
    <div class="login-card">
      
      <!-- Brand & Security Header -->
      <div class="text-center">
        <div class="brand-badge">
          <img src="../favicon.png" alt="Eco Divine Logo">
        </div>
        <h1 class="brand-title">Eco Divine <span>Resort</span></h1>
        <p class="brand-subtitle">Saint Martin Island &bull; Management Portal</p>
        
        <div class="security-badge">
          <i class="fa-solid fa-lock"></i> Secure Staff Verification
        </div>
      </div>

      <!-- Feedback Messages -->
      <?php if (!empty($error)): ?>
        <div class="custom-alert custom-alert-danger" role="alert">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="custom-alert custom-alert-success" role="alert">
          <i class="fa-solid fa-circle-check"></i>
          <div><?= htmlspecialchars($success) ?></div>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form action="login.php" method="POST" id="adminLoginForm" autocomplete="off">
        
        <!-- CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <!-- Username or Email Field -->
        <div class="input-shell">
          <label class="form-label" for="usernameOrEmail">
            Username or Email
          </label>
          <input 
            type="text" 
            class="form-control" 
            id="usernameOrEmail" 
            name="username_or_email" 
            placeholder="e.g. nurhossan2025 or name@example.com" 
            value="<?= isset($_POST['username_or_email']) ? htmlspecialchars($_POST['username_or_email']) : '' ?>"
            required 
            autofocus 
            autocomplete="username"
            <?= ($remaining_lockout > 0) ? 'disabled' : '' ?>
          >
          <i class="fa-solid fa-user input-icon"></i>
        </div>

        <!-- Password Field with Show/Hide Toggle -->
        <div class="input-shell mb-3">
          <div class="form-label">
            <label for="adminPassword" class="mb-0">Password</label>
            <span class="text-muted" style="font-size: 0.74rem; font-weight: normal;">Confidential</span>
          </div>
          <input 
            type="password" 
            class="form-control" 
            id="adminPassword" 
            name="password" 
            placeholder="Enter your security password" 
            required 
            autocomplete="current-password"
            <?= ($remaining_lockout > 0) ? 'disabled' : '' ?>
          >
          <i class="fa-solid fa-lock input-icon"></i>
          <button type="button" class="toggle-password-btn" id="togglePasswordBtn" aria-label="Toggle password visibility">
            <i class="fa-regular fa-eye" id="togglePasswordIcon"></i>
          </button>
        </div>

        <!-- Remember & Assistance Links -->
        <div class="d-flex align-items-center justify-content-between mb-4" style="font-size: 0.8rem;">
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="rememberMe" style="cursor: pointer;">
            <label class="form-check-label text-muted" for="rememberMe" style="cursor: pointer;">
              Remember this device
            </label>
          </div>
          <span class="text-muted" style="font-size: 0.76rem;" title="Contact Lead Admin for credentials">
            <i class="fa-solid fa-circle-info text-success me-1"></i> Staff Only
          </span>
        </div>

        <!-- Submit Button -->
        <button 
          type="submit" 
          class="btn-login" 
          id="loginSubmitBtn"
          <?= ($remaining_lockout > 0) ? 'disabled' : '' ?>
        >
          <span>Sign In to Dashboard</span>
          <i class="fa-solid fa-arrow-right"></i>
        </button>

      </form>

      <!-- Trust & Encryption Footer -->
      <div class="card-meta-footer">
        <div class="encrypt-badge">
          <i class="fa-solid fa-shield-check"></i>
          <span>256-Bit SSL Protected</span>
        </div>
        <span>v3.0 Secure Engine</span>
      </div>

    </div>

    <!-- Bottom Copyright -->
    <div class="bottom-copyright">
      &copy; <?= date('Y') ?> Eco Divine Beach Resort. All rights reserved.
    </div>

  </div>

  <!-- Client-Side Enhancements -->
  <script>
    // 1. Password Visibility Toggle
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const pwdInput = document.getElementById('adminPassword');
    const eyeIcon = document.getElementById('togglePasswordIcon');

    if (toggleBtn && pwdInput && eyeIcon) {
      toggleBtn.addEventListener('click', function() {
        const isPassword = pwdInput.getAttribute('type') === 'password';
        if (isPassword) {
          pwdInput.setAttribute('type', 'text');
          eyeIcon.classList.remove('fa-eye');
          eyeIcon.classList.add('fa-eye-slash');
        } else {
          pwdInput.setAttribute('type', 'password');
          eyeIcon.classList.remove('fa-eye-slash');
          eyeIcon.classList.add('fa-eye');
        }
      });
    }

    // 2. Prevent Double Submits and Show Loading State
    const loginForm = document.getElementById('adminLoginForm');
    const submitBtn = document.getElementById('loginSubmitBtn');

    if (loginForm && submitBtn) {
      loginForm.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Authenticating...';
      });
    }
  </script>

</body>
</html>
