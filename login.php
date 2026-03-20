<?php
require_once 'config.php';

$error = '';
$success = '';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error = 'Güvenlik hatası! Sayfayı yenileyip tekrar deneyin.';
    } else {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);
        
        if (empty($username) || empty($password)) {
            $error = 'Lütfen kullanıcı adı ve şifrenizi girin.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $user_role = $user['user_role'] ?? 'user';
                $is_admin = ($user_role == 'admin' || $user_role == 'super_admin');
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_role'] = $user_role;
                $_SESSION['api_key'] = $user['api_key'];
                $_SESSION['is_admin'] = $is_admin;
                $_SESSION['balance'] = $user['balance'];
                
                if (!isset($user['user_role'])) {
                    $stmt = $pdo->prepare("UPDATE users SET user_role = 'user' WHERE id = ?");
                    $stmt->execute([$user['id']]);
                }
                
                $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $expires = time() + (30 * 24 * 60 * 60);
                    setcookie('remember_token', $token, $expires, '/');
                    $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $stmt->execute([$token, $user['id']]);
                }
                
                $success = 'Giriş başarılı! Yönlendiriliyorsunuz...';
                echo '<meta http-equiv="refresh" content="2;url=dashboard.php">';
                
            } else {
                $error = 'Kullanıcı adı veya şifre hatalı!';
            }
        }
    }
}

if (isset($_COOKIE['remember_token']) && !isset($_SESSION['user_id'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? AND status = 'active'");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['user_role'] = $user['user_role'] ?? 'user';
        $_SESSION['api_key'] = $user['api_key'];
        $_SESSION['balance'] = $user['balance'];
        
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
    <title>Giriş Yap - Darq SMM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --secondary: #10B981;
            --accent: #F59E0B;
            --bg-body: #020617;
            --bg-card: rgba(30, 41, 59, 0.6);
            --text-main: #F8FAFC;
            --text-muted: #94A3B8;
            --gradient-main: linear-gradient(135deg, #8B5CF6 0%, #4F46E5 100%);
            --glass-border: 1px solid rgba(255, 255, 255, 0.08);
            --glow: 0 0 30px rgba(139, 92, 246, 0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-body); 
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            position: relative; 
            overflow: hidden; 
        }

        .background-glow { position: absolute; width: 100%; height: 100%; z-index: -1; overflow: hidden; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(100px); opacity: 0.4; border-radius: 50%; animation: float 10s infinite alternate; }
        .blob-1 { top: -10%; left: -10%; width: 500px; height: 500px; background: var(--primary); }
        .blob-2 { bottom: -10%; right: -10%; width: 400px; height: 400px; background: #059669; animation-delay: -5s; }

        @keyframes float { 0% { transform: translate(0, 0); } 100% { transform: translate(30px, 30px); } }

        .auth-container {
            width: 100%;
            max-width: 420px;
            padding: 20px;
            position: relative;
            z-index: 10;
        }

        .auth-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border: var(--glass-border);
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        .logo-area { text-align: center; margin-bottom: 30px; }
        .logo { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 800; color: white; display: inline-flex; align-items: center; gap: 10px; text-decoration: none; letter-spacing: -1px; }
        .logo i { color: var(--primary); filter: drop-shadow(0 0 10px rgba(139, 92, 246, 0.5)); }
        .auth-desc { color: var(--text-muted); margin-top: 5px; font-size: 0.95rem; }

        .form-group { margin-bottom: 20px; position: relative; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.9rem; color: var(--text-muted); font-family: 'Outfit', sans-serif; }
        
        .input-wrapper { position: relative; }

        .input-wrapper .input-icon { 
            position: absolute; 
            left: 15px; 
            top: 50%; 
            transform: translateY(-50%); 
            color: var(--text-muted); 
            transition: 0.3s; 
            pointer-events: none;
        }
        .toggle-password { 
            position: absolute; 
            left: 15px;
            top: 50%; 
            transform: translateY(-50%); 
            color: var(--text-muted); 
            cursor: pointer; 
            transition: 0.3s; 
            z-index: 10;
        }
        .toggle-password:hover { color: white; }
        
        .form-control {
            width: 100%;
            padding: 14px 15px 14px 45px;
            background: rgba(255, 255, 255, 0.03);
            border: var(--glass-border);
            border-radius: 14px;
            color: white;
            font-size: 1rem;
            transition: 0.3s;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(255, 255, 255, 0.07); box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1); }
        .form-control:focus + i { color: var(--primary); }
        .form-control:focus ~ .toggle-password { color: var(--primary); }

        .form-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; font-size: 0.9rem; }
        
        .custom-checkbox { display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--text-muted); user-select: none; }
        .custom-checkbox input { display: none; }
        .checkmark { width: 18px; height: 18px; border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; position: relative; transition: 0.3s; background: rgba(255,255,255,0.05); }
        .custom-checkbox input:checked + .checkmark { background: var(--primary); border-color: var(--primary); }
        .custom-checkbox input:checked + .checkmark::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: white; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }
        
        .forgot-link { color: var(--primary); text-decoration: none; font-weight: 600; transition: 0.3s; }
        .forgot-link:hover { color: #A78BFA; text-decoration: underline; }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--gradient-main);
            color: white;
            border: none;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: var(--glow);
            font-family: 'Outfit', sans-serif;
            letter-spacing: 0.5px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(139, 92, 246, 0.5); }

        .divider { display: flex; align-items: center; margin: 25px 0; color: rgba(255,255,255,0.2); font-size: 0.85rem; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: rgba(255,255,255,0.1); }
        .divider span { padding: 0 15px; }

        .social-login { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .social-btn { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: white; text-decoration: none; font-size: 0.9rem; transition: 0.3s; font-weight: 500; }
        .social-btn:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.3); transform: translateY(-2px); }

        .auth-footer { text-align: center; margin-top: 25px; font-size: 0.95rem; color: var(--text-muted); }
        .auth-footer a { color: white; font-weight: 600; text-decoration: none; transition: 0.3s; }
        .auth-footer a:hover { color: var(--primary); }

        .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; animation: fadeIn 0.3s ease; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #FCA5A5; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6EE7B7; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        .back-home { position: absolute; top: 30px; left: 30px; color: var(--text-muted); text-decoration: none; display: flex; align-items: center; gap: 8px; font-weight: 600; transition: 0.3s; z-index: 20; padding: 10px 20px; background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 50px; backdrop-filter: blur(5px); }
        .back-home:hover { color: white; border-color: var(--primary); transform: translateX(-5px); }

        @media (max-width: 480px) {
            .back-home { top: 20px; left: 20px; font-size: 0.9rem; padding: 8px 15px; }
            .auth-card { padding: 30px 20px; }
            .social-login { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="background-glow">
        <div class="glow-blob blob-1"></div>
        <div class="glow-blob blob-2"></div>
    </div>

    <a href="index.php" class="back-home"><i class="fas fa-arrow-left"></i> Ana Sayfa</a>

    <div class="auth-container">
        <div class="auth-card">
            <div class="logo-area">
                <a href="index.php" class="logo">
                    <i class="fas fa-bolt"></i> Darq
                </a>
                <p class="auth-desc">Hesabınıza giriş yaparak devam edin</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                
                <div class="form-group">
                    <label>Kullanıcı Adı</label>
                    <div class="input-wrapper">
                        <input type="text" name="username" class="form-control" placeholder="Kullanıcı adınız" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Şifre</label>
                    <div class="input-wrapper">
                        <i class="fas fa-eye toggle-password" id="togglePassword"></i>
                        <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                    </div>
                </div>

                <div class="form-options">
                    <label class="custom-checkbox">
                        <input type="checkbox" name="remember">
                        <span class="checkmark"></span>
                        Beni Hatırla
                    </label>
                    <a href="forgot_password.php" class="forgot-link">Şifremi Unuttum?</a>
                </div>

                <button type="submit" class="btn-submit">
                    Giriş Yap <i class="fas fa-arrow-right" style="margin-left: 5px;"></i>
                </button>

                <div class="divider"><span>veya</span></div>

                <div class="social-login">
                    <a href="https://t.me/DarqSmm0" target="_blank" class="social-btn">
                        <i class="fab fa-telegram"></i> Telegram
                    </a>
                    <a href="https://wa.me/+212721490727" target="_blank" class="social-btn">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                </div>

                <div class="auth-footer">
                    Hesabınız yok mu? <a href="register.php">Hemen Kayıt Ol</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function (e) {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>