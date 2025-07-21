document.addEventListener("DOMContentLoaded", () => {
    const btn = document.querySelector(".btn");
    if (btn) {
        btn.addEventListener("click", () => {
            btn.style.transform = "scale(0.9)";
            setTimeout(() => (btn.style.transform = "scale(1)"), 200);
        });
    }
});
