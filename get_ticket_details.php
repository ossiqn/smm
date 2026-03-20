<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo '<div style="text-align: center; padding: 40px;"><i class="fas fa-exclamation-triangle"></i> Oturum bulunamadı.</div>';
    exit;
}

if (!isset($_REQUEST['ticket_id']) || empty($_REQUEST['ticket_id'])) {
    echo '<div style="text-align: center; padding: 40px;"><i class="fas fa-exclamation-triangle"></i> Ticket ID bulunamadı.</div>';
    exit;
}

$ticket_id = $_REQUEST['ticket_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->execute([$ticket_id, $_SESSION['user_id']]);
    $ticket = $stmt->fetch();
    
    if (!$ticket) {
        echo '<div style="text-align: center; padding: 40px;"><i class="fas fa-exclamation-triangle"></i> Ticket bulunamadı veya bu ticket\'a erişim izniniz yok.</div>';
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT tm.*, u.username 
                          FROM ticket_messages tm 
                          LEFT JOIN users u ON tm.user_id = u.id 
                          WHERE tm.ticket_id = ? 
                          ORDER BY tm.created_at ASC");
    $stmt->execute([$ticket_id]);
    $messages = $stmt->fetchAll();
    
    $created_date = date('d.m.Y H:i', strtotime($ticket['created_at']));
    $updated_date = date('d.m.Y H:i', strtotime($ticket['updated_at']));
    
    $status_texts = [
        'open' => 'Açık',
        'in_progress' => 'İşleniyor',
        'answered' => 'Cevaplandı',
        'closed' => 'Kapalı',
        'resolved' => 'Çözüldü'
    ];
    
    $priority_texts = [
        'low' => 'Düşük',
        'medium' => 'Orta',
        'high' => 'Yüksek',
        'urgent' => 'Acil'
    ];
    
    $status_color = [
        'open' => '#3B82F6',
        'in_progress' => '#F59E0B',
        'answered' => '#10B981',
        'closed' => '#94A3B8',
        'resolved' => '#8B5CF6'
    ];
    
    $priority_color = [
        'low' => '#10B981',
        'medium' => '#F59E0B',
        'high' => '#EF4444',
        'urgent' => '#8B5CF6'
    ];
    ?>
    
    <div class="ticket-details">
        <div class="ticket-detail-row">
            <div><strong>Ticket ID:</strong></div>
            <div style="color: #8B5CF6; font-weight: 600;">#<?php echo htmlspecialchars($ticket['ticket_id']); ?></div>
        </div>
        
        <div class="ticket-detail-row">
            <div><strong>Durum:</strong></div>
            <div>
                <span style="padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; background: <?php echo $status_color[$ticket['status']] . '20'; ?>; color: <?php echo $status_color[$ticket['status']]; ?>; border: 1px solid <?php echo $status_color[$ticket['status']] . '40'; ?>;">
                    <?php echo $status_texts[$ticket['status']] ?? $ticket['status']; ?>
                </span>
            </div>
        </div>
        
        <div class="ticket-detail-row">
            <div><strong>Öncelik:</strong></div>
            <div>
                <span style="padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; background: <?php echo $priority_color[$ticket['priority']] . '20'; ?>; color: <?php echo $priority_color[$ticket['priority']]; ?>; border: 1px solid <?php echo $priority_color[$ticket['priority']] . '40'; ?>;">
                    <?php echo $priority_texts[$ticket['priority']] ?? $ticket['priority']; ?>
                </span>
            </div>
        </div>
        
        <div class="ticket-detail-row">
            <div><strong>Kategori:</strong></div>
            <div><?php echo htmlspecialchars($ticket['category']); ?></div>
        </div>
        
        <div class="ticket-detail-row">
            <div><strong>Oluşturulma:</strong></div>
            <div><?php echo $created_date; ?></div>
        </div>
        
        <div class="ticket-detail-row">
            <div><strong>Son Güncelleme:</strong></div>
            <div><?php echo $updated_date; ?></div>
        </div>
    </div>
    
    <div class="ticket-conversation" style="max-height: 400px; overflow-y: auto; padding: 15px; background: rgba(0,0,0,0.1); border-radius: var(--radius);">
        <div class="message user">
            <div class="message-header">
                <div class="message-sender"><?php echo htmlspecialchars($ticket['username']); ?> (Siz)</div>
                <div class="message-time"><?php echo $created_date; ?></div>
            </div>
            <div class="message-content" style="padding: 10px 0;">
                <?php echo nl2br(htmlspecialchars($ticket['message'])); ?>
            </div>
        </div>
        
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): 
                $message_date = date('d.m.Y H:i', strtotime($message['created_at']));
                $is_admin = $message['is_admin'] == 1;
                $sender = $is_admin ? 'Destek Ekibi' : ($message['username'] ?? 'Kullanıcı');
            ?>
            <div class="message <?php echo $is_admin ? 'admin' : 'user'; ?>">
                <div class="message-header">
                    <div class="message-sender"><?php echo htmlspecialchars($sender); ?></div>
                    <div class="message-time"><?php echo $message_date; ?></div>
                </div>
                <div class="message-content" style="padding: 10px 0;">
                    <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($ticket['admin_reply'])): ?>
            <div class="message admin">
                <div class="message-header">
                    <div class="message-sender">Destek Ekibi</div>
                    <div class="message-time"><?php echo $updated_date; ?></div>
                </div>
                <div class="message-content" style="padding: 10px 0;">
                    <?php echo nl2br(htmlspecialchars($ticket['admin_reply'])); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($ticket['status'] !== 'closed' && $ticket['status'] !== 'resolved'): ?>
    <form method="POST" action="support.php" class="reply-form">
        <input type="hidden" name="ticket_id" value="<?php echo htmlspecialchars($ticket['ticket_id']); ?>">
        
        <div class="form-group">
            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-light);">Cevabınız:</label>
            <textarea name="reply_message" class="form-control" rows="4" placeholder="Cevabınızı buraya yazın..." required style="width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: var(--radius); color: var(--text-light);"></textarea>
        </div>
        
        <div class="ticket-actions">
            <button type="submit" name="reply_ticket" class="action-btn primary">
                <i class="fas fa-reply"></i> Yanıt Gönder
            </button>
            
            <button type="submit" name="close_ticket" class="action-btn secondary" onclick="return confirm('Bu talebi kapatmak istediğinize emin misiniz?')">
                <i class="fas fa-lock"></i> Talebi Kapat
            </button>
        </div>
    </form>
    <?php else: ?>
    <div style="text-align: center; padding: 30px; color: var(--text-gray); background: rgba(255,255,255,0.03); border-radius: var(--radius); margin-top: 20px;">
        <i class="fas fa-lock" style="font-size: 2rem; margin-bottom: 15px; color: #F59E0B;"></i>
        <p style="font-size: 1.1rem; margin-bottom: 10px;">Bu destek talebi kapatılmıştır.</p>
        <p style="font-size: 0.9rem;">Yeni bir talep oluşturmak için ana sayfayı kullanın.</p>
    </div>
    <?php endif; ?>
    
    <?php
} catch (Exception $e) {
    echo '<div style="text-align: center; padding: 40px;"><i class="fas fa-exclamation-circle"></i> Ticket detayları yüklenirken hata oluştu: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>