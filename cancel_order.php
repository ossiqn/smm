function cancelOrder(orderId) {
    const formData = new FormData();
    formData.append('order_id', orderId);
    
    Swal.fire({
        title: 'İşleniyor...',
        text: 'Sipariş iptal ediliyor, lütfen bekleyin.',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // YENİ ENDPOINT: cancel_order.php
    fetch('cancel_order.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            Swal.fire({
                title: '✅ İptal Başarılı!',
                html: `
                    <div style="text-align: center;">
                        <div style="font-size: 4rem; color: #10B981; margin-bottom: 20px;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div style="background: rgba(16, 185, 129, 0.1); padding: 20px; border-radius: 12px; margin: 20px 0;">
                            <div style="font-size: 1.2rem; color: var(--text-gray); margin-bottom: 10px;">İade Edilen Tutar</div>
                            <div style="font-size: 2.5rem; font-weight: bold; color: #10B981;">
                                +₺${parseFloat(data.refund_amount).toFixed(2)}
                            </div>
                        </div>
                        <div style="background: rgba(139, 92, 246, 0.1); padding: 15px; border-radius: 12px; margin: 20px 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="color: var(--text-gray);">Yeni Bakiyeniz:</span>
                                <span style="font-size: 1.5rem; font-weight: bold; color: var(--primary);">
                                    ₺${parseFloat(data.new_balance).toFixed(2)}
                                </span>
                            </div>
                        </div>
                    </div>
                `,
                showConfirmButton: true,
                confirmButtonText: 'Tamam',
                timer: 5000,
                timerProgressBar: true,
                allowOutsideClick: false,
                customClass: {
                    popup: 'success-popup'
                },
                willClose: () => {
                    // Sayfayı yenile
                    location.reload();
                }
            });
            
            // Kullanıcı bakiyesini anında güncelle
            document.getElementById('userBalance').innerHTML = `
                <i class="fas fa-coins"></i>
                <span class="currency-symbol">₺</span>${parseFloat(data.new_balance).toFixed(2)}
            `;
            
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Hata!',
                text: data.message,
                confirmButtonText: 'Tamam'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Hata!',
            text: 'İptal işlemi sırasında bir hata oluştu. Lütfen daha sonra tekrar deneyin.',
            confirmButtonText: 'Tamam'
        });
    });
}