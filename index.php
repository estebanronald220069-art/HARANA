<?php
// index.php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';
require_once 'includes/auth.php';

// ===== UPDATED REDIRECT FOR ALREADY LOGGED IN USERS =====
// If user is already logged in, redirect them to appropriate dashboard
if ($auth->isLoggedIn() || isset($_SESSION['user_id'])) {
    // If auth says not logged in but session has user_id, consider logged in
    $role = $_SESSION['role'] ?? null;
    if (!$role && isset($_SESSION['user_id'])) {
        // Try to get role from database
        $db = Database::getInstance();
        $user = $db->getSingle("SELECT role FROM users WHERE user_id = ?", [$_SESSION['user_id']], 'i');
        $role = $user['role'] ?? 'viewer';
    }
    
    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
        exit();
    } else {
        header('Location: user/dashboard.php');
        exit();
    }
}
// ===== END OF UPDATED CODE =====

$error = '';
$step = isset($_GET['step']) ? $_GET['step'] : 'login';
$user_id = $_SESSION['2fa_user_id'] ?? null;

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        if ($step === 'login') {
            $username = Security::sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            $result = $auth->login($username, $password);
            
            if ($result['success']) {
                // Redirect based on role from the login result
                header('Location: ' . $result['redirect']);
                exit();
            } elseif (isset($result['require_2fa']) && $result['require_2fa']) {
                header('Location: index.php?step=2fa');
                exit();
            } else {
                $error = $result['error'];
            }
        } elseif ($step === '2fa' && $user_id) {
            $totp_code = Security::sanitize($_POST['totp_code'] ?? '');
            
            $result = $auth->verify2FA($user_id, $totp_code);
            
            if ($result['success']) {
                error_log("=== LOGIN SUCCESS ===");
                error_log("User ID: " . ($_SESSION['user_id'] ?? 'not set'));
                error_log("Session token: " . ($_SESSION['session_token'] ?? 'not set'));
                error_log("Full session: " . print_r($_SESSION, true));
                
                // Check role and redirect accordingly
                $redirect_url = ($_SESSION['role'] === 'admin') ? 'admin/dashboard.php' : 'user/dashboard.php';
                header('Location: ' . $redirect_url);
                exit();
            } else {
                $error = $result['error'];
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Nagkaisang Haranista sa Gintong Luzon Phils, Inc.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Your existing styles - keeping them all */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            background: linear-gradient(135deg, #375a7f 0%, #4a7a9c 100%); 
            min-height: 100vh; 
            font-family: 'Poppins', 'Segoe UI', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated background elements */
        .bg-bubble {
            position: absolute;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            animation: float 20s infinite;
            z-index: 0;
        }
        
        .bg-bubble-1 {
            width: 300px;
            height: 300px;
            top: -150px;
            left: -150px;
            animation-delay: 0s;
        }
        
        .bg-bubble-2 {
            width: 500px;
            height: 500px;
            bottom: -250px;
            right: -250px;
            animation-delay: 5s;
        }
        
        .bg-bubble-3 {
            width: 200px;
            height: 200px;
            bottom: 50px;
            left: 100px;
            animation-delay: 10s;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(10deg); }
        }
        
        .container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 650px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .card { 
            border: none; 
            border-radius: 20px; 
            box-shadow: 0 20px 50px rgba(0,0,0,0.3); 
            width: 100%;
            max-width: 100%;
            margin: 0;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            transform: translateY(0);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px rgba(0,0,0,0.4);
        }
        
        .card-header { 
            background: linear-gradient(135deg, #375a7f 0%, #4a7a9c 100%); 
            border-radius: 20px 20px 0 0; 
            color: white; 
            padding: 2rem 2rem; 
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
            width: 100%;
        }
        
        .logo-container img {
            width: 120px;
            height: 120px;
            object-fit: contain;
            filter: drop-shadow(0 5px 15px rgba(0,0,0,0.2));
        }
        
        .logo-placeholder {
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo-placeholder i {
            font-size: 4rem;
            background: linear-gradient(135deg, #375a7f, #4a7a9c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 5px 15px rgba(0,0,0,0.2));
        }
        
        .org-name {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            line-height: 1.3;
            position: relative;
            z-index: 1;
        }
        
        .org-subname {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
            position: relative;
            z-index: 1;
        }
        
        .card-header i {
            font-size: 3rem;
            margin-bottom: 10px;
            animation: pulse 2s ease-in-out infinite;
            position: relative;
            z-index: 1;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.9; }
        }
        
        .card-body {
            padding: 2rem 2.5rem;
            background: transparent;
            border-radius: 0 0 20px 20px;
        }
        
        .btn-primary { 
            background: linear-gradient(135deg, #375a7f 0%, #4a7a9c 100%); 
            border: none; 
            padding: 14px; 
            width: 100%;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            font-size: 1.1rem;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }
        
        .btn-primary:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 10px 25px rgba(55, 90, 127, 0.4); 
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .form-control {
            padding: 14px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-size: 1rem;
            background: white;
            width: 100%;
        }
        
        .form-control:focus {
            border-color: #375a7f;
            box-shadow: 0 0 0 4px rgba(55, 90, 127, 0.1);
            outline: none;
        }
        
        .form-control:hover {
            border-color: #4a7a9c;
        }
        
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
            font-size: 0.95rem;
            transition: color 0.3s ease;
            display: block;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        /* Password field container for icon positioning */
        .password-field {
            position: relative;
            width: 100%;
        }
        
        .password-field input {
            padding-right: 45px;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #375a7f;
            z-index: 10;
            font-size: 1.2rem;
            background: transparent;
            padding: 0;
            line-height: 1;
        }
        
        .password-toggle:hover {
            color: #4a7a9c;
        }
        
        .alert {
            border-radius: 12px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .footer-links {
            text-align: center;
            margin-top: 25px;
        }
        
        .footer-links a {
            color: #375a7f;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .footer-links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, #375a7f 0%, #4a7a9c 100%);
            transition: width 0.3s ease;
        }
        
        .footer-links a:hover::after {
            width: 100%;
        }
        
        .footer-links a:hover {
            color: #4a7a9c;
        }
        
        .form-check-input:checked {
            background-color: #375a7f;
            border-color: #375a7f;
        }
        
        .form-check-input {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .form-check-label {
            cursor: pointer;
            user-select: none;
        }
        
        /* Loading spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .btn-primary.loading .spinner {
            display: inline-block;
        }
        
        .btn-primary.loading .btn-text {
            opacity: 0.8;
        }
        
        /* 2FA specific styles */
        .totp-input {
            letter-spacing: 8px;
            font-size: 1.8rem;
            font-weight: 600;
            text-align: center;
            padding: 15px;
        }
        
        .totp-input::-webkit-input-placeholder {
            letter-spacing: normal;
            font-size: 1rem;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: #375a7f;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .back-link i {
            margin-right: 5px;
            transition: transform 0.3s ease;
        }
        
        .back-link:hover i {
            transform: translateX(-5px);
        }
        
        /* Center the row and column */
        .row {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        
        .col-md-8 {
            width: 100%;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                max-width: 100%;
                padding: 0 15px;
            }
            
            .card-header {
                padding: 1.5rem;
            }
            
            .org-name {
                font-size: 1.2rem;
            }
            
            .org-subname {
                font-size: 0.8rem;
            }
            
            .logo-container img,
            .logo-placeholder {
                width: 100px;
                height: 100px;
            }
            
            .logo-placeholder i {
                font-size: 3.5rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .form-control {
                padding: 12px;
            }
            
            .btn-primary {
                padding: 12px;
            }
        }
        
        @media (max-width: 480px) {
            .card-header {
                padding: 1.2rem;
            }
            
            .org-name {
                font-size: 1rem;
            }
            
            .logo-container img,
            .logo-placeholder {
                width: 90px;
                height: 90px;
            }
            
            .logo-placeholder i {
                font-size: 3rem;
            }
            
            .card-body {
                padding: 1.2rem;
            }
            
            .form-group {
                margin-bottom: 1.2rem;
            }
            
            .totp-input {
                font-size: 1.5rem;
                letter-spacing: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Animated background bubbles -->
    <div class="bg-bubble bg-bubble-1"></div>
    <div class="bg-bubble bg-bubble-2"></div>
    <div class="bg-bubble bg-bubble-3"></div>
    
    <div class="container">
        <div class="row">
            <div class="col-md-8">
                <!-- Login Section -->
                <div id="login-section">
                    <div class="card">
                        <div class="card-header text-center">
                            <div class="logo-container">
                                <img src="assets/images/harana-logo.png" alt="Harana Logo" 
                                     onerror="this.style.display='none'; this.parentNode.innerHTML='<div class=\'logo-placeholder\'><i class=\'fas fa-hand-holding-heart\'></i></div>';">
                            </div>
                            <h3 class="org-name">Nagkaisang Haranista</h3>
                            <div class="org-subname">sa Gintong Luzon Phils, Inc.</div>
                        </div>
                        <div class="card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($step === 'login'): ?>
                            <form method="POST" action="" id="loginForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-user me-2"></i>Username</label>
                                    <input type="text" class="form-control" name="username" placeholder="Enter your username" required autofocus>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-lock me-2"></i>Password</label>
                                    <div class="password-field">
                                        <input type="password" class="form-control" name="password" id="password" placeholder="Enter your password" required>
                                        <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="remember">
                                            <label class="form-check-label" for="remember">Remember me</label>
                                        </div>
                                        <a href="forgot_password.php" class="text-decoration-none">
                                            <i class="fas fa-question-circle me-1"></i>Forgot Password?
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary" id="loginBtn">
                                        <span class="spinner"></span>
                                        <span class="btn-text"><i class="fas fa-sign-in-alt me-2"></i>Login</span>
                                    </button>
                                </div>
                            </form>
                            
                            <div class="footer-links">
                                <p class="mb-0">Don't have an account? <a href="register.php">Register here <i class="fas fa-arrow-right ms-1"></i></a></p>
                            </div>
                            
                            <?php elseif ($step === '2fa'): ?>
                            <form method="POST" action="?step=2fa" id="2faForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="text-center mb-4">
                                    <div class="mb-3">
                                        <i class="fas fa-shield-alt fa-4x" style="color: #375a7f;"></i>
                                    </div>
                                    <h5 class="mt-2">Two-Factor Authentication</h5>
                                    <p class="text-muted">Enter the 6-digit code from your authenticator app</p>
                                </div>
                                
                                <div class="form-group mb-4">
                                    <input type="text" class="form-control text-center totp-input" name="totp_code" 
                                           placeholder="000000" maxlength="6" pattern="[0-9]{6}" 
                                           inputmode="numeric" autocomplete="off" required autofocus>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary" id="verifyBtn">
                                        <span class="spinner"></span>
                                        <span class="btn-text"><i class="fas fa-check me-2"></i>Verify & Login</span>
                                    </button>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="index.php" class="back-link">
                                        <i class="fas fa-arrow-left"></i> Back to login
                                    </a>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        
        if (togglePassword && password) {
            togglePassword.addEventListener('click', function() {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                
                // Toggle icon
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        }
        
        // Form submission loading effect
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        
        if (loginForm && loginBtn) {
            loginForm.addEventListener('submit', function(e) {
                loginBtn.classList.add('loading');
                loginBtn.disabled = true;
            });
        }
        
        // 2FA form submission
        const twoFAForm = document.getElementById('2faForm');
        const verifyBtn = document.getElementById('verifyBtn');
        
        if (twoFAForm && verifyBtn) {
            twoFAForm.addEventListener('submit', function(e) {
                verifyBtn.classList.add('loading');
                verifyBtn.disabled = true;
            });
        }
        
        // Auto-submit 2FA code when 6 digits entered
        const totpInput = document.querySelector('input[name="totp_code"]');
        if (totpInput) {
            totpInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length === 6) {
                    document.getElementById('2faForm').submit();
                }
            });
            
            // Focus on input
            totpInput.focus();
        }
        
        // Smooth alert dismissal
        document.querySelectorAll('.alert .btn-close').forEach(btn => {
            btn.addEventListener('click', function() {
                this.closest('.alert').style.animation = 'slideOut 0.3s ease forwards';
            });
        });
        
        // Add animation keyframes for slideOut
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideOut {
                from {
                    opacity: 1;
                    transform: translateY(0);
                }
                to {
                    opacity: 0;
                    transform: translateY(-10px);
                }
            }
        `;
        document.head.appendChild(style);
        
        // Handle logo load error
        const logo = document.querySelector('.logo-container img');
        if (logo) {
            logo.addEventListener('error', function() {
                this.style.display = 'none';
                const container = this.parentNode;
                container.innerHTML = '<div class="logo-placeholder"><i class="fas fa-hand-holding-heart"></i></div>';
            });
        }
    </script>
</body>
</html>