<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

$users_with_messages = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT u.id, u.username, u.email, 
                         (SELECT COUNT(*) FROM live_support_messages l WHERE l.user_id = u.id AND l.is_admin = 0 AND l.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as active_messages,
                         MAX(lm.created_at) as last_message_time
                         FROM users u 
                         LEFT JOIN live_support_messages lm ON u.id = lm.user_id 
                         WHERE u.user_role != 'admin'
                         GROUP BY u.id 
                         ORDER BY last_message_time DESC");
    $users_with_messages = $stmt->fetchAll();
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canlı Destek Yönetimi - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --secondary: #10B981;
            --accent: #F59E0B;
            --danger: #EF4444;
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
            height: 100vh;
            overflow: hidden;
        }
        
        .container {
            display: flex;
            height: 100vh;
            max-width: 100%;
            padding: 0;
        }
        
        .users-list {
            width: 350px;
            background: var(--bg-dark);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
        }
        
        .users-header {
            padding: 20px;
            background: var(--bg-card);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .users-header h3 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .users-container {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-darker);
        }
        
        .chat-header {
            padding: 20px;
            background: var(--bg-card);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: var(--gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        .chat-messages {
            flex: 1;
            padding: 25px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
            background: rgba(0, 0, 0, 0.2);
        }
        
        .chat-input-container {
            padding: 20px;
            background: var(--bg-card);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .user-item {
            padding: 15px;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin-bottom: 10px;
            background: var(--bg-card);
        }
        
        .user-item:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(5px);
        }
        
        .user-item.active {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(16, 185, 129, 0.2));
            border-color: var(--primary);
        }
        
        .user-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .user-email {
            color: var(--text-gray);
            font-size: 0.85rem;
            margin-bottom: 5px;
        }
        
        .user-status {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
        }
        
        .status-online {
            color: var(--secondary);
        }
        
        .status-offline {
            color: var(--text-gray);
        }
        
        .unread-badge {
            background: var(--danger);
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
        }
        
        .message {
            display: flex;
            flex-direction: column;
            max-width: 75%;
        }
        
        .message.user {
            align-self: flex-start;
        }
        
        .message.admin {
            align-self: flex-end;
        }
        
        .message-content {
            padding: 12px 18px;
            border-radius: 18px;
            word-wrap: break-word;
            line-height: 1.4;
        }
        
        .message.user .message-content {
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px 18px 18px 5px;
        }
        
        .message.admin .message-content {
            background: var(--gradient);
            color: white;
            border-radius: 18px 18px 5px 18px;
        }
        
        .message-time {
            font-size: 0.7rem;
            color: var(--text-gray);
            margin-top: 5px;
            margin-left: 10px;
            margin-right: 10px;
        }
        
        .chat-input-wrapper {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .chat-input {
            flex: 1;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            color: var(--text-light);
            font-family: inherit;
            resize: none;
            height: 60px;
            font-size: 0.95rem;
        }
        
        .chat-input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .send-btn {
            padding: 15px 25px;
            background: var(--gradient);
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            height: 60px;
        }
        
        .send-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.3);
        }
        
        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .no-chat-selected {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            color: var(--text-gray);
        }
        
        .no-chat-selected i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-chat-selected h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        .no-users {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-gray);
        }
        
        .no-users i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            padding: 8px 15px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }
        
        .action-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .users-list {
                width: 100%;
                height: 300px;
            }
            
            .users-container {
                max-height: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="users-list">
            <div class="users-header">
                <h3>
                    <i class="fas fa-headset"></i> Canlı Destek Kullanıcıları
                    <span style="font-size: 0.8rem; color: var(--primary); margin-left: auto;"><?php echo count($users_with_messages); ?> kullanıcı</span>
                </h3>
            </div>
            
            <div class="users-container" id="usersContainer">
                <?php if (!empty($users_with_messages)): ?>
                    <?php foreach ($users_with_messages as $user): ?>
                        <div class="user-item" onclick="selectUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['email']); ?>')" id="user_<?php echo $user['id']; ?>">
                            <div class="user-item-header">
                                <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                                <?php if ($user['active_messages'] > 0): ?>
                                    <div class="unread-badge" id="badge_<?php echo $user['id']; ?>"><?php echo $user['active_messages']; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                            <div class="user-status <?php echo $user['active_messages'] > 0 ? 'status-online' : 'status-offline'; ?>">
                                <i class="fas fa-circle"></i>
                                <?php echo $user['active_messages'] > 0 ? 'Çevrimiçi' : 'Çevrimdışı'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-users">
                        <i class="fas fa-users-slash"></i>
                        <h3>Henüz aktif kullanıcı yok</h3>
                        <p>Canlı destek kullanmak isteyen kullanıcılar burada görünecektir.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="chat-area">
            <div class="chat-header" id="chatHeader">
                <div class="no-chat-selected" id="noChatSelected">
                    <div>
                        <i class="fas fa-comments"></i>
                        <h3>Canlı Destek Yönetimi</h3>
                        <p>Görüşme başlatmak için soldan bir kullanıcı seçin</p>
                    </div>
                </div>
                
                <div id="chatHeaderContent" style="display: none; width: 100%;">
                    <div class="chat-user-info">
                        <div class="user-avatar" id="currentUserAvatar">U</div>
                        <div>
                            <div style="font-weight: 600; font-size: 1.2rem;" id="currentUserName">Kullanıcı</div>
                            <div style="font-size: 0.9rem; color: var(--text-gray);" id="currentUserEmail">email@example.com</div>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <button class="action-btn" onclick="createTicketFromChat()">
                            <i class="fas fa-ticket-alt"></i> Ticket Aç
                        </button>
                        <button class="action-btn" onclick="endChat()">
                            <i class="fas fa-times"></i> Görüşmeyi Bitir
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="chat-messages" id="chatMessages" style="display: none;">
                
            </div>
            
            <div class="chat-input-container" id="chatInputContainer" style="display: none;">
                <div class="chat-input-wrapper">
                    <textarea class="chat-input" id="messageInput" placeholder="Mesajınızı yazın..." rows="3"></textarea>
                    <button class="send-btn" onclick="sendMessage()" id="sendBtn">
                        <i class="fas fa-paper-plane"></i> Gönder
                    </button>
                </div>
                <div style="margin-top: 10px; display: flex; gap: 10px;">
                    <button class="action-btn" onclick="sendQuickMessage('Merhaba! Size nasıl yardımcı olabilirim?')">
                        <i class="fas fa-hand"></i> Merhaba
                    </button>
                    <button class="action-btn" onclick="sendQuickMessage('Sorununuzu anladım, çözüm için çalışıyorum.')">
                        <i class="fas fa-cogs"></i> Çözülüyor
                    </button>
                    <button class="action-btn" onclick="sendQuickMessage('Bu konuda size daha detaylı bilgi vereceğim.')">
                        <i class="fas fa-info-circle"></i> Bilgi
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentUserId = null;
        let currentUserName = '';
        let currentUserEmail = '';
        let messageInterval = null;
        
        function selectUser(userId, userName, userEmail) {
            currentUserId = userId;
            currentUserName = userName;
            currentUserEmail = userEmail;
            
            document.getElementById('noChatSelected').style.display = 'none';
            document.getElementById('chatHeaderContent').style.display = 'flex';
            document.getElementById('chatMessages').style.display = 'flex';
            document.getElementById('chatInputContainer').style.display = 'block';
            
            document.getElementById('currentUserName').textContent = userName;
            document.getElementById('currentUserEmail').textContent = userEmail;
            document.getElementById('currentUserAvatar').textContent = userName.charAt(0).toUpperCase();
            
            document.querySelectorAll('.user-item').forEach(item => {
                item.classList.remove('active');
            });
            document.getElementById('user_' + userId).classList.add('active');
            
            document.getElementById('badge_' + userId)?.remove();
            
            loadMessages();
            
            if (messageInterval) {
                clearInterval(messageInterval);
            }
            
            messageInterval = setInterval(loadMessages, 2000);
        }
        
        function loadMessages() {
            if (!currentUserId) return;
            
            fetch('admin_get_messages.php?user_id=' + currentUserId)
                .then(response => response.json())
                .then(data => {
                    const messagesDiv = document.getElementById('chatMessages');
                    messagesDiv.innerHTML = '';
                    
                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(message => {
                            const messageDiv = document.createElement('div');
                            messageDiv.className = `message ${message.is_admin ? 'admin' : 'user'}`;
                            
                            const time = new Date(message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                            
                            messageDiv.innerHTML = `
                                <div class="message-content">${message.message}</div>
                                <div class="message-time">${time}</div>
                            `;
                            
                            messagesDiv.appendChild(messageDiv);
                        });
                        
                        messagesDiv.scrollTop = messagesDiv.scrollHeight;
                    } else {
                        messagesDiv.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: var(--text-gray); width: 100%;">
                                <i class="fas fa-comments" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.5;"></i>
                                <p>Henüz mesaj yok. İlk mesajı siz gönderin.</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Mesajlar yüklenemedi:', error);
                });
                
            updateUserBadges();
        }
        
        function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (message && currentUserId) {
                const sendBtn = document.getElementById('sendBtn');
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
                
                fetch('admin_send_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `user_id=${currentUserId}&message=${encodeURIComponent(message)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        input.value = '';
                        loadMessages();
                    }
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
                })
                .catch(error => {
                    console.error('Mesaj gönderilemedi:', error);
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
                });
            }
        }
        
        function sendQuickMessage(message) {
            if (!currentUserId) return;
            
            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
            
            fetch('admin_send_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user_id=${currentUserId}&message=${encodeURIComponent(message)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadMessages();
                }
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
            })
            .catch(error => {
                console.error('Mesaj gönderilemedi:', error);
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
            });
        }
        
        function updateUserBadges() {
            fetch('admin_get_active_chats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.active_chats) {
                        Object.keys(data.active_chats).forEach(userId => {
                            const badge = document.getElementById('badge_' + userId);
                            if (badge) {
                                badge.textContent = data.active_chats[userId];
                            } else if (data.active_chats[userId] > 0) {
                                const userItem = document.getElementById('user_' + userId);
                                if (userItem) {
                                    const badgeDiv = document.createElement('div');
                                    badgeDiv.className = 'unread-badge';
                                    badgeDiv.id = 'badge_' + userId;
                                    badgeDiv.textContent = data.active_chats[userId];
                                    userItem.querySelector('.user-item-header').appendChild(badgeDiv);
                                }
                            }
                        });
                    }
                });
        }
        
        function createTicketFromChat() {
            if (!currentUserId) return;
            
            const subject = prompt('Ticket konusunu girin:');
            if (subject) {
                window.open(`admin_ticket_create.php?user_id=${currentUserId}&subject=${encodeURIComponent(subject)}`, '_blank');
            }
        }
        
        function endChat() {
            if (currentUserId) {
                if (confirm('Bu görüşmeyi sonlandırmak istediğinize emin misiniz?')) {
                    document.getElementById('noChatSelected').style.display = 'flex';
                    document.getElementById('chatHeaderContent').style.display = 'none';
                    document.getElementById('chatMessages').style.display = 'none';
                    document.getElementById('chatInputContainer').style.display = 'none';
                    
                    document.querySelectorAll('.user-item').forEach(item => {
                        item.classList.remove('active');
                    });
                    
                    currentUserId = null;
                    
                    if (messageInterval) {
                        clearInterval(messageInterval);
                        messageInterval = null;
                    }
                }
            }
        }
        
        document.getElementById('messageInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        setInterval(updateUserBadges, 5000);
        
        document.addEventListener('DOMContentLoaded', function() {
            if (window.location.search.includes('user_id=')) {
                const urlParams = new URLSearchParams(window.location.search);
                const userId = urlParams.get('user_id');
                const userName = urlParams.get('user_name');
                const userEmail = urlParams.get('user_email');
                
                if (userId && userName) {
                    setTimeout(() => {
                        selectUser(userId, decodeURIComponent(userName), decodeURIComponent(userEmail || ''));
                    }, 500);
                }
            }
        });
    </script>
</body>
</html>