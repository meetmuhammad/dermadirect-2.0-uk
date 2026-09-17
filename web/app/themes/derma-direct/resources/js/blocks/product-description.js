document.addEventListener('DOMContentLoaded', () => {
    const toggle__el = document.querySelector('#description-toggle');
    const text__el = document.querySelector('#description-text');
    const arrow__el = document.querySelector('.product-description-wrapper .toggle-arrow');
    if (!toggle__el || !text__el || !arrow__el) return;

    // Default Open
    text__el.style.maxHeight = text__el.scrollHeight + "px";
    arrow__el.classList.add('rotate-180');

    // Toggle
    toggle__el.addEventListener('click', () => {
        const isOpen = text__el.style.maxHeight !== "0px";

        if (isOpen) {
            text__el.style.maxHeight = "0px"; // ← Close properly
            arrow__el.classList.remove('rotate-180');
        } else {
            text__el.style.maxHeight = text__el.scrollHeight + "px"; // ← Re-open
            arrow__el.classList.add('rotate-180');
        }
    });
});
