document.addEventListener("DOMContentLoaded",  () => {
    new Swiper(".testimonial-slider", {
        slidesPerView: 1,
        spaceBetween: 20,
        loop: true,

        // autoplay: {
        //     delay: 2500,
        //     disableOnInteraction: false,
        // },

        navigation: {
            nextEl: ".testimonial-slider .swiper-button-next",
            prevEl: ".testimonial-slider .swiper-button-prev",
        },

        breakpoints: {
            768: { slidesPerView: 2 },
            1024: { slidesPerView: 3 },
        },
    });

    document.querySelectorAll('.testimonial-content-wrapper').forEach((wrapper) => {
        const content = wrapper.querySelector('.testimonial-content');
        const button = wrapper.querySelector('.testimonial-toggle');

        if (!content || !button) return;

        // Show button only when content exceeds 3 lines
        if (content.scrollHeight > content.clientHeight) {
            button.classList.remove('hidden');
        }

        button.addEventListener('click', () => {
            if (content.classList.contains('line-clamp-3')) {
                content.classList.remove('line-clamp-3');
                content.classList.add('line-clamp-5');
                button.textContent = 'See Less';
            } else {
                content.classList.remove('line-clamp-5');
                content.classList.add('line-clamp-3');
                button.textContent = 'See More';
            }
        });
    });
});
