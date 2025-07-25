window.addEventListener('load', function() {
    // مقداردهی اولیه آبجکت تلگرام
    const tg = window.Telegram.WebApp;
    
    // نمایش اپلیکیشن
    tg.ready();

    const closeButton = document.getElementById('close-btn');

    // مدیریت رویداد کلیک روی دکمه بستن
    closeButton.addEventListener('click', function() {
        // بستن مینی اپ
        tg.close();
    });
});