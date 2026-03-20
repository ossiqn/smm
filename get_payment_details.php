<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die('Yetkisiz erişim!');
}

if (!isset($_GET['id'])) {
    die('Geçersiz istek!');
}

$payment_id = intval($_GET['id']);

try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.email, u.balance as user_balance 
        FROM payments p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        die('Ödeme bulunamadı!');
    }
    
    // Payment method mapping
    $method_names = [
        'bank' => 'Banka Havalesi',
        'credit_card' => 'Kredi Kartı',
        'crypto' => 'Kripto Para',
        'transfer' => 'EFT/Havale',
        'papara' => 'Papara',
        'paypal' => 'PayPal'
    ];
    
    // Status mapping
    $status_names = [
        'pending' => 'Beklemede',
        'completed' => 'Tamamlandı',
        'rejected' => 'Reddedildi',
        'cancelled' => 'İptal Edildi'
    ];
    
    ?>
    <div class="payment-details">
        <div style="display: grid; gap: 15px;">
            <!-- Basic Info -->
            <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: var(--radius);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="color: var(--primary);">Ödeme Bilgileri</h3>
                    <span class="payment-status status-<?php echo $payment['status']; ?>" style="padding: 5px 15px;">
                        <?php echo $status_names[$payment['status']] ?? $payment['status']; ?>
                    </span>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Ödeme ID</div>
                        <div style="font-weight: 600; font-family: monospace;">#<?php echo $payment['id']; ?></div>
                    </div>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Referans ID</div>
                        <div style="font-weight: 600;"><?php echo $payment['payment_id'] ?: 'Belirtilmemiş'; ?></div>
                    </div>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Tutar</div>
                        <div style="font-weight: 700; color: var(--secondary); font-size: 1.2rem;">
                            ₺<?php echo number_format($payment['amount'], 2); ?>
                        </div>
                    </div>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Yöntem</div>
                        <div style="font-weight: 600;">
                            <?php echo $method_names[$payment['method']] ?? ucfirst($payment['method']); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User Info -->
            <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: var(--radius);">
                <h3 style="color: var(--primary); margin-bottom: 10px;">Kullanıcı Bilgileri</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Kullanıcı Adı</div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($payment['username']); ?></div>
                    </div>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Email</div>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($payment['email']); ?></div>
                    </div>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Mevcut Bakiye</div>
                        <div style="font-weight: 600; color: var(--secondary);">
                            ₺<?php echo number_format($payment['user_balance'], 2); ?>
                        </div>
                    </div>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Kullanıcı ID</div>
                        <div style="font-weight: 600;">#<?php echo $payment['user_id']; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Transaction Info -->
            <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: var(--radius);">
                <h3 style="color: var(--primary); margin-bottom: 10px;">İşlem Bilgileri</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Oluşturulma Tarihi</div>
                        <div style="font-weight: 600;">
                            <?php echo date('d.m.Y H:i:s', strtotime($payment['created_at'])); ?>
                        </div>
                    </div>
                    <?php if ($payment['approved_at']): ?>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">Onaylanma Tarihi</div>
                        <div style="font-weight: 600;">
                            <?php echo date('d.m.Y H:i:s', strtotime($payment['approved_at'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($payment['transaction_id']): ?>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">İşlem ID</div>
                        <div style="font-weight: 600; font-family: monospace;"><?php echo $payment['transaction_id']; ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($payment['ip_address']): ?>
                    <div>
                        <div style="color: var(--text-gray); font-size: 0.9rem;">IP Adresi</div>
                        <div style="font-weight: 600;"><?php echo $payment['ip_address']; ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Additional Details -->
            <?php if ($payment['details']): ?>
            <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: var(--radius);">
                <h3 style="color: var(--primary); margin-bottom: 10px;">Ek Bilgiler</h3>
                <div style="color: var(--text-gray); font-size: 0.9rem; line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($payment['details'])); ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Reject Reason -->
            <?php if ($payment['status'] == 'rejected' && $payment['reject_reason']): ?>
            <div style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); padding: 15px; border-radius: var(--radius);">
                <h3 style="color: #EF4444; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-exclamation-triangle"></i> Reddetme Sebebi
                </h3>
                <div style="color: #FCA5A5; font-size: 0.9rem; line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($payment['reject_reason'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.1); text-align: center;">
            <small style="color: var(--text-gray); font-size: 0.85rem;">
                <i class="fas fa-info-circle"></i> 
                Bu ödeme <?php echo date('d.m.Y H:i:s', strtotime($payment['created_at'])); ?> tarihinde oluşturuldu.
            </small>
        </div>
    </div>
    <?php
    
} catch (PDOException $e) {
    echo '<div style="color: var(--danger); padding: 15px; text-align: center;">';
    echo '<i class="fas fa-exclamation-circle"></i> Hata oluştu: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}
?>