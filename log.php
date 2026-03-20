<?php
require_once 'config.php';

$error = '';
$success = '';

// Eğer kullanıcı giriş yapmışsa dashboard'a yönlendir
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error = 'Güvenlik tokenı geçersiz!';
    } else {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);
        
        if (empty($username) || empty($password)) {
            $error = 'Kullanıcı adı ve şifre girin!';
        } else {
            // Kullanıcıyı bul (username veya email ile giriş yapabilir)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && verify_password($password, $user['password'])) {
                // Giriş başarılı
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_role'] = $user['user_role'];
                $_SESSION['api_key'] = $user['api_key'];
                
                // Son giriş zamanını güncelle
                $stmt = $pdo->prepare("UPDATE users SET last_login = NOW(), ip_address = ? WHERE id = ?");
                $stmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
                
                // Giriş logu oluştur
                $stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], true]);
                
                // Remember me cookie
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $expires = time() + (30 * 24 * 60 * 60); // 30 gün
                    setcookie('remember_token', $token, $expires, '/', '', true, true);
                    
                    $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $stmt->execute([$token, $user['id']]);
                }
                
                // Eğer 2FA aktifse 2FA sayfasına yönlendir
                if ($user['two_factor_enabled']) {
                    $_SESSION['requires_2fa'] = true;
                    header('Location: two_factor.php');
                    exit;
                }
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Kullanıcı adı veya şifre hatalı!';
                
                // Başarısız giriş logu
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $failed_user = $stmt->fetch();
                
                if ($failed_user) {
                    $stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$failed_user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], false]);
                }
            }
        }
    }
}

// Remember me cookie kontrolü
if (isset($_COOKIE['remember_token']) && !isset($_SESSION['user_id'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND status = 'active'");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['user_role'] = $user['user_role'];
        $_SESSION['api_key'] = $user['api_key'];
        
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --secondary: #10B981;
            --accent: #F59E0B;
            --bg-dark: #0F172A;
            --bg-darker: #020617;
            --bg-card: #1E293B;
            --bg-hover: #334155;
            --text-light: #F8FAFC;
            --text-gray: #94A3B8;
            --text-muted: #64748B;
            --gradient: linear-gradient(135deg, var(--primary), var(--secondary));
            --gradient-gold: linear-gradient(135deg, var(--accent), #FBBF24);
            --shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            --shadow-light: 0 10px 30px rgba(139, 92, 246, 0.2);
            --radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-light);
            background: var(--bg-darker);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.1) 0%, transparent 70%);
            z-index: -1;
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .auth-container {
            max-width: 1200px;
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
        }

        @media (max-width: 1024px) {
            .auth-container {
                grid-template-columns: 1fr;
                gap: 40px;
            }
        }

        .auth-left {
            text-align: center;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 30px;
            text-decoration: none;
            font-size: 1.8rem;
            font-weight: 900;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
        }

        .logo-text {
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
        }

        .logo-text span:first-child {
            color: var(--text-light);
        }

        .gradient-text {
            background: var(--gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .auth-left h1 {
            font-size: 2.5rem;
            margin-bottom: 15px;
            line-height: 1.2;
        }

        .auth-left p {
            color: var(--text-gray);
            margin-bottom: 30px;
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 40px;
        }

        .stat-item {
            background: var(--bg-card);
            padding: 20px;
            border-radius: var(--radius);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            background: var(--gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: block;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-gray);
            margin-top: 5px;
        }

        .auth-form {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 40px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }

        .auth-form::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient);
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h2 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .form-header p {
            color: var(--text-gray);
        }

        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #EF4444;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #10B981;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-light);
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-gray);
        }

        .form-control {
            width: 100%;
            padding: 14px 15px 14px 45px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            color: var(--text-light);
            font-size: 15px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-control.error {
            border-color: #EF4444;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-gray);
            cursor: pointer;
            font-size: 1rem;
        }

        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .checkbox-group label {
            font-size: 0.9rem;
            color: var(--text-gray);
            cursor: pointer;
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 32px;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            width: 100%;
            background: var(--gradient);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.3);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 25px 0;
            color: var(--text-gray);
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }

        .divider span {
            padding: 0 15px;
            font-size: 0.9rem;
        }

        .social-login {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
        }

        .social-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .social-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .social-btn.google:hover {
            background: #DB4437;
            border-color: #DB4437;
        }

        .social-btn.facebook:hover {
            background: #4267B2;
            border-color: #4267B2;
        }

        .form-footer {
            text-align: center;
            margin-top: 25px;
            color: var(--text-gray);
            font-size: 0.9rem;
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        .loading-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            display: none;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .captcha-container {
            margin-bottom: 20px;
        }

        .two-factor-toggle {
            text-align: center;
            margin-top: 15px;
        }

        .two-factor-toggle a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9rem;
        }

        .two-factor-toggle a:hover {
            text-decoration: underline;
        }

        .language-selector {
            position: absolute;
            top: 20px;
            right: 20px;
        }

        .language-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            padding: 8px 15px;
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .language-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .floating-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 15px 20px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateX(150%);
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .floating-notification.show {
            transform: translateX(0);
        }

        .session-timeout {
            position: fixed;
            top: 20px;
            left: 20px;
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 15px 20px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: none;
            z-index: 1000;
        }

        @media (max-width: 768px) {
            .auth-container {
                padding: 10px;
            }
            
            .auth-form {
                padding: 30px 20px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .social-login {
                grid-template-columns: 1fr;
            }
            
            .options-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .language-selector {
                position: static;
                margin-bottom: 20px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="language-selector">
        <a href="#" class="language-btn">
            <i class="fas fa-globe"></i>
            <span>Türkçe</span>
            <i class="fas fa-chevron-down"></i>
        </a>
    </div>

    <div class="auth-container">
        <!-- Sol Taraf: İstatistikler -->
        <div class="auth-left">
            <a href="index.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-bolt"></i>
                </div>
                <div class="logo-text">
                    <span class="gradient-text">Darq</span>
                </div>
            </a>
            
            <h1>Darq SMM Panel'e Giriş Yap</h1>
            <p>Hesabınıza giriş yapın ve premium hizmetlerden yararlanmaya devam edin.</p>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-number" id="onlineUsers">0</span>
                    <span class="stat-label">Çevrimiçi Kullanıcı</span>
                </div>
                
                <div class="stat-item">
                    <span class="stat-number" id="totalOrders">0</span>
                    <span class="stat-label">Toplam Sipariş</span>
                </div>
                
                <div class="stat-item">
                    <span class="stat-number" id="successRate">0%</span>
                    <span class="stat-label">Başarı Oranı</span>
                </div>
            </div>
            
            <div class="two-factor-toggle">
                <a href="#" id="toggle2FA">
                    <i class="fas fa-shield-alt"></i>
                    2-Faktörlü Kimlik Doğrulama Aktif Et
                </a>
            </div>
        </div>

        <!-- Sağ Taraf: Giriş Formu -->
        <div class="auth-form">
            <div class="form-header">
                <h2>Hesabınıza Giriş Yapın</h2>
                <p>Kullanıcı adı veya e-posta adresinizle giriş yapabilirsiniz</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form id="loginForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                
                <div class="form-group">
                    <label>Kullanıcı Adı veya E-posta</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" 
                               name="username" 
                               class="form-control" 
                               placeholder="kullanıcı_adı veya email@adresiniz.com"
                               required
                               autocomplete="username"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Şifre</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               name="password" 
                               id="password"
                               class="form-control" 
                               placeholder="Şifrenizi girin"
                               required
                               autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <div class="captcha-container">
                        <!-- reCAPTCHA buraya gelecek -->
                    </div>
                </div>

                <div class="options-row">
                    <div class="checkbox-group">
                        <input type="checkbox" name="remember" id="remember">
                        <label for="remember">Beni Hatırla</label>
                    </div>
                    
                    <a href="forgot_password.php" class="forgot-password">
                        Şifremi Unuttum?
                    </a>
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    <span id="btnText">Giriş Yap</span>
                    <div class="loading-spinner" id="loadingSpinner"></div>
                </button>

                <div class="divider">
                    <span>veya</span>
                </div>

                <div class="social-login">
                    <a href="#" class="social-btn google">
                        <i class="fab fa-google"></i>
                        Google ile Giriş
                    </a>
                    <a href="#" class="social-btn facebook">
                        <i class="fab fa-facebook-f"></i>
                        Facebook ile Giriş
                    </a>
                </div>

                <div class="form-footer">
                    Henüz hesabınız yok mu? <a href="register.php">Kayıt Olun</a>
                </div>
            </form>
        </div>
    </div>

    <div class="floating-notification" id="floatingNotification">
        <i class="fas fa-info-circle"></i>
        <span id="notificationText"></span>
    </div>

    <div class="session-timeout" id="sessionTimeout">
        <div>Oturum süreniz dolmak üzere</div>
        <div id="timeoutTimer">5:00</div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const loginForm = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const btnText = document.getElementById('btnText');
            const floatingNotification = document.getElementById('floatingNotification');
            const notificationText = document.getElementById('notificationText');
            const sessionTimeout = document.getElementById('sessionTimeout');
            const timeoutTimer = document.getElementById('timeoutTimer');
            const onlineUsers = document.getElementById('onlineUsers');
            const totalOrders = document.getElementById('totalOrders');
            const successRate = document.getElementById('successRate');
            const toggle2FA = document.getElementById('toggle2FA');

            // Şifre göster/gizle
            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });

            // Canlı istatistikler (simüle)
            function updateLiveStats() {
                // Rastgele veriler (gerçekte API'den gelecek)
                const randomUsers = Math.floor(Math.random() * 500) + 1000;
                const randomOrders = Math.floor(Math.random() * 10000) + 50000;
                const randomRate = (Math.random() * 5 + 95).toFixed(1);
                
                onlineUsers.textContent = randomUsers.toLocaleString();
                totalOrders.textContent = randomOrders.toLocaleString();
                successRate.textContent = randomRate + '%';
            }

            // İlk yükleme
            updateLiveStats();
            // Her 30 saniyede bir güncelle
            setInterval(updateLiveStats, 30000);

            // Form gönderimi
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Yükleme animasyonunu göster
                submitBtn.disabled = true;
                loadingSpinner.style.display = 'block';
                btnText.textContent = 'Giriş yapılıyor...';
                
                // Fake API çağrısı simülasyonu
                setTimeout(() => {
                    this.submit();
                }, 1500);
            });

            // Bildirim göster
            function showNotification(message, type = 'info') {
                notificationText.textContent = message;
                floatingNotification.className = 'floating-notification show';
                
                setTimeout(() => {
                    floatingNotification.classList.remove('show');
                }, 3000);
            }

            // 2FA toggle
            toggle2FA.addEventListener('click', function(e) {
                e.preventDefault();
                showNotification('2-Faktörlü Kimlik Doğrulama özelliği aktif edildi!');
            });

            // Oturum zaman aşımı kontrolü
            let timeoutSeconds = 300; // 5 dakika
            let timeoutInterval;
            
            function startSessionTimer() {
                clearInterval(timeoutInterval);
                timeoutSeconds = 300;
                sessionTimeout.style.display = 'none';
                
                timeoutInterval = setInterval(() => {
                    timeoutSeconds--;
                    
                    if (timeoutSeconds <= 60) {
                        sessionTimeout.style.display = 'block';
                        const minutes = Math.floor(timeoutSeconds / 60);
                        const seconds = timeoutSeconds % 60;
                        timeoutTimer.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                        
                        if (timeoutSeconds <= 0) {
                            clearInterval(timeoutInterval);
                            showNotification('Oturum süreniz doldu. Lütfen tekrar giriş yapın.', 'warning');
                            setTimeout(() => {
                                window.location.href = 'login.php';
                            }, 2000);
                        }
                    }
                }, 1000);
            }

            // Kullanıcı aktivitesinde zamanlayıcıyı sıfırla
            ['mousemove', 'keypress', 'click', 'scroll'].forEach(event => {
                window.addEventListener(event, startSessionTimer);
            });

            // İlk başlat
            startSessionTimer();

            // Enter tuşu ile form gönderimi
            loginForm.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.dispatchEvent(new Event('submit'));
                }
            });

            // Auto-focus ilk input
            document.querySelector('input[name="username"]').focus();

            // Demo hesapları için hızlı giriş (geliştirme sırasında)
            <?php if (DEBUG_MODE): ?>
            const demoAccounts = [
                { username: 'demo_user', password: 'Demo123!' },
                { username: 'test_user', password: 'Test123!' },
                { username: 'admin_demo', password: 'Admin123!' }
            ];

            // Demo butonları ekle
            const demoContainer = document.createElement('div');
            demoContainer.style.cssText = 'margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.05); border-radius: var(--radius);';
            demoContainer.innerHTML = `
                <h4 style="margin-bottom: 10px; color: var(--text-light); font-size: 0.9rem;">Demo Hesaplar (Geliştirme):</h4>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    ${demoAccounts.map(acc => `
                        <button class="demo-btn" data-username="${acc.username}" data-password="${acc.password}"
                                style="padding: 8px 12px; background: rgba(139, 92, 246, 0.2); border: 1px solid rgba(139, 92, 246, 0.3); border-radius: 8px; color: var(--text-light); cursor: pointer; font-size: 0.8rem;">
                            ${acc.username}
                        </button>
                    `).join('')}
                </div>
            `;

            document.querySelector('.auth-form').appendChild(demoContainer);

            // Demo butonlarına tıklama
            document.querySelectorAll('.demo-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const username = this.getAttribute('data-username');
                    const password = this.getAttribute('data-password');
                    
                    document.querySelector('input[name="username"]').value = username;
                    document.querySelector('input[name="password"]').value = password;
                    
                    showNotification(`${username} hesabı için bilgiler dolduruldu!`);
                });
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>nm