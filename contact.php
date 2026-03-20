<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Geçersiz işlem. Lütfen sayfayı yenileyip tekrar deneyin.';
    } else {
        $name = htmlspecialchars(trim($_POST['name'] ?? ''));
        $email = htmlspecialchars(trim($_POST['email'] ?? ''));
        $subject = htmlspecialchars(trim($_POST['subject'] ?? ''));
        $message = htmlspecialchars(trim($_POST['message'] ?? ''));
        
        if (empty($name) || empty($email) || empty($subject) || empty($message)) {
            $error = 'Lütfen tüm alanları doldurun.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Lütfen geçerli bir e-posta adresi girin.';
        } else {
            $success = 'Mesajınız başarıyla gönderildi! En kısa sürede size dönüş yapacağız.';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $csrf_token = $_SESSION['csrf_token'];
            $_POST = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İletişim - Darq SMM</title>
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
            --gradient-text: linear-gradient(135deg, #C4B5FD 0%, #6EE7B7 100%);
            --glass-border: 1px solid rgba(255, 255, 255, 0.08);
            --glow: 0 0 30px rgba(139, 92, 246, 0.3);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; scroll-behavior: smooth; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); color: var(--text-main); overflow-x: hidden; line-height: 1.6; }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); }
        ::-webkit-scrollbar-thumb { background: var(--primary-dark); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }

        .background-glow { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; animation: float 15s infinite alternate ease-in-out; }
        .blob-1 { top: -10%; left: -10%; width: 600px; height: 600px; background: var(--primary); }
        .blob-2 { bottom: 10%; right: -10%; width: 500px; height: 500px; background: #059669; animation-delay: -5s; }

        @keyframes float { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(40px, 40px) scale(1.05); } }

        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }

        .navbar { padding: 20px 0; position: fixed; width: 100%; top: 0; z-index: 1000; background: rgba(2, 6, 23, 0.7); backdrop-filter: blur(15px); border-bottom: var(--glass-border); transition: 0.3s; }
        .navbar.scrolled { padding: 15px 0; background: rgba(2, 6, 23, 0.95); }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        
        .logo { display: flex; align-items: center; gap: 12px; font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 800; text-decoration: none; color: white; letter-spacing: -0.5px; }
        .logo i { color: var(--primary); font-size: 1.8rem; filter: drop-shadow(0 0 10px rgba(139, 92, 246, 0.5)); }
        
        .nav-links { display: flex; gap: 25px; align-items: center; }
        .nav-links a { text-decoration: none; color: var(--text-muted); font-weight: 500; transition: 0.3s; font-size: 0.9rem; position: relative; white-space: nowrap; }
        .nav-links a:hover, .nav-links a.active { color: white; }
        .nav-links a::after { content: ''; position: absolute; bottom: -5px; left: 0; width: 0; height: 2px; background: var(--gradient-main); transition: 0.3s; border-radius: 2px; }
        .nav-links a:hover::after { width: 100%; }

        .nav-actions { display: flex; gap: 15px; }
        
        .btn { padding: 10px 24px; border-radius: 12px; font-weight: 600; text-decoration: none; transition: all 0.3s ease; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; font-size: 0.95rem; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: 0 4px 20px rgba(139, 92, 246, 0.4); border: 1px solid rgba(255,255,255,0.1); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(139, 92, 246, 0.6); }
        .btn-outline { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.15); color: white; backdrop-filter: blur(5px); }
        .btn-outline:hover { background: rgba(255,255,255,0.1); border-color: white; transform: translateY(-3px); }

        .menu-toggle { display: none; font-size: 1.5rem; color: white; cursor: pointer; }

        .contact-hero { padding: 180px 0 80px; text-align: center; position: relative; }
        .hero-title { font-family: 'Outfit', sans-serif; font-size: 3.5rem; line-height: 1.1; font-weight: 800; margin-bottom: 20px; }
        .text-gradient { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .hero-desc { color: var(--text-muted); font-size: 1.1rem; max-width: 700px; margin: 0 auto 40px; }

        .contact-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 50px; margin-bottom: 100px; }
        
        .contact-info { display: flex; flex-direction: column; gap: 20px; }
        .contact-card { background: var(--bg-card); border: var(--glass-border); padding: 30px; border-radius: 20px; transition: 0.3s; display: flex; align-items: flex-start; gap: 20px; backdrop-filter: blur(10px); }
        .contact-card:hover { transform: translateY(-5px); border-color: var(--primary); background: rgba(139, 92, 246, 0.1); }
        
        .contact-icon { width: 50px; height: 50px; background: var(--gradient-main); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; flex-shrink: 0; box-shadow: var(--glow); }
        
        .contact-details h3 { font-size: 1.2rem; margin-bottom: 5px; color: white; font-family: 'Outfit', sans-serif; }
        .contact-details p { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 10px; }
        .contact-link { color: var(--primary); text-decoration: none; font-weight: 600; font-size: 0.9rem; }
        .contact-link:hover { text-decoration: underline; }

        .form-container { background: var(--bg-card); border: var(--glass-border); padding: 40px; border-radius: 24px; backdrop-filter: blur(15px); }
        .form-header { margin-bottom: 30px; }
        .form-header h2 { font-size: 1.8rem; margin-bottom: 10px; font-family: 'Outfit', sans-serif; }
        .form-header p { color: var(--text-muted); font-size: 0.95rem; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-weight: 500; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: var(--glass-border); border-radius: 12px; color: white; font-size: 1rem; transition: 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(2, 6, 23, 0.8); }
        textarea.form-control { min-height: 150px; resize: vertical; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        .alert { padding: 15px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6EE7B7; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #FCA5A5; }

        .footer { padding: 60px 0 30px; background: rgba(2, 6, 23, 0.95); border-top: var(--glass-border); }
        .footer-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr; gap: 40px; margin-bottom: 50px; }
        .footer-col h4 { color: white; margin-bottom: 25px; font-size: 1.2rem; font-weight: 700; font-family: 'Outfit', sans-serif; }
        .footer-links a { display: block; color: var(--text-muted); text-decoration: none; margin-bottom: 12px; transition: 0.3s; font-size: 0.95rem; }
        .footer-links a:hover { color: var(--primary); padding-left: 5px; }
        
        .social-icons { display: flex; gap: 10px; margin-top: 25px; }
        .social-icons a { display: inline-flex; width: 45px; height: 45px; background: rgba(255,255,255,0.05); border-radius: 12px; align-items: center; justify-content: center; color: white; text-decoration: none; transition: 0.3s; border: 1px solid rgba(255,255,255,0.05); }
        .social-icons a:hover { background: var(--primary); transform: translateY(-5px); border-color: transparent; box-shadow: var(--glow); }

        .payment-methods { display: flex; gap: 15px; margin-top: 15px; font-size: 1.8rem; color: var(--text-muted); }
        .footer-bottom { text-align: center; padding-top: 30px; border-top: 1px solid rgba(255, 255, 255, 0.1); color: var(--text-gray); font-size: 0.9rem; }

        @media (max-width: 992px) {
            .nav-links { display: none; position: absolute; top: 70px; left: 0; width: 100%; background: rgba(2, 6, 23, 0.95); flex-direction: column; padding: 30px; border-bottom: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(20px); height: calc(100vh - 70px); overflow-y: auto; }
            .nav-links.active { display: flex; }
            .nav-actions { display: none; }
            .menu-toggle { display: block; }
            .contact-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 576px) {
            .hero-title { font-size: 2.5rem; }
            .footer-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="background-glow">
        <div class="glow-blob blob-1"></div>
        <div class="glow-blob blob-2"></div>
    </div>

    <nav class="navbar" id="navbar">
        <div class="container nav-inner">
            <a href="index.php" class="logo">
                <i class="fas fa-bolt"></i> Darq
            </a>
            
            <div class="nav-links" id="navLinks">
                <a href="index.php">Ana Sayfa</a>
                <a href="services.php">Hizmetler</a>
                <a href="about.html">Hakkımızda</a>
                <a href="contact.php" class="active">İletişim</a>
                <a href="faq.html">SSS</a>
                <a href="tos.html">Kullanım Şartları</a>
                <a href="privacy.html">Gizlilik Politikası</a>
                <a href="refund.html">İade Politikası</a>
                
                <div id="mobile-auth" style="display: none; flex-direction: column; gap: 10px; width: 100%; margin-top: 15px;">
                    <a href="login.php" class="btn btn-outline" style="justify-content: center;">Giriş Yap</a>
                    <a href="register.php" class="btn btn-primary" style="justify-content: center;">Kayıt Ol</a>
                </div>
            </div>

            <div class="nav-actions">
                <a href="login.php" class="btn btn-outline">Giriş Yap</a>
                <a href="register.php" class="btn btn-primary">Kayıt Ol</a>
            </div>
            
            <div class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </nav>

    <section class="contact-hero">
        <div class="container">
            <h1 class="hero-title">İletişime Geçin <br> <span class="text-gradient">Yardıma Hazırız</span></h1>
            <p class="hero-desc">
                Sorularınız, önerileriniz veya destek talepleriniz için bize ulaşın. Ekibimiz en kısa sürede size dönüş yapacaktır.
            </p>
        </div>
    </section>

    <div class="container">
        <div class="contact-grid">
            <div class="contact-info">
                <div class="contact-card">
                    <div class="contact-icon"><i class="fas fa-headset"></i></div>
                    <div class="contact-details">
                        <h3>Canlı Destek</h3>
                        <p>Panel üzerinden veya Telegram'dan 7/24 anlık destek alabilirsiniz.</p>
                        <a href="https://t.me/darq_support" target="_blank" class="contact-link">Sohbeti Başlat &rarr;</a>
                    </div>
                </div>

                <div class="contact-card">
                    <div class="contact-icon"><i class="fab fa-whatsapp"></i></div>
                    <div class="contact-details">
                        <h3>WhatsApp Hattı</h3>
                        <p>Acil durumlar ve hızlı iletişim için WhatsApp hattımızı kullanabilirsiniz.</p>
                        <a href="https://wa.me/+212721490727" target="_blank" class="contact-link">+212 721 490 727 &rarr;</a>
                    </div>
                </div>

                <div class="contact-card">
                    <div class="contact-icon"><i class="fas fa-envelope"></i></div>
                    <div class="contact-details">
                        <h3>E-posta Desteği</h3>
                        <p>Detaylı sorularınız ve işbirlikleri için bize mail atabilirsiniz.</p>
                        <a href="mailto:p4ssword35@gmail.com" class="contact-link">p4ssword35@gmail.com &rarr;</a>
                    </div>
                </div>

                <div class="contact-card">
                    <div class="contact-icon"><i class="fab fa-telegram-plane"></i></div>
                    <div class="contact-details">
                        <h3>Telegram Kanalı</h3>
                        <p>Güncel duyurular, kampanyalar ve hizmet güncellemeleri için katılın.</p>
                        <a href="https://t.me/DarqSmm0" target="_blank" class="contact-link">Kanala Katıl &rarr;</a>
                    </div>
                </div>
            </div>

            <div class="form-container">
                <div class="form-header">
                    <h2>Bize Yazın</h2>
                    <p>Aşağıdaki formu doldurarak bize mesaj gönderebilirsiniz. En geç 24 saat içinde yanıtlayacağız.</p>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Ad Soyad</label>
                            <input type="text" name="name" class="form-control" placeholder="Adınız" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>E-posta Adresi</label>
                            <input type="email" name="email" class="form-control" placeholder="Mail adresiniz" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Konu</label>
                        <input type="text" name="subject" class="form-control" placeholder="Mesajınızın konusu" value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Mesajınız</label>
                        <textarea name="message" class="form-control" placeholder="Size nasıl yardımcı olabiliriz?" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Mesajı Gönder <i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <a href="index.php" class="logo" style="margin-bottom: 20px; display: block;">
                        <i class="fas fa-bolt"></i> Darq
                    </a>
                    <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.6;">
                        Sosyal medya hesaplarınızı büyütmenin en profesyonel, hızlı ve güvenilir adresi. <br>2026 © Tüm hakları saklıdır.
                    </p>
                    <div class="social-icons">
                        <a href="https://t.me/DarqSmm0" target="_blank"><i class="fab fa-telegram"></i></a>
                        <a href="https://www.instagram.com/darqsmm" target="_blank"><i class="fab fa-instagram"></i></a>
                        <a href="https://wa.me/+212721490727" target="_blank"><i class="fab fa-whatsapp"></i></a>
                    </div>
                </div>
                
                <div class="footer-col">
                    <h4>Hızlı Erişim</h4>
                    <div class="footer-links">
                        <a href="index.php">Ana Sayfa</a>
                        <a href="services.php">Hizmetler</a>
                        <a href="api.php">API Dokümanı</a>
                        <a href="about.html">Hakkımızda</a>
                    </div>
                </div>
                
                <div class="footer-col">
                    <h4>Destek & Yasal</h4>
                    <div class="footer-links">
                        <a href="faq.html">Sıkça Sorulan Sorular</a>
                        <a href="tos.html">Kullanım Şartları</a>
                        <a href="privacy.html">Gizlilik Politikası</a>
                        <a href="refund.html">İade Politikası</a>
                    </div>
                </div>
                
                <div class="footer-col">
                    <h4>Ödeme Yöntemleri</h4>
                    <div class="payment-methods">
                        <i class="fab fa-cc-visa"></i>
                        <i class="fab fa-cc-mastercard"></i>
                        <i class="fab fa-cc-paypal"></i>
                        <i class="fab fa-bitcoin"></i>
                    </div>
                    <p style="margin-top: 15px; font-size: 0.9rem; color: var(--text-muted);">
                        <img src="https://www.paytr.com/img/logo.png" alt="PayTR" style="height: 20px; filter: brightness(0) invert(1); vertical-align: middle; margin-right: 5px;">
                        ile güvenli ödeme.
                    </p>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>Vergi Kimlik No: 2881185234</p>
            </div>
        </div>
    </footer>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const navLinks = document.getElementById('navLinks');
        const navbar = document.getElementById('navbar');

        menuToggle.addEventListener('click', () => {
            navLinks.classList.toggle('active');
            if (window.innerWidth <= 992) {
                const mobileAuth = document.getElementById('mobile-auth');
                if (navLinks.classList.contains('active')) {
                    mobileAuth.style.display = 'flex';
                } else {
                    mobileAuth.style.display = 'none';
                }
            }
        });

        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    </script>
</body>
</html>