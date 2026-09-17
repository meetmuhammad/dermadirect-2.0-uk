document.addEventListener('DOMContentLoaded', () => {
    const marquee_left__el = document.querySelector('.reviews-slider-section-wrapper .marquee-left');
    const marquee_right__el = document.querySelector('.reviews-slider-section-wrapper .marquee-right');

    if (!marquee_left__el || !marquee_right__el) return;

    function setMarqueeAnimation(selector, direction = "left", pixelsPerSecond = 60) {
        const swiperWrapper = document.querySelector(`${selector} .swiper-wrapper`);
        const swiperContainer = document.querySelector(selector);

        if (!swiperWrapper || !swiperContainer) return;

        const totalWidth = swiperWrapper.scrollWidth;
        const containerWidth = swiperContainer.offsetWidth;
        const moveX = totalWidth - containerWidth;

        // Save dynamic moveX for CSS animation distance
        swiperWrapper.style.setProperty("--moveX", `${moveX}px`);

        // Calculate duration dynamically — longer width = more time
        const duration = moveX / pixelsPerSecond; // seconds

        const animationName = direction === "left" ? "scrollBackAndForthLeft" : "scrollBackAndForthRight";
        swiperWrapper.style.animation = `${animationName} ${duration}s linear infinite alternate`;
    }

    // Init both sliders
    setMarqueeAnimation(".reviews-slider-section-wrapper .marquee-left", "left");
    setMarqueeAnimation(".reviews-slider-section-wrapper .marquee-right", "right");

    // Recalculate on resize
    window.addEventListener("resize", () => {
        setMarqueeAnimation(".reviews-slider-section-wrapper .marquee-left", "left");
        setMarqueeAnimation(".reviews-slider-section-wrapper .marquee-right", "right");
    });

    new Swiper(marquee_left__el, {
        slidesPerView: "auto",
        spaceBetween: 12,
        allowTouchMove: false,
    });
    new Swiper(marquee_right__el, {
        slidesPerView: "auto",
        spaceBetween: 12,
        allowTouchMove: false,
    });

    function addHoverPause(swiperEl) {
        const wrapper = swiperEl.querySelector(".swiper-wrapper");
        swiperEl.querySelectorAll(".swiper-slide").forEach(slide => {
            slide.addEventListener("mouseenter", () => {
                wrapper.style.animationPlayState = "paused";
            });
            slide.addEventListener("mouseleave", () => {
                wrapper.style.animationPlayState = "running";
            });
        });
    }

    addHoverPause(marquee_left__el);
    addHoverPause(marquee_right__el);

});
