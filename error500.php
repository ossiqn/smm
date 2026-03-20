<?php
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Sunucu Hatası</title>
    <style>
        body {
            background: #0F172A;
            color: #F8FAFC;
            font-family: 'Poppins', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            text-align: center;
        }
        .error-container {
            max-width: 500px;
            padding: 40px;
            background: #1E293B;
            border-radius: 16px;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        h1 {
            font-size: 4rem;
            margin: 0;
            background: linear-gradient(135deg, #8B5CF6, #10B981);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        h2 {
            margin: 20px 0;
            color: #F8FAFC;
        }
        p {
            color: #94A3B8;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        a {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #8B5CF6, #10B981);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        a:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.3);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>500</h1>
        <h2>Sunucu Hatası</h2>
        <p>Bir sunucu hatası oluştu. Lütfen daha sonra tekrar deneyin.</p>
        <a href="/dashboard.php">
            <i class="fas fa-home"></i> Ana Sayfaya Dön
        </a>
    </div>
</body>
</html>