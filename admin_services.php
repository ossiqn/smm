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
    if (isset($_POST['add_service'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $cost = $_POST['cost'];
        $min_quantity = $_POST['min_quantity'];
        $max_quantity = $_POST['max_quantity'];
        $api_service_id = $_POST['api_service_id'];
        $api_provider = $_POST['api_provider'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO services (name, description, category, price, cost, min_quantity, max_quantity, api_service_id, api_provider, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $description, $category, $price, $cost, $min_quantity, $max_quantity, $api_service_id, $api_provider, $status]);
            $_SESSION['success'] = "Servis başarıyla oluşturuldu.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Hata: " . $e->getMessage();
        }
        header("Location: admin_services.php");
        exit;
    }

    if (isset($_POST['update_service'])) {
        $service_id = $_POST['service_id'];
        $name = $_POST['name'];
        $description = $_POST['description'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $cost = $_POST['cost'];
        $min_quantity = $_POST['min_quantity'];
        $max_quantity = $_POST['max_quantity'];
        $api_service_id = $_POST['api_service_id'];
        $api_provider = $_POST['api_provider'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE services SET name = ?, description = ?, category = ?, price = ?, cost = ?, min_quantity = ?, max_quantity = ?, api_service_id = ?, api_provider = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $description, $category, $price, $cost, $min_quantity, $max_quantity, $api_service_id, $api_provider, $status, $service_id]);
            $_SESSION['success'] = "Servis güncellendi.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Hata: " . $e->getMessage();
        }
        header("Location: admin_services.php");
        exit;
    }

    if (isset($_POST['delete_service'])) {
        $service_id = $_POST['service_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
            $stmt->execute([$service_id]);
            $_SESSION['success'] = "Servis silindi.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Silme hatası.";
        }
        header("Location: admin_services.php");
        exit;
    }
}

$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "1=1";
$params = [];

if ($category_filter !== 'all') {
    $where .= " AND category = ?";
    $params[] = $category_filter;
}

if ($status_filter !== 'all') {
    $where .= " AND status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $where .= " AND (name LIKE ? OR description LIKE ? OR api_service_id LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$total_services = 0;
$services = [];
$categories = [];
$stats = ['total' => 0, 'active' => 0, 'categories' => 0];

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE $where");
    $count_stmt->execute($params);
    $total_services = $count_stmt->fetchColumn();
    $total_pages = ceil($total_services / $limit);
    
    $stmt = $pdo->prepare("SELECT * FROM services WHERE $where ORDER BY category, name ASC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $services = $stmt->fetchAll();

    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM services WHERE category IS NOT NULL ORDER BY category");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

    $stats['total'] = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
    $stats['active'] = $pdo->query("SELECT COUNT(*) FROM services WHERE status = 'active'")->fetchColumn();
    $stats['categories'] = count($categories);
    
} catch (PDOException $e) {}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Servis Yönetimi - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; }
        .blob-1 { top: -10%; left: -10%; width: 600px; height: 600px; background: var(--primary); }
        .blob-2 { bottom: 10%; right: -10%; width: 500px; height: 500px; background: #059669; }

        .navbar { padding: 20px 0; position: fixed; width: 100%; top: 0; z-index: 1000; background: rgba(2, 6, 23, 0.8); backdrop-filter: blur(15px); border-bottom: var(--glass-border); }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        .logo { font-family: 'Outfit'; font-size: 1.6rem; font-weight: 800; color: white; text-decoration: none; display: flex; gap: 10px; align-items: center; }
        .logo i { color: var(--primary); }
        .admin-badge { background: var(--accent); color: #000; padding: 2px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: bold; }

        .nav-menu { display: flex; gap: 20px; align-items: center; }
        .nav-menu a { color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: 0.3s; display: flex; align-items: center; gap: 6px; }
        .nav-menu a:hover, .nav-menu a.active { color: white; }
        .nav-menu a.active { color: var(--primary); background: rgba(139, 92, 246, 0.1); padding: 5px 12px; border-radius: 10px; }

        .main-content { padding: 100px 0 40px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .page-header h1 { font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: white; }
        .gradient-text { background: var(--gradient-main); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .header-actions { display: flex; gap: 10px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-card); border: var(--glass-border); padding: 25px; border-radius: 20px; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        .stat-header { display: flex; justify-content: space-between; align-items: center; }
        .stat-value { font-family: 'Outfit'; font-size: 2.2rem; font-weight: 700; color: white; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; margin-top: 5px; }
        .stat-icon { width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .filter-section { background: var(--bg-card); padding: 25px; border-radius: 24px; border: var(--glass-border); margin-bottom: 30px; }
        .filter-form { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 20px; align-items: end; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem; font-weight: 500; }
        .modern-input { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: 0.3s; font-family: 'Plus Jakarta Sans'; }
        .modern-input:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }
        .form-control { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }

        .btn { padding: 12px 25px; border-radius: 12px; border: none; cursor: pointer; font-weight: 600; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; text-decoration: none; color: white; justify-content: center; }
        .btn-primary { background: var(--gradient-main); box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-info { background: #3B82F6; } .btn-info:hover { background: #2563EB; }
        .btn-success { background: var(--secondary); } .btn-success:hover { background: #059669; }
        .btn-secondary { background: rgba(255,255,255,0.1); } .btn-secondary:hover { background: rgba(255,255,255,0.2); }

        .table-container { background: var(--bg-card); border-radius: 24px; border: var(--glass-border); overflow: hidden; backdrop-filter: blur(10px); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th { text-align: left; padding: 20px; background: rgba(0,0,0,0.3); color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        td { padding: 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); font-size: 0.95rem; vertical-align: middle; }
        tr:hover { background: rgba(255,255,255,0.02); }

        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .status-active { background: rgba(16, 185, 129, 0.15); color: #10B981; }
        .status-inactive { background: rgba(239, 68, 68, 0.15); color: #EF4444; }

        .action-btn { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: 0.2s; margin-right: 5px; }
        .btn-edit { background: rgba(59, 130, 246, 0.15); color: #3B82F6; } .btn-edit:hover { background: #3B82F6; color: white; }
        .btn-delete { background: rgba(239, 68, 68, 0.15); color: #EF4444; } .btn-delete:hover { background: #EF4444; color: white; }

        .pagination { display: flex; justify-content: center; margin-top: 30px; gap: 8px; }
        .page-link { padding: 10px 16px; border-radius: 12px; background: var(--bg-card); border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); text-decoration: none; transition: 0.3s; font-size: 0.9rem; }
        .page-link:hover, .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: #0f172a; width: 95%; max-width: 800px; padding: 30px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7); animation: zoomIn 0.3s ease; max-height: 90vh; overflow-y: auto; }
        .close-modal { position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.05); border: none; width: 32px; height: 32px; border-radius: 50%; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.3s; }
        .close-modal:hover { background: #EF4444; color: white; }
        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        .swal2-popup { background: #1e293b !important; border-radius: 24px !important; border: 1px solid rgba(139,92,246,0.3) !important; }
        .swal2-title { color: white !important; font-family: 'Outfit' !important; }
        .swal2-content { color: #cbd5e1 !important; }

        @media (max-width: 992px) {
            .nav-menu { display: none; }
            .stats-grid { grid-template-columns: 1fr; }
            .filter-form { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
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
                <a href="admin_users.php">Kullanıcılar</a>
                <a href="admin_services.php" class="active">Servisler</a>
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
            <h1>Servis <span class="gradient-text">Yönetimi</span></h1>
            <div class="header-actions">
                <button onclick="openModal('addModal')" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Yeni Servis</button>
                <button onclick="openModal('importModal')" class="btn btn-info"><i class="fas fa-file-import"></i> Toplu Ekle</button>
                <button onclick="exportServices()" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                        <div class="stat-label">Toplam Servis</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-server"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value" style="color: #10B981;"><?php echo number_format($stats['active']); ?></div>
                        <div class="stat-label">Aktif Servis</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($stats['categories']); ?></div>
                        <div class="stat-label">Toplam Kategori</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-tags"></i></div>
                </div>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Arama</label>
                    <input type="text" name="search" class="modern-input" placeholder="Servis adı, ID..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Kategori</label>
                    <select name="category" class="modern-input">
                        <option value="all">Tümü</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Durum</label>
                    <select name="status" class="modern-input">
                        <option value="all">Tümü</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Aktif</option>
                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Pasif</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="height: 46px; width: 100%; margin-top: 24px;"><i class="fas fa-filter"></i> Filtrele</button>
                </div>
            </form>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Servis Adı</th>
                            <th>Kategori</th>
                            <th>Fiyat</th>
                            <th>Maliyet</th>
                            <th>Min/Max</th>
                            <th>Durum</th>
                            <th>API ID</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($services)): ?>
                        <tr><td colspan="8" style="text-align:center; padding: 40px; color: var(--text-muted);">Servis bulunamadı.</td></tr>
                        <?php else: ?>
                            <?php foreach ($services as $service): ?>
                            <tr>
                                <td style="font-weight: 600; white-space:normal; min-width:200px;"><?php echo htmlspecialchars($service['name']); ?></td>
                                <td><span style="color:var(--primary); font-size:0.85rem;"><?php echo htmlspecialchars($service['category']); ?></span></td>
                                <td style="color:#10B981; font-weight:600;">₺<?php echo number_format($service['price'], 2); ?></td>
                                <td style="color:var(--text-muted);">₺<?php echo number_format($service['cost'], 2); ?></td>
                                <td style="font-size:0.8rem;"><?php echo $service['min_quantity']; ?> - <?php echo $service['max_quantity']; ?></td>
                                <td><span class="status-badge <?php echo $service['status'] == 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo $service['status'] == 'active' ? 'Aktif' : 'Pasif'; ?></span></td>
                                <td style="font-family:monospace; color: var(--text-muted);"><?php echo $service['api_service_id']; ?></td>
                                <td>
                                    <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($service)); ?>)"><i class="fas fa-edit"></i></button>
                                    <button class="action-btn btn-delete" onclick="confirmDelete('<?php echo $service['id']; ?>')"><i class="fas fa-trash"></i></button>
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
                <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="addModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
            <h2 style="margin-bottom: 20px; font-family: 'Outfit'; color: white;">Yeni Servis Ekle</h2>
            <form method="POST">
                <input type="hidden" name="add_service" value="1">
                <div class="form-group">
                    <label>Servis Adı</label>
                    <input type="text" name="name" class="modern-input" required>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Kategori</label>
                        <input type="text" name="category" class="modern-input" required>
                    </div>
                    <div class="form-group">
                        <label>Durum</label>
                        <select name="status" class="modern-input">
                            <option value="active">Aktif</option>
                            <option value="inactive">Pasif</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Satış Fiyatı (₺)</label>
                        <input type="number" step="0.0001" name="price" class="modern-input" required>
                    </div>
                    <div class="form-group">
                        <label>Maliyet (₺)</label>
                        <input type="number" step="0.0001" name="cost" class="modern-input" required>
                    </div>
                    <div class="form-group">
                        <label>Min. Miktar</label>
                        <input type="number" name="min_quantity" class="modern-input" value="100">
                    </div>
                    <div class="form-group">
                        <label>Max. Miktar</label>
                        <input type="number" name="max_quantity" class="modern-input" value="10000">
                    </div>
                    <div class="form-group">
                        <label>API Servis ID</label>
                        <input type="text" name="api_service_id" class="modern-input">
                    </div>
                    <div class="form-group">
                        <label>API Sağlayıcı</label>
                        <input type="text" name="api_provider" class="modern-input">
                    </div>
                </div>
                <div class="form-group">
                    <label>Açıklama</label>
                    <textarea name="description" class="modern-input" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Ekle</button>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
            <h2 style="margin-bottom: 20px; font-family: 'Outfit'; color: white;">Servis Düzenle</h2>
            <form method="POST">
                <input type="hidden" name="update_service" value="1">
                <input type="hidden" name="service_id" id="edit_service_id">
                <div class="form-group">
                    <label>Servis Adı</label>
                    <input type="text" name="name" id="edit_name" class="modern-input" required>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Kategori</label>
                        <input type="text" name="category" id="edit_category" class="modern-input" required>
                    </div>
                    <div class="form-group">
                        <label>Durum</label>
                        <select name="status" id="edit_status" class="modern-input">
                            <option value="active">Aktif</option>
                            <option value="inactive">Pasif</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Satış Fiyatı (₺)</label>
                        <input type="number" step="0.0001" name="price" id="edit_price" class="modern-input" required>
                    </div>
                    <div class="form-group">
                        <label>Maliyet (₺)</label>
                        <input type="number" step="0.0001" name="cost" id="edit_cost" class="modern-input" required>
                    </div>
                    <div class="form-group">
                        <label>Min. Miktar</label>
                        <input type="number" name="min_quantity" id="edit_min" class="modern-input">
                    </div>
                    <div class="form-group">
                        <label>Max. Miktar</label>
                        <input type="number" name="max_quantity" id="edit_max" class="modern-input">
                    </div>
                    <div class="form-group">
                        <label>API Servis ID</label>
                        <input type="text" name="api_service_id" id="edit_api_id" class="modern-input">
                    </div>
                    <div class="form-group">
                        <label>API Sağlayıcı</label>
                        <input type="text" name="api_provider" id="edit_api_provider" class="modern-input">
                    </div>
                </div>
                <div class="form-group">
                    <label>Açıklama</label>
                    <textarea name="description" id="edit_desc" class="modern-input" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Güncelle</button>
            </form>
        </div>
    </div>

    <div id="importModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('importModal')"><i class="fas fa-times"></i></button>
            <h2 style="margin-bottom: 20px; font-family: 'Outfit'; color: white;">Toplu Servis Ekle</h2>
            <div style="text-align: center; padding: 40px;">
                <i class="fas fa-cloud-download-alt" style="font-size: 3rem; color: var(--primary); margin-bottom: 20px;"></i>
                <p style="color: var(--text-muted); margin-bottom: 20px;">API üzerinden servisleri otomatik çekme özelliği yakında aktif olacaktır.</p>
                <button onclick="closeModal('importModal')" class="btn btn-info" style="width: 100%;">Anlaşıldı</button>
            </div>
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

        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        function openEditModal(service) {
            document.getElementById('edit_service_id').value = service.id;
            document.getElementById('edit_name').value = service.name;
            document.getElementById('edit_category').value = service.category;
            document.getElementById('edit_price').value = service.price;
            document.getElementById('edit_cost').value = service.cost;
            document.getElementById('edit_min').value = service.min_quantity;
            document.getElementById('edit_max').value = service.max_quantity;
            document.getElementById('edit_api_id').value = service.api_service_id;
            document.getElementById('edit_api_provider').value = service.api_provider;
            document.getElementById('edit_status').value = service.status;
            document.getElementById('edit_desc').value = service.description;
            openModal('editModal');
        }

        function confirmDelete(id) {
            Swal.fire({
                title: 'Silmek istediğine emin misin?',
                text: "Bu işlem geri alınamaz!",
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
                    form.innerHTML = `<input type="hidden" name="delete_service" value="1"><input type="hidden" name="service_id" value="${id}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            })
        }

        function exportServices() {
            Swal.fire({
                icon: 'info',
                title: 'Bilgi',
                text: 'Excel export özelliği yakında eklenecek.',
                background: '#1e293b',
                color: '#fff'
            });
        }
    </script>
</body>
</html>