window.addEventListener('load', function() {
    const tg = window.Telegram.WebApp;
    tg.expand();
    
    const loader = document.getElementById('loader');
    const cartContainer = document.getElementById('cart-container');
    const cartItemsContainer = document.getElementById('cart-items');
    
    let cartData = { products: [], deliveryCost: 0 };

    // --- تابع برای رندر کردن سبد خرید ---
    function renderCart() {
        if (!cartData || !cartData.products) return;

        cartItemsContainer.innerHTML = '';
        let subtotal = 0;

        if (cartData.products.length === 0) {
            cartItemsContainer.innerHTML = '<p style="text-align:center;">سبد خرید شما خالی است.</p>';
            tg.MainButton.hide();
        } else {
             tg.MainButton.show();
        }

        cartData.products.forEach(product => {
            const itemElement = document.createElement('div');
            itemElement.className = 'cart-item';
            
            itemElement.innerHTML = `
                <img src="${product.image}" alt="${product.name}" class="item-image">
                <div class="item-details">
                    <div class="item-name">${product.name}</div>
                    <div class="item-price">${product.price.toLocaleString()} تومان</div>
                </div>
                <div class="item-quantity">
                    <button class="quantity-btn" onclick="changeQuantity(${product.id}, 1)">+</button>
                    <span class="quantity-value">${product.quantity}</span>
                    <button class="quantity-btn" onclick="changeQuantity(${product.id}, -1)">-</button>
                </div>
            `;
            cartItemsContainer.appendChild(itemElement);
            subtotal += product.price * product.quantity;
        });

        document.getElementById('subtotal').innerText = subtotal.toLocaleString() + ' تومان';
        document.getElementById('grand-total').innerText = (subtotal + cartData.deliveryCost).toLocaleString() + ' تومان';
        
        tg.MainButton.setText(`پرداخت نهایی (${(subtotal + cartData.deliveryCost).toLocaleString()} تومان)`);
    }

    // --- تابع برای تغییر تعداد محصول ---
    window.changeQuantity = function(productId, change) {
        const product = cartData.products.find(p => p.id === productId);
        if (product) {
            product.quantity += change;
            if (product.quantity <= 0) {
                cartData.products = cartData.products.filter(p => p.id !== productId);
            }
        }
        renderCart();
    }

    // --- رویداد کلیک روی دکمه اصلی تلگرام ---
    tg.onEvent('mainButtonClicked', function() {
        const finalData = {
            products: cartData.products,
        };
        tg.sendData(JSON.stringify(finalData));
    });

    // --- دریافت اطلاعات واقعی از سرور ---
    function fetchCartData() {
        // آدرس کامل فایل api.php شما
        const apiUrl = 'https://www.rammehraz.com/Rambot/test/Amir/MTR/api.php?action=get_cart';
        
        // ارسال initData برای احراز هویت
        const urlWithAuth = `${apiUrl}&initData=${tg.initData}`;

        fetch(urlWithAuth)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    tg.showAlert('خطا در بارگذاری اطلاعات: ' + data.error);
                } else {
                    cartData = data;
                    renderCart();
                }
            })
            .catch(error => {
                tg.showAlert('یک خطای شبکه رخ داد. لطفاً دوباره تلاش کنید.');
                console.error('Fetch Error:', error);
            })
            .finally(() => {
                loader.classList.add('hidden');
                cartContainer.classList.remove('hidden');
            });
    }

    // اجرای تابع برای دریافت اطلاعات
    fetchCartData();
});