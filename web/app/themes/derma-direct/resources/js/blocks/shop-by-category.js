document.addEventListener('DOMContentLoaded', () => {
    const slider__els = document.querySelectorAll('.shop-by-category-wrapper .swiper.product-slider');
    if (slider__els.length === 0) return;

    slider__els.forEach(slider => {
        // Get the parent wrapper
        const wrapper__el = slider.closest('.shop-by-category-wrapper');
        if (!wrapper__el) return;

        // Find arrows inside this wrapper__el
        const prev_arrow__el = wrapper__el.querySelector('.prev-arrow');
        const next_arrow__el = wrapper__el.querySelector('.next-arrow');

        new Swiper(slider, {
            slidesPerView: 3,
            spaceBetween: 16,
            loop: true,
            navigation: {
                nextEl: next_arrow__el,
                prevEl: prev_arrow__el,
            },
            autoplay: false,
            breakpoints: {
                0: { slidesPerView: 1 },
                480: { slidesPerView: 2 },
                768: { slidesPerView: 2 },
                1024: { slidesPerView: 3 },
            },
        });
    });
});
