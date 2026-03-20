<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();

require_once 'config.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? htmlspecialchars(trim($_POST['username'])) : '';
    $email = isset($_POST['email']) ? filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL) : '';
    
    if (empty($username) || empty($email)) {
        $error = 'Lütfen kullanıcı adı ve email adresinizi girin!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir email adresi girin!';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND email = ?");
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();
            
            if ($user) {
                $password = $_POST['password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($password) || empty($confirm_password)) {
                    $error = 'Lütfen yeni şifre alanlarını doldurun!';
                } elseif (strlen($password) < 6) {
                    $error = 'Şifre en az 6 karakter olmalıdır!';
                } elseif ($password !== $confirm_password) {
                    $error = 'Şifreler eşleşmiyor!';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $user['id']]);
                    
                    $message = 'Şifreniz başarıyla değiştirildi! Artık yeni şifrenizle giriş yapabilirsiniz.';
                }
            } else {
                $error = 'Kullanıcı adı ve email eşleşmiyor veya hesap bulunamadı!';
            }
        } catch (Exception $e) {
            $error = 'Bir hata oluştu. Lütfen daha sonra tekrar deneyin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum - Darq</title>
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
            --text-light: #F8FAFC;
            --text-gray: #94A3B8;
            --gradient: linear-gradient(135deg, var(--primary), var(--secondary));
            --shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
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
        }

        .container {
            max-width: 500px;
            width: 100%;
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

        .gradient-text {
            background: var(--gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .auth-form {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 40px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
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

        .btn-secondary {
            background: transparent;
            border: 2px solid var(--primary);
            margin-top: 15px;
        }

        .btn-secondary:hover {
            background: var(--primary);
        }

        .instructions {
            background: rgba(139, 92, 246, 0.1);
            border-radius: var(--radius);
            padding: 20px;
            margin-top: 25px;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .instructions h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .instructions ul {
            color: var(--text-gray);
            font-size: 0.9rem;
            padding-left: 20px;
        }

        .instructions li {
            margin-bottom: 5px;
        }

        @media (max-width: 768px) {
            .auth-form {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.html" class="logo">
            <div class="logo-icon">
                <i class="fas fa-bolt"></i>
            </div>
            <div class="logo-text">
                <span class="gradient-text">Darq</span>
            </div>
        </a>

        <div class="auth-form">
            <div class="form-header">
                <h2>Şifre Değiştir</h2>
                <p>Hesap bilgilerinizle şifrenizi değiştirin</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form id="forgotPasswordForm" method="POST" action="">
                <div class="form-group">
                    <label>Kullanıcı Adı</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" 
                               name="username" 
                               class="form-control" 
                               placeholder="kullanıcı adınız"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Adresiniz</label>
                    <div class="input-with-icon">
                        <i class="fas fa-envelope"></i>
                        <input type="email" 
                               name="email" 
                               class="form-control" 
                               placeholder="ornek@email.com"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Yeni Şifre</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               name="password" 
                               id="password"
                               class="form-control" 
                               placeholder="En az 6 karakter"
                               required
                               minlength="6">
                        <button type="button" class="password-toggle" data-target="password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Yeni Şifre (Tekrar)</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               name="confirm_password" 
                               id="confirm_password"
                               class="form-control" 
                               placeholder="Şifreyi tekrar girin"
                               required
                               minlength="6">
                        <button type="button" class="password-toggle" data-target="confirm_password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-save"></i> Şifreyi Değiştir
                </button>

                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Giriş Sayfasına Dön
                </a>
            </form>

            <div class="instructions">
                <h4><i class="fas fa-info-circle"></i> Nasıl Çalışır?</h4>
                <ul>
                    <li>Kullanıcı adı ve email adresinizi girin</li>
                    <li>Yeni şifrenizi belirleyin (en az 6 karakter)</li>
                    <li>Şifrenizi tekrar girin ve "Şifreyi Değiştir" butonuna tıklayın</li>
                    <li>Şifreniz anında değiştirilecektir</li>
                    <li>Artık yeni şifrenizle giriş yapabilirsiniz</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('forgotPasswordForm');
            const toggleButtons = form.querySelectorAll('.password-toggle');
            
            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const targetInput = document.getElementById(targetId);
                    const type = targetInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    targetInput.setAttribute('type', type);
                    const icon = this.querySelector('i');
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                });
            });
            
            form.addEventListener('submit', function(e) {
                const username = document.querySelector('input[name="username"]').value.trim();
                const email = document.querySelector('input[name="email"]').value.trim();
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                if (!username) {
                    e.preventDefault();
                    alert('Lütfen kullanıcı adınızı girin!');
                    return false;
                }
                
                if (!email) {
                    e.preventDefault();
                    alert('Lütfen email adresinizi girin!');
                    return false;
                }
                
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    e.preventDefault();
                    alert('Geçerli bir email adresi girin!');
                    return false;
                }
                
                if (password.length < 6) {
                    e.preventDefault();
                    alert('Şifre en az 6 karakter olmalıdır!');
                    return false;
                }
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Şifreler eşleşmiyor!');
                    return false;
                }
                
                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> İşleniyor...';
                submitBtn.disabled = true;
                
                return true;
            });
        });
    </script>
</body>
</html>