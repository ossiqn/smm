<?php
require_once 'config.php';

$error = '';
$success = false;
$new_api_key = '';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error = 'Güvenlik hatası! Lütfen sayfayı yenileyin.';
    } else {
        $full_name = sanitize(trim($_POST['full_name'] ?? ''));
        $username = sanitize(trim($_POST['username'] ?? ''));
        $email = sanitize(trim($_POST['email'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $country = sanitize($_POST['country'] ?? 'TR');
        
        $agree_terms = isset($_POST['agree_terms']);
        $agree_privacy = isset($_POST['agree_privacy']);
        $agree_age = isset($_POST['agree_age']);

        if (empty($full_name) || empty($username) || empty($email) || empty($phone) || empty($password)) {
            $error = 'Lütfen tüm zorunlu alanları doldurun.';
        }
        elseif (!$agree_terms || !$agree_privacy || !$agree_age) {
            $error = 'Kayıt olabilmek için yasal şartları ve yaş sınırını kabul etmelisiniz.';
        }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Lütfen geçerli bir e-posta adresi girin.';
        }
        elseif (!preg_match('/^5[0-9]{9}$/', $phone)) {
            $error = 'Telefon numarası 5 ile başlamalı ve toplam 10 haneli olmalıdır (Örn: 5444444444).';
        }
        elseif (strlen($password) < 6) {
            $error = 'Şifre en az 6 karakter olmalıdır.';
        }
        elseif ($password !== $confirm_password) {
            $error = 'Şifreler eşleşmiyor.';
        }
        else {
            $stmt = $pdo->prepare("SELECT username, email, phone FROM users WHERE username = ? OR email = ? OR phone = ?");
            $stmt->execute([$username, $email, $phone]);
            $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_user) {
                if ($existing_user['email'] == $email) {
                    $error = 'Bu e-posta adresi zaten kullanımda.';
                } elseif ($existing_user['username'] == $username) {
                    $error = 'Bu kullanıcı adı başkası tarafından alınmış.';
                } elseif ($existing_user['phone'] == $phone) {
                    $error = 'Bu telefon numarası zaten sisteme kayıtlı.';
                } else {
                    $error = 'Bu bilgilerle daha önce kayıt olunmuş.';
                }
            } else {
                try {
                    $new_api_key = bin2hex(random_bytes(16));
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $user_own_ref_code = 'DARQ' . strtoupper(substr(md5($username . time()), 0, 6));
                    
                    $balance = 0;

                    $kvkk_consent = json_encode([
                        'terms' => true,
                        'privacy' => true,
                        'age' => true,
                        'ip' => $_SERVER['REMOTE_ADDR'],
                        'date' => date('Y-m-d H:i:s')
                    ]);

                    $sql = "INSERT INTO users (username, email, password, full_name, phone, country, api_key, referral_code, referred_by, balance, kvkk_consent, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'active')";
                    $stmt = $pdo->prepare($sql);
                    
                    if ($stmt->execute([$username, $email, $hashed_password, $full_name, $phone, $country, $new_api_key, $user_own_ref_code, $balance, $kvkk_consent])) {
                        $success = true;
                    } else {
                        $error = 'Kayıt sırasında bir veritabanı hatası oluştu.';
                    }
                } catch (Exception $e) {
                    $error = 'Sistem hatası: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt Ol - Darq SMM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            overflow-x: hidden;
            padding: 40px 20px;
        }

        .background-glow { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(100px); opacity: 0.4; border-radius: 50%; animation: float 10s infinite alternate; }
        .blob-1 { top: -10%; right: -10%; width: 500px; height: 500px; background: var(--primary); }
        .blob-2 { bottom: -10%; left: -10%; width: 400px; height: 400px; background: #059669; animation-delay: -5s; }

        @keyframes float { 0% { transform: translate(0, 0); } 100% { transform: translate(-30px, 30px); } }

        .auth-container { width: 100%; max-width: 500px; position: relative; z-index: 10; }

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

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 20px; position: relative; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.85rem; color: var(--text-muted); font-family: 'Outfit', sans-serif; }
        
        .input-wrapper { position: relative; }
        .input-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-muted); transition: 0.3s; pointer-events: none; }
        
        .form-control {
            width: 100%;
            padding: 12px 15px 12px 45px;
            background: rgba(255, 255, 255, 0.03);
            border: var(--glass-border);
            border-radius: 12px;
            color: white;
            font-size: 0.95rem;
            transition: 0.3s;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(255, 255, 255, 0.07); box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1); }
        .form-control:focus + i { color: var(--primary); }

        .legal-box { background: rgba(255,255,255,0.02); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 20px; }
        .custom-checkbox { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; color: var(--text-muted); font-size: 0.85rem; margin-bottom: 10px; user-select: none; }
        .custom-checkbox:last-child { margin-bottom: 0; }
        .custom-checkbox input { display: none; }
        .checkmark { width: 18px; height: 18px; border: 1px solid rgba(255,255,255,0.2); border-radius: 5px; position: relative; transition: 0.3s; background: rgba(255,255,255,0.05); flex-shrink: 0; margin-top: 2px; }
        .custom-checkbox input:checked + .checkmark { background: var(--primary); border-color: var(--primary); }
        .custom-checkbox input:checked + .checkmark::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: white; font-size: 10px; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }
        .custom-checkbox a { color: var(--primary); text-decoration: none; }
        .custom-checkbox a:hover { text-decoration: underline; }

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
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(139, 92, 246, 0.5); }
        .btn-submit:disabled { background: #4b5563; cursor: not-allowed; transform: none; box-shadow: none; }

        .auth-footer { text-align: center; margin-top: 20px; font-size: 0.9rem; color: var(--text-muted); }
        .auth-footer a { color: white; font-weight: 600; text-decoration: none; transition: 0.3s; }
        .auth-footer a:hover { color: var(--primary); }

        .alert { padding: 12px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; animation: fadeIn 0.3s ease; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #FCA5A5; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6EE7B7; }
        
        .back-home { position: absolute; top: 30px; left: 30px; color: var(--text-muted); text-decoration: none; display: flex; align-items: center; gap: 8px; font-weight: 600; transition: 0.3s; z-index: 20; padding: 10px 20px; background: rgba(30, 41, 59, 0.4); border: 1px solid rgba(255,255,255,0.1); border-radius: 50px; backdrop-filter: blur(5px); }
        .back-home:hover { color: white; border-color: var(--primary); transform: translateX(-5px); }

        .popup-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(2, 6, 23, 0.9); z-index: 1000; display: flex; justify-content: center; align-items: center; backdrop-filter: blur(10px); animation: fadeIn 0.3s ease; }
        .popup-card { background: var(--bg-card); border: 1px solid var(--primary); padding: 40px; border-radius: 30px; text-align: center; max-width: 450px; width: 90%; position: relative; box-shadow: 0 0 50px rgba(139, 92, 246, 0.3); animation: scaleIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        .popup-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #10B981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; color: white; margin: 0 auto 20px; box-shadow: 0 0 20px rgba(16, 185, 129, 0.5); }
        .popup-title { font-size: 1.8rem; font-weight: 800; color: white; margin-bottom: 10px; font-family: 'Outfit'; }
        .popup-text { color: var(--text-muted); margin-bottom: 20px; font-size: 1rem; }
        .api-key-box { background: rgba(0,0,0,0.3); padding: 15px; border-radius: 12px; border: 1px dashed rgba(255,255,255,0.2); font-family: monospace; color: var(--primary); font-size: 1.1rem; margin-bottom: 20px; word-break: break-all; }
        .countdown { font-size: 0.9rem; color: var(--text-muted); }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes scaleIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        @media (max-width: 480px) {
            .back-home { top: 20px; left: 20px; font-size: 0.9rem; padding: 8px 15px; }
            .auth-card { padding: 30px 20px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body>

    <div class="background-glow">
        <div class="glow-blob blob-1"></div>
        <div class="glow-blob blob-2"></div>
    </div>

    <?php if ($success): ?>
    <div class="popup-overlay">
        <div class="popup-card">
            <div class="popup-icon"><i class="fas fa-check"></i></div>
            <h2 class="popup-title">Kayıt Başarılı!</h2>
            <p class="popup-text">Aramıza hoş geldin! Hesabın başarıyla oluşturuldu.</p>
            <div class="api-key-box">
                API Key: <?php echo $new_api_key; ?>
            </div>
            <p class="countdown">Login sayfasına yönlendiriliyorsunuz... <span id="timer">3</span></p>
        </div>
    </div>
    <script>
        let timeLeft = 3;
        const timer = document.getElementById('timer');
        setInterval(() => {
            timeLeft--;
            timer.innerText = timeLeft;
            if(timeLeft <= 0) window.location.href = 'login.php';
        }, 1000);
    </script>
    <?php endif; ?>

    <a href="index.php" class="back-home"><i class="fas fa-arrow-left"></i> Ana Sayfa</a>

    <div class="auth-container">
        <div class="auth-card">
            <div class="logo-area">
                <a href="index.php" class="logo">
                    <i class="fas fa-bolt"></i> Darq
                </a>
                <p class="auth-desc">Hemen ücretsiz hesabını oluştur</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Ad Soyad</label>
                        <div class="input-wrapper">
                            <input type="text" name="full_name" class="form-control" placeholder="Adınız" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                            <i class="fas fa-id-card"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Kullanıcı Adı</label>
                        <div class="input-wrapper">
                            <input type="text" name="username" class="form-control" placeholder="Kullanıcı adı" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>E-posta Adresi</label>
                    <div class="input-wrapper">
                        <input type="email" name="email" class="form-control" placeholder="ornek@mail.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Telefon Numarası</label>
                    <div class="input-wrapper">
                        <input type="tel" name="phone" class="form-control" placeholder="5XXXXXXXXX" required 
                               maxlength="10" pattern="5[0-9]{9}" title="5 ile başlayan 10 haneli numara giriniz"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                        <i class="fas fa-phone"></i>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Şifre</label>
                        <div class="input-wrapper">
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required minlength="6">
                            <i class="fas fa-lock"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Şifre Tekrar</label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
                            <i class="fas fa-lock"></i>
                        </div>
                    </div>
                </div>

                <div class="legal-box">
                    <label class="custom-checkbox">
                        <input type="checkbox" name="agree_age" id="agree_age" required>
                        <span class="checkmark"></span>
                        18 yaşından büyük olduğumu beyan ederim.
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="agree_terms" id="agree_terms" required>
                        <span class="checkmark"></span>
                        <span><a href="tos.html" target="_blank">Kullanım Şartları</a>'nı okudum ve kabul ediyorum.</span>
                    </label>
                    <label class="custom-checkbox">
                        <input type="checkbox" name="agree_privacy" id="agree_privacy" required>
                        <span class="checkmark"></span>
                        <span><a href="privacy.html" target="_blank">Gizlilik Politikası</a>'nı okudum ve kabul ediyorum.</span>
                    </label>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn" disabled>
                    Kayıt Ol <i class="fas fa-user-plus" style="margin-left: 5px;"></i>
                </button>

                <div class="auth-footer">
                    Zaten hesabın var mı? <a href="login.php">Giriş Yap</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        const checkboxes = document.querySelectorAll('input[type="checkbox"]');
        const submitBtn = document.getElementById('submitBtn');

        function checkConsents() {
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            if (allChecked) {
                submitBtn.removeAttribute('disabled');
                submitBtn.style.opacity = "1";
            } else {
                submitBtn.setAttribute('disabled', 'true');
                submitBtn.style.opacity = "0.6";
            }
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', checkConsents);
        });
    </script>
</body>
</html>