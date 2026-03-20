<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $balance = floatval($_POST['balance']);
        $role = $_POST['role'];
        
        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            if ($check->rowCount() > 0) {
                throw new Exception("Bu kullanıcı adı veya e-posta zaten kullanılıyor.");
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $api_key = bin2hex(random_bytes(16));
            
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, balance, role, api_key, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
            $stmt->execute([$username, $email, $hashed_password, $balance, $role, $api_key]);
            
            $_SESSION['success'] = "Kullanıcı başarıyla oluşturuldu.";
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header("Location: admin_users.php");
        exit;
    }

    if (isset($_POST['update_user'])) {
        $user_id = $_POST['user_id'];
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $balance = floatval($_POST['balance']);
        $role = $_POST['role'];
        $status = $_POST['status'];
        $password = $_POST['password'];

        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $check->execute([$username, $email, $user_id]);
            if ($check->rowCount() > 0) {
                throw new Exception("Bu kullanıcı adı veya e-posta başka bir kullanıcı tarafından kullanılıyor.");
            }

            $sql = "UPDATE users SET username = ?, email = ?, balance = ?, role = ?, status = ? WHERE id = ?";
            $params = [$username, $email, $balance, $role, $status, $user_id];

            if (!empty($password)) {
                $sql = "UPDATE users SET username = ?, email = ?, balance = ?, role = ?, status = ?, password = ? WHERE id = ?";
                $params = [$username, $email, $balance, $role, $status, password_hash($password, PASSWORD_DEFAULT), $user_id];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $_SESSION['success'] = "Kullanıcı bilgileri güncellendi.";
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header("Location: admin_users.php");
        exit;
    }

    if (isset($_POST['add_balance'])) {
        $user_id = $_POST['user_id'];
        $amount = floatval($_POST['amount']);
        $note = $_POST['note'];

        try {
            if ($amount == 0) throw new Exception("Tutar 0 olamaz.");

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $user_id]);

            $type = $amount > 0 ? 'admin_add' : 'admin_deduct';
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $type, abs($amount), "Admin İşlemi: " . $note]);

            $pdo->commit();
            $_SESSION['success'] = "Bakiye işlemi başarıyla uygulandı.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Hata: " . $e->getMessage();
        }
        header("Location: admin_users.php");
        exit;
    }

    if (isset($_POST['delete_user'])) {
        $user_id = $_POST['user_id'];
        
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['error'] = "Kendinizi silemezsiniz.";
            header("Location: admin_users.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM orders WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM payments WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM tickets WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$user_id]);
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);

            $pdo->commit();
            $_SESSION['success'] = "Kullanıcı ve ilişkili tüm veriler silindi.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Silme hatası: " . $e->getMessage();
        }
        header("Location: admin_users.php");
        exit;
    }
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';

$where = "1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (username LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($role_filter != 'all') {
    $where .= " AND role = ?";
    $params[] = $role_filter;
}

$total_users = 0;
$users = [];
$stats = [
    'total_users' => 0,
    'total_balance' => 0,
    'active_users' => 0
];

try {
    $count_sql = "SELECT COUNT(*) FROM users WHERE $where";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_users = $stmt->fetchColumn();
    $total_pages = ceil($total_users / $limit);

    $sql = "SELECT * FROM users WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['total_balance'] = $pdo->query("SELECT SUM(balance) FROM users")->fetchColumn();
    $stats['active_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

} catch (PDOException $e) {}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanıcı Yönetimi - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --secondary: #10B981;
            --accent: #F59E0B;
            --danger: #EF4444;
            --bg-body: #020617;
            --bg-card: rgba(30, 41, 59, 0.6);
            --text-main: #F8FAFC;
            --text-muted: #94A3B8;
            --gradient-main: linear-gradient(135deg, #8B5CF6 0%, #4F46E5 100%);
            --gradient-text: linear-gradient(135deg, #C4B5FD 0%, #6EE7B7 100%);
            --glass-border: 1px solid rgba(255, 255, 255, 0.08);
            --glow: 0 0 30px rgba(139, 92, 246, 0.3);
            --radius: 20px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); min-height: 100vh; overflow-x: hidden; }

        .background-glow { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; animation: float 10s infinite alternate; }
        .blob-1 { top: -10%; left: -10%; width: 600px; height: 600px; background: var(--primary); }
        .blob-2 { bottom: 10%; right: -10%; width: 500px; height: 500px; background: #059669; animation-delay: -5s; }
        @keyframes float { 0% { transform: translate(0,0); } 100% { transform: translate(30px,30px); } }

        .navbar { padding: 20px 0; position: fixed; width: 100%; top: 0; z-index: 1000; background: rgba(2, 6, 23, 0.8); backdrop-filter: blur(15px); border-bottom: var(--glass-border); }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        .logo { font-family: 'Outfit'; font-size: 1.6rem; font-weight: 800; color: white; text-decoration: none; display: flex; gap: 10px; align-items: center; }
        .logo i { color: var(--primary); }
        .admin-badge { background: var(--danger); color: white; padding: 2px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: bold; letter-spacing: 1px; }

        .nav-menu { display: flex; gap: 20px; align-items: center; }
        .nav-menu a { color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: 0.3s; display: flex; align-items: center; gap: 6px; }
        .nav-menu a:hover, .nav-menu a.active { color: white; }
        .nav-menu a.active { color: var(--primary); background: rgba(139, 92, 246, 0.1); padding: 5px 12px; border-radius: 10px; }

        .main-content { padding: 100px 0 40px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .page-header h1 { font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: white; }
        .gradient-text { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-card); border: var(--glass-border); padding: 25px; border-radius: 20px; position: relative; overflow: hidden; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        .stat-header { display: flex; justify-content: space-between; align-items: center; }
        .stat-value { font-family: 'Outfit'; font-size: 2.2rem; font-weight: 700; color: white; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; margin-top: 5px; }
        .stat-icon { width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .filter-section { background: var(--bg-card); padding: 25px; border-radius: 24px; border: var(--glass-border); margin-bottom: 30px; }
        .filter-form { display: grid; grid-template-columns: 2fr 1fr auto; gap: 20px; align-items: end; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem; font-weight: 500; }
        .modern-input { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: 0.3s; font-family: 'Plus Jakarta Sans'; }
        .modern-input:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }
        .form-control { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }

        .btn { padding: 12px 25px; border-radius: 12px; border: none; font-weight: 600; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; text-decoration: none; justify-content: center; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-secondary { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid rgba(255,255,255,0.1); }
        .btn-secondary:hover { background: rgba(255,255,255,0.1); color: white; }
        .btn-success { background: rgba(16,185,129,0.15); color: var(--secondary); border: 1px solid rgba(16,185,129,0.2); }
        .btn-success:hover { background: rgba(16,185,129,0.25); color: white; }

        .table-container { background: var(--bg-card); border-radius: 24px; border: var(--glass-border); overflow: hidden; backdrop-filter: blur(10px); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th { text-align: left; padding: 20px; background: rgba(0,0,0,0.3); color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        td { padding: 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); font-size: 0.95rem; vertical-align: middle; }
        tr:hover { background: rgba(255,255,255,0.02); }

        .role-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .role-admin { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }
        .role-user { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }
        
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .status-active { background: rgba(16, 185, 129, 0.15); color: #10B981; }
        .status-banned { background: rgba(239, 68, 68, 0.15); color: #EF4444; }

        .action-btn { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: 0.2s; margin-right: 5px; }
        .btn-view { background: rgba(139, 92, 246, 0.15); color: #8B5CF6; } .btn-view:hover { background: #8B5CF6; color: white; }
        .btn-edit { background: rgba(59, 130, 246, 0.15); color: #3B82F6; } .btn-edit:hover { background: #3B82F6; color: white; }
        .btn-balance { background: rgba(16, 185, 129, 0.15); color: #10B981; } .btn-balance:hover { background: #10B981; color: white; }
        .btn-delete { background: rgba(239, 68, 68, 0.15); color: #EF4444; } .btn-delete:hover { background: #EF4444; color: white; }

        .pagination { display: flex; justify-content: center; margin-top: 30px; gap: 8px; }
        .page-link { padding: 10px 16px; border-radius: 12px; background: var(--bg-card); border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); text-decoration: none; transition: 0.3s; font-size: 0.9rem; }
        .page-link:hover, .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: #0f172a; width: 95%; max-width: 500px; padding: 30px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7); animation: zoomIn 0.3s ease; }
        .close-modal { position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.05); border: none; width: 32px; height: 32px; border-radius: 50%; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.3s; }
        .close-modal:hover { background: #EF4444; color: white; }
        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .detail-item { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .detail-item:last-child { border-bottom: none; }
        .detail-label { color: var(--text-muted); font-size: 0.9rem; }
        .detail-value { color: white; font-weight: 600; font-size: 0.95rem; }

        .swal2-popup { background: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 20px !important; color: white !important; }
        .swal2-title { color: white !important; font-family: 'Outfit' !important; }
        .swal2-html-container { color: #94a3b8 !important; }

        @media (max-width: 992px) {
            .nav-menu { display: none; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filter-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="background-glow">
        <div class="glow-blob blob-1"></div>
        <div class="glow-blob blob-2"></div>
    </div>

    <nav class="navbar">
        <div class="container nav-inner">
            <a href="admin_dashboard.php" class="logo">
                <i class="fas fa-shield-alt"></i> Darq <span class="admin-badge">ADMIN</span>
            </a>
            
            <div class="nav-menu">
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="admin_orders.php">Siparişler</a>
                <a href="admin_payments.php">Ödemeler</a>
                <a href="admin_users.php" class="active">Kullanıcılar</a>
                <a href="admin_services.php">Servisler</a>
                <a href="admin_tickets.php">Destek</a>
                <a href="dashboard.php" style="color: var(--accent);">Siteye Dön <i class="fas fa-external-link-alt"></i></a>
            </div>

            <div style="display:flex; gap:15px; align-items:center;">
                <span style="color:white; font-weight:600;"><?php echo htmlspecialchars($admin['username']); ?></span>
                <a href="logout.php" class="btn btn-primary" style="padding: 8px 15px; width: auto; font-size: 0.85rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #EF4444; box-shadow: none;">Çıkış</a>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        
        <div class="page-header">
            <h1>Kullanıcı <span class="gradient-text">Yönetimi</span></h1>
            <button onclick="openAddModal()" class="btn btn-primary"><i class="fas fa-user-plus"></i> Yeni Kullanıcı</button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                        <div class="stat-label">Toplam Kullanıcı</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value" style="color: #10B981;"><?php echo number_format($stats['active_users']); ?></div>
                        <div class="stat-label">Aktif Kullanıcı</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value">₺<?php echo number_format($stats['total_balance'], 2); ?></div>
                        <div class="stat-label">Toplam Bakiye</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                </div>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Arama</label>
                    <input type="text" name="search" class="modern-input" placeholder="Kullanıcı adı, email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Rol</label>
                    <select name="role" class="modern-input">
                        <option value="all">Tümü</option>
                        <option value="user" <?php echo $role_filter == 'user' ? 'selected' : ''; ?>>Kullanıcı</option>
                        <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="height: 46px; margin-top: 24px; width: 100%;"><i class="fas fa-filter"></i> Filtrele</button>
                </div>
            </form>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kullanıcı Adı</th>
                            <th>Email</th>
                            <th>Bakiye</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th>Kayıt</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr><td colspan="8" style="text-align:center; padding: 40px; color: var(--text-muted);">Kullanıcı bulunamadı.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--primary); font-family: monospace;">#<?php echo $user['id']; ?></div>
                                </td>
                                <td style="font-weight: 600; color: white; cursor:pointer;" onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($user)); ?>)"><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td style="color: #10B981;">₺<?php echo number_format($user['balance'], 2); ?></td>
                                <td><span class="role-badge role-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                                <td><span class="status-badge status-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                <td><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <button class="action-btn btn-view" onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" title="Detaylar">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" title="Düzenle">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btn btn-balance" onclick="openBalanceModal('<?php echo $user['id']; ?>', '<?php echo $user['username']; ?>')" title="Bakiye">
                                        <i class="fas fa-coins"></i>
                                    </button>
                                    <button class="action-btn btn-delete" onclick="confirmDelete('<?php echo $user['id']; ?>')" title="Sil">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="detailModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button>
            <h2 style="font-family: 'Outfit'; color: white; margin-bottom: 20px;">Kullanıcı Detayları</h2>
            <div id="userDetailContent"></div>
        </div>
    </div>

    <div id="addModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
            <h2 style="font-family: 'Outfit'; color: white; margin-bottom: 20px;">Yeni Kullanıcı</h2>
            <form method="POST">
                <input type="hidden" name="create_user" value="1">
                <div class="form-group">
                    <label>Kullanıcı Adı</label>
                    <input type="text" name="username" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label>Şifre</label>
                    <input type="text" name="password" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label>Bakiye (₺)</label>
                    <input type="number" step="0.01" name="balance" class="modern-input" value="0">
                </div>
                <div class="form-group">
                    <label>Rol</label>
                    <select name="role" class="modern-input">
                        <option value="user">Kullanıcı</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Oluştur</button>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
            <h2 style="font-family: 'Outfit'; color: white; margin-bottom: 20px;">Kullanıcı Düzenle</h2>
            <form method="POST">
                <input type="hidden" name="update_user" value="1">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                    <label>Kullanıcı Adı</label>
                    <input type="text" name="username" id="edit_username" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" id="edit_email" class="modern-input" required>
                </div>
                <div class="form-group">
                    <label>Şifre (Değişmeyecekse boş bırakın)</label>
                    <input type="text" name="password" class="modern-input">
                </div>
                <div class="form-group">
                    <label>Bakiye (₺)</label>
                    <input type="number" step="0.01" name="balance" id="edit_balance" class="modern-input">
                </div>
                <div class="form-group">
                    <label>Rol</label>
                    <select name="role" id="edit_role" class="modern-input">
                        <option value="user">Kullanıcı</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Durum</label>
                    <select name="status" id="edit_status" class="modern-input">
                        <option value="active">Aktif</option>
                        <option value="banned">Yasaklı</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Güncelle</button>
            </form>
        </div>
    </div>

    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('balanceModal')"><i class="fas fa-times"></i></button>
            <h2 style="font-family: 'Outfit'; color: white; margin-bottom: 10px;">Bakiye İşlemi</h2>
            <p id="balance_username" style="margin-bottom: 20px; color: var(--primary); font-weight: bold; text-align:center; font-size:1.1rem;"></p>
            <form method="POST">
                <input type="hidden" name="add_balance" value="1">
                <input type="hidden" name="user_id" id="balance_user_id">
                <div class="form-group">
                    <label>Tutar (₺) - Düşmek için eksi (-) kullanın</label>
                    <input type="number" step="0.01" name="amount" class="modern-input" required placeholder="Örn: 50 veya -20">
                </div>
                <div class="form-group">
                    <label>Açıklama</label>
                    <input type="text" name="note" class="modern-input" placeholder="Bonus, iade vb.">
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 10px;">İşlemi Uygula</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        <?php if(isset($_SESSION['success'])): ?>
            Swal.fire({icon: 'success', title: 'Başarılı!', text: '<?php echo $_SESSION['success']; unset($_SESSION['success']); ?>', timer: 2000, showConfirmButton: false, background: '#1e293b', color: '#fff'});
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
            Swal.fire({icon: 'error', title: 'Hata!', text: '<?php echo $_SESSION['error']; unset($_SESSION['error']); ?>', background: '#1e293b', color: '#fff'});
        <?php endif; ?>

        function openAddModal() { document.getElementById('addModal').style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        
        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_balance').value = user.balance;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_status').value = user.status;
            document.getElementById('editModal').style.display = 'flex';
        }

        function openBalanceModal(id, username) {
            document.getElementById('balance_user_id').value = id;
            document.getElementById('balance_username').innerText = username;
            document.getElementById('balanceModal').style.display = 'flex';
        }

        function openDetailModal(user) {
            const html = `
                <div class="detail-item"><span class="detail-label">ID:</span><span class="detail-value">#${user.id}</span></div>
                <div class="detail-item"><span class="detail-label">Kullanıcı Adı:</span><span class="detail-value">${user.username}</span></div>
                <div class="detail-item"><span class="detail-label">Email:</span><span class="detail-value">${user.email}</span></div>
                <div class="detail-item"><span class="detail-label">Telefon:</span><span class="detail-value">${user.phone || '-'}</span></div>
                <div class="detail-item"><span class="detail-label">Ülke:</span><span class="detail-value">${user.country || '-'}</span></div>
                <div class="detail-item"><span class="detail-label">Bakiye:</span><span class="detail-value" style="color:#10B981">₺${parseFloat(user.balance).toFixed(2)}</span></div>
                <div class="detail-item"><span class="detail-label">Rol:</span><span class="detail-value">${user.role.toUpperCase()}</span></div>
                <div class="detail-item"><span class="detail-label">Durum:</span><span class="detail-value">${user.status.toUpperCase()}</span></div>
                <div class="detail-item"><span class="detail-label">IP Adresi:</span><span class="detail-value">${user.ip_address || '-'}</span></div>
                <div class="detail-item"><span class="detail-label">Kayıt Tarihi:</span><span class="detail-value">${new Date(user.created_at).toLocaleString()}</span></div>
                <div class="detail-item"><span class="detail-label">Son Giriş:</span><span class="detail-value">${user.last_login ? new Date(user.last_login).toLocaleString() : '-'}</span></div>
            `;
            document.getElementById('userDetailContent').innerHTML = html;
            document.getElementById('detailModal').style.display = 'flex';
        }

        function confirmDelete(id) {
            Swal.fire({
                title: 'Silmek istediğine emin misin?',
                text: "Bu kullanıcıya ait tüm veriler (Siparişler, Ödemeler vb.) kalıcı olarak silinecek!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#EF4444',
                cancelButtonColor: '#3B82F6',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal',
                background: '#1e293b',
                color: '#fff'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="delete_user" value="1"><input type="hidden" name="user_id" value="${id}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            })
        }
    </script>
</body>
</html>