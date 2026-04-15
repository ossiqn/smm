🔧 SMM Script Kurulum (ossiqn/smm)
📦 1. Dosyayı indir

GitHub’dan indir:

https://github.com/ossiqn/smm

ZIP indir ya da:

git clone https://github.com/ossiqn/smm.git
🌐 2. Hosting / Sunucu Hazırla

Şunlar lazım:

PHP 7.4 – 8.x
MySQL
Apache / Nginx
cPanel / VPS fark etmez
📁 3. Dosyaları yükle
Scripti:
public_html içine at (cPanel)
ya da domain klasörüne
🗄️ 4. Veritabanı oluştur

cPanel → MySQL Database:

DB oluştur
kullanıcı oluştur
yetki ver
⚙️ 5. Config ayarı

Projede genelde şu dosyalardan biri olur:

config.php
.env
db.php

Bunu aç ve düzenle:

DB_HOST=localhost
DB_NAME=veritabani_adi
DB_USER=kullanici
DB_PASS=sifre
🧱 6. Database import (çok önemli)

Repo içinde .sql dosyası varsa:

phpMyAdmin → Import
.sql dosyasını yükle

👉 Eğer yoksa:

siteyi açınca installer açılır (çoğu smm panelde böyle)
🚀 7. Kurulum ekranı (varsa)

Tarayıcıya gir:

domainin.com

Eğer installer varsa:

DB bilgilerini gir
admin oluştur
finish
🔐 8. Admin panel

Genelde:

domain.com/admin
🧹 9. Güvenlik

Kurulumdan sonra:

install klasörünü sil
debug kapat
admin şifreni değiş

