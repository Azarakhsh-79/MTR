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
        const grandTotal = subtotal + (cartData.deliveryCost || 0);
        document.getElementById('grand-total').innerText = grandTotal.toLocaleString() + ' تومان';
        
        tg.MainButton.setText(`پرداخت نهایی (${grandTotal.toLocaleString()} تومان)`);
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
        const apiUrl = 'https://www.rammehraz.com/Rambot/test/Amir/MTR/mini_app/api.php?action=get_cart';
        
        // **اصلاح کلیدی در اینجا**
        // مطمئن می‌شویم که initData وجود دارد و آن را به درستی برای URL انکود می‌کنیم
        if (!tg.initData) {
            tg.showAlert('خطا: اطلاعات اولیه تلگرام در دسترس نیست. لطفاً مینی اپ را دوباره باز کنید.');
            console.error('Telegram initData is missing.');
            loader.innerText = 'خطا در احراز هویت.';
            return;
        }

        const urlWithAuth = `${apiUrl}&initData=${encodeURIComponent(tg.initData)}`;

        fetch(urlWithAuth)
            .then(response => {
                if (!response.ok) {
                    // دریافت خطای متنی از سرور در صورت وجود
                    return response.json().then(err => { throw new Error(err.error || `HTTP error! status: ${response.status}`) });
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    tg.showAlert('خطا در بارگذاری اطلاعات: ' + data.error);
                } else {
                    cartData = data;
                    renderCart();
                }
            })
            .catch(error => {
                tg.showAlert('یک خطای شبکه رخ داد: ' + error.message);
                console.error('Fetch Error:', error);
                 loader.innerText = 'خطا در ارتباط با سرور.';
            })
            .finally(() => {
                loader.classList.add('hidden');
                cartContainer.classList.remove('hidden');
            });
    }

    // اجرای تابع برای دریافت اطلاعات
    fetchCartData();
});