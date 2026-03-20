<?php
// error.php
$error_codes = [
    400 => 'Geçersiz İstek',
    401 => 'Yetkisiz Erişim',
    403 => 'Erişim Engellendi',
    404 => 'Sayfa Bulunamadı',
    500 => 'Sunucu Hatası'
];

$error_code = isset($_GET['code']) ? (int)$_GET['code'] : 404;
$error_message = isset($error_codes[$error_code]) ? $error_codes[$error_code] : 'Bilinmeyen Hata';

// Kullanıcı giriş yapmış mı kontrol et
session_start();
$logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hata <?php echo $error_code; ?> - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0F172A, #1E293B);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
        }
        
        .error-container {
            max-width: 600px;
            width: 100%;
        }
        
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            background: linear-gradient(135deg, #8B5CF6, #10B981);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1;
            margin-bottom: 20px;
        }
        
        .error-message {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .error-description {
            color: #94A3B8;
            font-size: 1.1rem;
            margin-bottom: 40px;
            line-height: 1.6;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 30px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8B5CF6, #10B981);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(139, 92, 246, 0.3);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 2px solid #8B5CF6;
        }
        
        .btn-secondary:hover {
            background: #8B5CF6;
            transform: translateY(-3px);
        }
        
        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 1.5rem;
            font-weight: 900;
            color: white;
            margin-bottom: 40px;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8B5CF6, #10B981);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        @media (max-width: 768px) {
            .error-code {
                font-size: 6rem;
            }
            
            .error-message {
                font-size: 1.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <a href="<?php echo $logged_in ? 'dashboard' : '/'; ?>" class="logo">
            <div class="logo-icon">
                <i class="fas fa-bolt"></i>
            </div>
            Darq SMM
        </a>
        
        <div class="error-code"><?php echo $error_code; ?></div>
        <div class="error-message"><?php echo $error_message; ?></div>
        
        <div class="error-description">
            <?php if ($error_code == 404): ?>
                Aradığınız sayfa bulunamadı. Sayfa taşınmış, silinmiş veya adı değiştirilmiş olabilir.
            <?php elseif ($error_code == 403): ?>
                Bu sayfaya erişim izniniz bulunmamaktadır. Yetkilendirme için giriş yapmanız gerekebilir.
            <?php elseif ($error_code == 500): ?>
                Sunucuda bir hata oluştu. Lütfen daha sonra tekrar deneyin.
            <?php else: ?>
                Bir hata oluştu. Lütfen tekrar deneyin.
            <?php endif; ?>
        </div>
        
        <div class="action-buttons">
            <?php if ($logged_in): ?>
                <a href="dashboard" class="btn btn-primary">
                    <i class="fas fa-home"></i> Dashboard'a Dön
                </a>
            <?php else: ?>
                <a href="/" class="btn btn-primary">
                    <i class="fas fa-home"></i> Ana Sayfa
                </a>
                <a href="giris" class="btn btn-secondary">
                    <i class="fas fa-sign-in-alt"></i> Giriş Yap
                </a>
            <?php endif; ?>
            
            <a href="destek" class="btn btn-secondary">
                <i class="fas fa-headset"></i> Destek
            </a>
        </div>
    </div>
</body>
</html>