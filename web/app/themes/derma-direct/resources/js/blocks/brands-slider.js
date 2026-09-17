document.addEventListener("DOMContentLoaded",  () => {

    const el = document.querySelector(".brand-slider");
    if (!el) return;

    const autoplay = el.dataset.autoplay === 'true';
    const autoplayDelay = parseInt(el.dataset.autoplayDelay, 10) || 5000;

    new Swiper(".brand-slider", {
        slidesPerView: 1,
        spaceBetween: 30,
        loop: true,

        autoplay: autoplay ? {
            delay: autoplayDelay,
            disableOnInteraction: false,
        } : false,

        pagination: {
            el: ".brand-slider .swiper-pagination",
            clickable: true,
        },

        breakpoints: {
            640: { slidesPerView: 2 },
            768: { slidesPerView: 3 },
            1024: { slidesPerView: 4 },
        },
    });

});
