document.addEventListener('DOMContentLoaded', function () {
    const openButtons = document.querySelectorAll('.subscribe-newsletter-btn');
    const modal = document.querySelector('.newsletter-modal');
    const closeButton = document.querySelector('.close-popup');

    openButtons.forEach(button => {
        button.addEventListener('click', function () {
            modal.classList.remove('hidden');
        });
    });

    closeButton?.addEventListener('click', function () {
        modal.classList.add('hidden');
    });
});