# TÜM SQL İFADELERİ - HEXDARQ SMM PANEL

Bu dosya, projedeki tüm PHP dosyalarından ve SQL dosyalarından çıkarılan SQL ifadelerini içermektedir.

---

## 📁 1. hexdarq_smm.sql (Ana Veritabanı Şeması)

```sql
CREATE DATABASE IF NOT EXISTS hexdarq_smm;
USE hexdarq_smm;

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    phone VARCHAR(20),
    country VARCHAR(50),
    balance DECIMAL(10,2) DEFAULT 0.00,
    api_key VARCHAR(255) UNIQUE,
    status ENUM('active', 'pending', 'suspended') DEFAULT 'pending',
    email_verified BOOLEAN DEFAULT FALSE,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    ip_address VARCHAR(45),
    user_role ENUM('user', 'reseller', 'admin') DEFAULT 'user'
);

CREATE TABLE login_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE email_verifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    token VARCHAR(255) UNIQUE,
    expires_at TIMESTAMP,
    verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## 📁 2. config.php

```sql
-- PDO Bağlantı
new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS)

-- Exchange Rate Ayarları
SELECT value FROM settings WHERE setting_key = 'exchange_rate'
INSERT INTO settings (setting_key, value, updated_at) VALUES ('exchange_rate', ?, NOW()) ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()

-- Kullanıcı Rolü
SELECT user_role FROM users WHERE id = ?
```

---

## 📁 3. register.php

```sql
-- Referral Kodu Kontrolü
SELECT id, username FROM users WHERE referral_code = ?

-- IP Adresi Kontrolü (1 saat içinde aynı IP'den kayıt)
SELECT id FROM users WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)

-- Kullanıcı Adı/Email Kontrolü
SELECT id FROM users WHERE username = ? OR email = ?

-- Referrer'a Bonus Ekleme
UPDATE users SET balance = balance + 5 WHERE id = ?

-- Referral Kaydı
INSERT INTO referrals (referrer_id, referred_email, bonus_amount, status) VALUES (?, ?, 5, 'completed')

-- Yeni Kullanıcı Kaydı
INSERT INTO users (username, email, password, full_name, phone, country, api_key, referral_code, referred_by, balance, status, email_verified, ip_address, kvkk_consent, terms_accepted, privacy_accepted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?, 1, 1)
```

---

## 📁 4. login.php ve log.php

```sql
-- Kullanıcı Giriş Kontrolü
SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'

-- Son Giriş Zamanı ve IP Güncelleme
UPDATE users SET last_login = NOW(), ip_address = ? WHERE id = ?

-- Giriş Logu (Başarılı)
INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, ?)

-- Remember Me Token Güncelleme
UPDATE users SET remember_token = ? WHERE id = ?

-- Remember Me Cookie Kontrolü
SELECT * FROM users WHERE remember_token = ? AND status = 'active'

-- Başarısız Giriş - Kullanıcı Kontrolü
SELECT id FROM users WHERE username = ? OR email = ?

-- Başarısız Giriş Logu
INSERT INTO login_logs (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, ?)
```

---

## 📁 5. logout.php

```sql
-- Remember Token Temizleme
UPDATE users SET remember_token = NULL WHERE id = ?
```

---

## 📁 6. dashboard.php

```sql
-- Kullanıcı Bilgileri
SELECT * FROM users WHERE id = ?

-- Support Messages Tablosu Kontrolü
SHOW TABLES LIKE 'support_messages'

-- Kullanıcının Ticket ID'leri
SELECT id FROM support_tickets WHERE user_id = ?

-- Okunmamış Mesaj Sayısı
SELECT COUNT(*) as unread_count FROM support_messages WHERE ticket_id IN (?,...) AND sender_role != 'user' AND is_read = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)

-- Son Okunmamış Mesajlar
SELECT sm.*, st.subject FROM support_messages sm JOIN support_tickets st ON sm.ticket_id = st.id WHERE sm.ticket_id IN (?,...) AND sm.sender_role != 'user' AND sm.is_read = 0 AND sm.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY sm.created_at DESC LIMIT 5

-- Orders Tablosu Kontrolü
SHOW TABLES LIKE 'orders'

-- Toplam Sipariş Sayısı
SELECT COUNT(*) as total FROM orders WHERE user_id = ?

-- Aktif Sipariş Sayısı
SELECT COUNT(*) as active FROM orders WHERE user_id = ? AND status IN ('processing', 'inprogress', 'pending')

-- Tamamlanan Sipariş Sayısı
SELECT COUNT(*) as completed FROM orders WHERE user_id = ? AND status = 'completed'

-- Toplam Harcama
SELECT SUM(price) as total_spent FROM orders WHERE user_id = ? AND status = 'completed'

-- Son 5 Sipariş
SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5

-- Services Tablosu Kontrolü
SHOW TABLES LIKE 'services'

-- Popüler Hizmetler
SELECT * FROM services WHERE status = 'active' ORDER BY category, price_per_1000 LIMIT 12

-- Referral Kodu Güncelleme
UPDATE users SET referral_code = ? WHERE id = ?

-- Toplam Referral Sayısı
SELECT COUNT(DISTINCT u.id) as total_refs FROM users u WHERE u.referred_by = ? AND u.id != ? AND NOT EXISTS (SELECT 1 FROM users u2 WHERE u2.ip_address = u.ip_address AND u2.id < u.id AND u2.referred_by = ?)

-- Announcements Tablosu Kontrolü
SHOW TABLES LIKE 'announcements'

-- Duyurular
SELECT * FROM announcements WHERE status = 'active' ORDER BY created_at DESC LIMIT 5

-- Support Tickets Tablosu Kontrolü
SHOW TABLES LIKE 'support_tickets'

-- Açık Destek Talepleri
SELECT * FROM support_tickets WHERE user_id = ? AND status != 'closed' ORDER BY created_at DESC LIMIT 3

-- Remember Token Temizleme
UPDATE users SET remember_token = NULL WHERE id = ?
```

---

## 📁 7. orders.php

```sql
-- Kullanıcı Bilgileri
SELECT * FROM users WHERE id = ?

-- Orders Tablosu Yapısı Kontrolü
DESCRIBE orders

-- Orders Tablosu Oluşturma (Eğer Yoksa)
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_id VARCHAR(50) UNIQUE NOT NULL,
    service_id INT NOT NULL,
    service_name VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    link TEXT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'partial', 'cancelled') DEFAULT 'pending',
    start_count INT DEFAULT 0,
    remains INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
)

-- Sipariş Listesi (Filtreleme ve Arama ile)
SELECT id, order_id, service_name, category, link, quantity, price, total, status, start_count, remains, created_at, updated_at 
FROM orders 
WHERE user_id = :user_id 
[AND status = :status] 
[AND (order_id LIKE :search OR service_name LIKE :search OR link LIKE :search)] 
ORDER BY created_at DESC 
LIMIT :limit OFFSET :offset

-- Toplam Sipariş Sayısı (Filtreleme ve Arama ile)
SELECT COUNT(*) as total 
FROM orders 
WHERE user_id = :user_id 
[AND status = :status] 
[AND (order_id LIKE :search OR service_name LIKE :search OR link LIKE :search)]

-- Sipariş İstatistikleri
SELECT 
    COUNT(*) as total, 
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed, 
    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing, 
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, 
    SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial, 
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled, 
    SUM(total) as total_spent 
FROM orders 
WHERE user_id = ?
```

---

## 📁 8. balance.php

```sql
-- Kullanıcı Bilgileri
SELECT * FROM users WHERE id = ?

-- Bakiye Güncelleme
UPDATE users SET balance = ? WHERE id = ?

-- Ödeme Kaydı (Tamamlanan)
INSERT INTO payments (user_id, payment_id, amount, payment_method, status, created_at) VALUES (?, ?, ?, ?, 'completed', NOW())

-- Ödeme Kaydı (Beklemede)
INSERT INTO payments (user_id, payment_id, amount, payment_method, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())

-- Ödeme Geçmişi
SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 10

-- Payments Tablosu Oluşturma (Eğer Yoksa)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    payment_id VARCHAR(50) UNIQUE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_payment_id (payment_id)
)

-- Bu Ay Toplam Ödeme
SELECT SUM(amount) as total FROM payments WHERE user_id = ? AND status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())
```

---

## 📁 9. support.php

```sql
-- Kullanıcı Bilgileri
SELECT * FROM users WHERE id = ?

-- Tickets Tablosu Oluşturma (Eğer Yoksa)
CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id VARCHAR(50) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    category VARCHAR(100),
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('open', 'in_progress', 'answered', 'closed', 'resolved') DEFAULT 'open',
    admin_reply TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
)

-- Ticket Messages Tablosu Oluşturma (Eğer Yoksa)
CREATE TABLE IF NOT EXISTS ticket_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id VARCHAR(50) NOT NULL,
    user_id INT,
    admin_id INT,
    message TEXT NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_id (ticket_id)
)

-- Live Support Messages Tablosu Oluşturma (Eğer Yoksa)
CREATE TABLE IF NOT EXISTS live_support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT NULL,
    message TEXT NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
)

-- Username Kolonu Kontrolü
SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'username'

-- Username Kolonu Ekleme (Eğer Yoksa)
ALTER TABLE tickets ADD COLUMN username VARCHAR(100) NOT NULL AFTER user_id

-- Yeni Ticket Oluşturma
INSERT INTO tickets (ticket_id, user_id, username, subject, category, priority, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'open')

-- Ticket Mesajı Ekleme
INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, FALSE)

-- Ticket Durumu Güncelleme
UPDATE tickets SET status = 'open', updated_at = NOW() WHERE ticket_id = ?

-- Ticket Kapatma
UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE ticket_id = ? AND user_id = ?

-- Live Support Mesajı (Kullanıcı)
INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 0, NOW())

-- Live Support Mesajı (Admin)
INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 1, NOW())

-- Kullanıcının Tüm Ticket'ları
SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC

-- Live Support Mesajları
SELECT * FROM live_support_messages WHERE user_id = ? ORDER BY created_at ASC LIMIT 50
```

---

## 📁 10. create_order.php

```sql
-- Kullanıcı Bilgileri
SELECT * FROM users WHERE id = ?

-- Bakiye Güncelleme (Sipariş için Bakiye Düşürme)
UPDATE users SET balance = ? WHERE id = ?

-- Yeni Sipariş Oluşturma
INSERT INTO orders (user_id, api_order_id, api_service_id, service_name, category, link, quantity, price, total_price, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
```

---

## 📁 11. sync_services.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Tüm Servisleri Silme
DELETE FROM services

-- Servis Ekleme
INSERT INTO services (service_id, name, category, rate_per_1000, price_per_1000, min, max, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
```

---

## 📁 12. api_functions.php

```sql
-- Exchange Rate Alma
SELECT value FROM settings WHERE setting_key = 'exchange_rate'

-- Exchange Rate Güncelleme/Ekleme
INSERT INTO settings (setting_key, value, updated_at) VALUES ('exchange_rate', ?, NOW()) ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()

-- Servisleri Silme (sync_services_from_api fonksiyonunda)
DELETE FROM services

-- Servis Ekleme (sync_services_from_api fonksiyonunda)
INSERT INTO services (service_id, name, category, rate_per_1000, price_per_1000, min, max, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
```

---

## 📁 13. admin_dashboard.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Toplam Kullanıcı Sayısı
SELECT COUNT(*) FROM users WHERE user_role != 'admin'

-- Toplam Sipariş Sayısı
SELECT COUNT(*) FROM orders

-- Bugünkü Sipariş Sayısı
SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()

-- Toplam Ödeme Sayısı
SELECT COUNT(*) FROM payments

-- Bugünkü Tamamlanan Ödeme Sayısı
SELECT COUNT(*) FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'completed'

-- Toplam Bakiye
SELECT SUM(balance) FROM users WHERE user_role != 'admin'

-- Aktif Sipariş Sayısı
SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'processing', 'inprogress')

-- Bekleyen Ödeme Sayısı
SELECT COUNT(*) FROM payments WHERE status = 'pending'

-- Açık Ticket Sayısı
SELECT COUNT(*) FROM tickets WHERE status = 'open'

-- Aktif Canlı Destek Kullanıcı Sayısı
SELECT COUNT(DISTINCT user_id) FROM live_support_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND is_admin = 0

-- Son 10 Sipariş
SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 10

-- Son 10 Ödeme
SELECT p.*, u.username FROM payments p JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 10

-- Son 10 Kullanıcı
SELECT * FROM users WHERE user_role != 'admin' ORDER BY created_at DESC LIMIT 10

-- Son 5 Ticket
SELECT t.*, u.username FROM tickets t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 5
```

---

## 📁 14. admin_orders.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Sipariş Listesi (Filtreleme ve Arama ile)
SELECT o.*, u.username, u.email 
FROM orders o 
LEFT JOIN users u ON o.user_id = u.id 
WHERE [filters] 
ORDER BY o.created_at DESC 
LIMIT :limit OFFSET :offset

-- Toplam Sipariş Sayısı (Filtreleme ile)
SELECT COUNT(*) as total 
FROM orders o 
LEFT JOIN users u ON o.user_id = u.id 
WHERE [filters]

-- Sipariş İstatistikleri
SELECT 
    COUNT(*) as total_orders, 
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders, 
    SUM(CASE WHEN status IN ('processing', 'inprogress', 'pending') THEN 1 ELSE 0 END) as active_orders, 
    SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_orders, 
    SUM(COALESCE(price, 0)) as total_revenue, 
    SUM(COALESCE(profit_try, 0)) as total_profit 
FROM orders

-- Sipariş Durumu Güncelleme
UPDATE orders SET status = ?, admin_note = ?, updated_at = NOW() WHERE order_id = ?

-- Sipariş Detayları
SELECT * FROM orders WHERE order_id = ?

-- Sipariş İade
UPDATE orders SET status = 'refunded', updated_at = NOW() WHERE order_id = ?

-- Kullanıcı Bakiyesine İade Ekleme
UPDATE users SET balance = balance + ? WHERE id = ?

-- Sipariş Silme
DELETE FROM orders WHERE order_id = ?
```

---

## 📁 15. admin_tickets.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Toplam Ticket Sayısı (Filtreleme ile)
SELECT COUNT(*) as total 
FROM tickets t 
LEFT JOIN users u ON t.user_id = u.id 
WHERE [filters]

-- Ticket Listesi (Filtreleme ve Arama ile)
SELECT 
    t.*, 
    u.username, 
    u.email, 
    (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) as reply_count, 
    (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id AND tr.is_admin = 1) as admin_reply_count 
FROM tickets t 
LEFT JOIN users u ON t.user_id = u.id 
WHERE [filters] 
ORDER BY 
    CASE WHEN t.status = 'open' THEN 1 
         WHEN t.status = 'answered' THEN 2 
         WHEN t.status = 'customer_reply' THEN 3 
         ELSE 4 END, 
    t.created_at DESC 
LIMIT :limit OFFSET :offset

-- Ticket İstatistikleri
SELECT 
    COUNT(*) as total, 
    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count, 
    SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END) as answered_count, 
    SUM(CASE WHEN status = 'customer_reply' THEN 1 ELSE 0 END) as customer_reply_count, 
    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count, 
    SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END) as low_count, 
    SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END) as medium_count, 
    SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_count, 
    SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count 
FROM tickets

-- Ticket Kapatma
UPDATE tickets SET status = 'closed', closed_at = NOW() WHERE id = ?

-- Ticket Silme (Önce Reply'leri Sil)
DELETE FROM ticket_replies WHERE ticket_id = ?
DELETE FROM tickets WHERE id = ?
```

---

## 📁 16. admin_services.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Toplam Servis Sayısı (Filtreleme ile)
SELECT COUNT(*) as total FROM services WHERE [filters]

-- Servis Listesi (Filtreleme ve Arama ile)
SELECT * FROM services WHERE [filters] ORDER BY category, name ASC LIMIT :limit OFFSET :offset

-- Kategoriler
SELECT DISTINCT category FROM services WHERE category IS NOT NULL ORDER BY category

-- Yeni Servis Ekleme
INSERT INTO services (name, description, category, price, cost, min_quantity, max_quantity, api_service_id, api_provider, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())

-- Servis Güncelleme
UPDATE services SET name = ?, description = ?, category = ?, price = ?, cost = ?, min_quantity = ?, max_quantity = ?, api_service_id = ?, api_provider = ?, status = ?, updated_at = NOW() WHERE id = ?

-- Servis Silme
DELETE FROM services WHERE id = ?
```

---

## 📁 17. admin_users.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Toplam Kullanıcı Sayısı (Filtreleme ile)
SELECT COUNT(*) as total FROM users WHERE [filters]

-- Kullanıcı Listesi (Filtreleme ve Arama ile)
SELECT * FROM users WHERE [filters] ORDER BY created_at DESC LIMIT :limit OFFSET :offset

-- Kullanıcı Güncelleme
UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, phone = ?, balance = ?, status = ?, role = ?, updated_at = NOW() WHERE id = ?

-- Mevcut Bakiye Kontrolü
SELECT balance FROM users WHERE id = ?

-- Bakiye Güncelleme
UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?

-- Bakiye İşlem Kaydı
INSERT INTO balance_transactions (user_id, amount, type, note, created_at) VALUES (?, ?, 'admin_add', ?, NOW())

-- Bildirim Ekleme
INSERT INTO notifications (user_id, message, type, created_at) VALUES (?, ?, 'balance', NOW())

-- Kullanıcı Silme
DELETE FROM users WHERE id = ?
```

---

## 📁 18. admin_payments.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Toplam Ödeme Sayısı (Filtreleme ile)
SELECT COUNT(*) as total 
FROM payments p 
LEFT JOIN users u ON p.user_id = u.id 
WHERE [filters]

-- Ödeme Listesi (Filtreleme ve Arama ile)
SELECT p.*, u.username, u.email 
FROM payments p 
LEFT JOIN users u ON p.user_id = u.id 
WHERE [filters] 
ORDER BY p.created_at DESC 
LIMIT :limit OFFSET :offset

-- Ödeme Detayları
SELECT p.*, u.username, u.balance as user_balance 
FROM payments p 
JOIN users u ON p.user_id = u.id 
WHERE p.id = ?

-- Ödeme Onaylama
UPDATE payments SET status = 'completed', approved_at = NOW() WHERE id = ?

-- Kullanıcı Bakiyesine Ekleme
UPDATE users SET balance = ? WHERE id = ?

-- Bildirim Ekleme
INSERT INTO notifications (user_id, message, type, created_at) VALUES (?, ?, 'payment', NOW())

-- Ödeme Reddetme
UPDATE payments SET status = 'rejected', reject_reason = ?, approved_at = NOW() WHERE id = ?
```

---

## 📁 19. admin_ticket_view.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Ticket Detayları
SELECT t.*, u.username, u.email 
FROM tickets t 
JOIN users u ON t.user_id = u.id 
WHERE t.id = ?

-- Ticket Mesajları
SELECT tr.*, u.username 
FROM ticket_replies tr 
LEFT JOIN users u ON tr.user_id = u.id 
WHERE tr.ticket_id = ? 
ORDER BY tr.created_at ASC

-- Admin Yanıtı Ekleme
INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin, created_at) VALUES (?, ?, ?, 1, NOW())

-- Ticket Durumu Güncelleme
UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?

-- Kullanıcı Bildirimi (Yanıt)
INSERT INTO user_notifications (user_id, type, title, message, related_id, created_at) VALUES (?, 'ticket_reply', ?, ?, ?, NOW())

-- Ticket Kapatma
UPDATE tickets SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?

-- Kullanıcı Bildirimi (Kapatıldı)
INSERT INTO user_notifications (user_id, type, title, message, related_id, created_at) VALUES (?, 'ticket_closed', ?, ?, ?, NOW())

-- Kullanıcı Bildirimi (Silindi)
INSERT INTO user_notifications (user_id, type, title, message, related_id, created_at) VALUES (?, 'ticket_deleted', ?, ?, ?, NOW())

-- Ticket Silme (Önce Reply'leri Sil)
DELETE FROM ticket_replies WHERE ticket_id = ?
DELETE FROM tickets WHERE id = ?

-- Ticket Önceliği Güncelleme
UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?

-- Kullanıcı Bildirimi (Durum Değişti)
INSERT INTO user_notifications (user_id, type, title, message, related_id, created_at) VALUES (?, 'ticket_status', ?, ?, ?, NOW())
```

---

## 📁 20. admin_ticket_create.php

```sql
-- Kullanıcı Bilgileri (Admin Kontrolü)
SELECT * FROM users WHERE id = ?

-- Yeni Ticket Oluşturma (Admin Tarafından)
INSERT INTO tickets (user_id, subject, message, priority, department, status, created_at) VALUES (?, ?, ?, ?, ?, 'answered', NOW())

-- İlk Admin Yanıtı
INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin, created_at) VALUES (?, ?, ?, 1, NOW())

-- Kullanıcı Bilgileri (Bildirim İçin)
SELECT username, email FROM users WHERE id = ?
```

---

## 📁 21. admin_get_active_chats.php

```sql
-- Aktif Canlı Destek Kullanıcıları
SELECT user_id, COUNT(*) as message_count 
FROM live_support_messages 
WHERE is_admin = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
GROUP BY user_id
```

---

## 📁 22. admin_get_messages.php

```sql
-- Kullanıcının Canlı Destek Mesajları
SELECT * FROM live_support_messages WHERE user_id = ? ORDER BY created_at ASC LIMIT 100
```

---

## 📁 23. admin_send_message.php

```sql
-- Admin Canlı Destek Mesajı Gönderme
INSERT INTO live_support_messages (user_id, admin_id, message, is_admin, created_at) VALUES (?, ?, ?, 1, NOW())
```

---

## 📁 24. admin_live_support.php

```sql
-- Aktif Canlı Destek Kullanıcıları ve Mesaj Bilgileri
SELECT DISTINCT 
    u.id, 
    u.username, 
    u.email, 
    (SELECT COUNT(*) FROM live_support_messages l WHERE l.user_id = u.id AND l.is_admin = 0 AND l.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as active_messages, 
    MAX(lm.created_at) as last_message_time 
FROM users u 
LEFT JOIN live_support_messages lm ON u.id = lm.user_id 
WHERE u.user_role != 'admin' 
GROUP BY u.id 
ORDER BY last_message_time DESC
```

---

## 📁 25. get_ticket_details.php

```sql
-- Ticket Detayları
SELECT * FROM tickets WHERE ticket_id = ? AND user_id = ?

-- Ticket Mesajları
SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC
```

---

## 📁 26. get_payment_details.php

```sql
-- Ödeme Detayları
SELECT p.*, u.username, u.email, u.balance as user_balance 
FROM payments p 
JOIN users u ON p.user_id = u.id 
WHERE p.id = ?
```

---

## 📁 27. notifications.php

```sql
-- Toplam Bildirim Sayısı
SELECT COUNT(*) as total FROM user_notifications WHERE user_id = ?

-- Bildirim Listesi (Sayfalama ile)
SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?

-- Tüm Bildirimleri Okundu İşaretleme
UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?

-- Bildirim Silme
DELETE FROM user_notifications WHERE user_id = ?
```

---

## 📁 28. mark_notifications_read.php

```sql
-- Tüm Bildirimleri Okundu İşaretleme
UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?

-- Tekil Bildirimi Okundu İşaretleme
UPDATE user_notifications SET is_read = TRUE WHERE id = ? AND user_id = ?
```

---

## 📁 29. check_ticket_updates.php

```sql
-- Ticket Güncelleme Zamanı
SELECT updated_at FROM tickets WHERE id = ?

-- Yeni Mesaj Sayısı
SELECT COUNT(*) as new_messages FROM ticket_replies WHERE ticket_id = ? AND created_at > FROM_UNIXTIME(?)
```

---

## 📁 30. user_check_live_messages.php

```sql
-- Okunmamış Canlı Destek Mesaj Sayısı
SELECT COUNT(*) as unread 
FROM live_support_messages 
WHERE user_id = ? 
AND is_admin = 1 
AND created_at > (
    SELECT MAX(created_at) 
    FROM live_support_messages 
    WHERE user_id = ? 
    AND is_admin = 0
)
```

---

## 📁 31. user_send_live_message.php

```sql
-- Kullanıcı Canlı Destek Mesajı Gönderme
INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 0, NOW())

-- Otomatik AI Yanıtı (Eğer Varsa)
INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 1, NOW())
```

---

## 📝 NOTLAR

1. **Parametreli Sorgular**: Tüm SQL sorguları PDO prepared statements kullanılarak yazılmıştır (`?` veya `:param` placeholder'ları ile).

2. **Dinamik Filtreleme**: Bazı sorgularda `[filters]` ifadesi, dinamik olarak eklenen WHERE koşullarını temsil eder.

3. **Tablo Oluşturma**: Bazı dosyalarda (`orders.php`, `balance.php`, `support.php`) tabloların varlığı kontrol edilir ve yoksa oluşturulur.

4. **İndeksler**: Performans için önemli kolonlarda indeksler tanımlanmıştır.

5. **Foreign Keys**: Bazı tablolarda foreign key kısıtlamaları kullanılmıştır (`login_logs`, `email_verifications`).

6. **ENUM Değerleri**: Status, priority, user_role gibi alanlar ENUM tipinde tanımlanmıştır.

---

**Toplam Dosya Sayısı**: 31 PHP dosyası + 1 SQL dosyası  
**Toplam SQL İfadesi**: 150+ farklı SQL sorgusu

---

*Son Güncelleme: 24 Ocak 2026*
