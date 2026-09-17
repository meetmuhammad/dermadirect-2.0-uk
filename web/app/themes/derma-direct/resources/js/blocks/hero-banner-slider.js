document.addEventListener('DOMContentLoaded', () => {
    const sliderEl = document.querySelector('.hero-banner-swiper');

    if (!sliderEl) return;

    const hasMultipleSlides = sliderEl.querySelectorAll('.swiper-slide').length > 1;
    const autoplayEnabled = sliderEl.dataset.autoplay === 'true';

    const VideoSlideDelay =
        (parseFloat(sliderEl.dataset.videoslidedelay) || 5) * 1000;

    const ImageSlideDelay =
        (parseFloat(sliderEl.dataset.imageslidedelay) || 3) * 1000;

    const swiper = new Swiper(sliderEl, {
        loop: hasMultipleSlides,
        speed: 600,
        autoplay: false,
        pagination: hasMultipleSlides
            ? {
                  el: '.hero-banner-pagination',
                  clickable: true,
              }
            : false,
        a11y: {
            prevSlideMessage: 'Previous slide',
            nextSlideMessage: 'Next slide',
        },
    });

    if (!hasMultipleSlides || !autoplayEnabled) {
        return;
    }

    let timer;

    const startAutoplay = (delay) => {
        clearTimeout(timer);

        timer = setTimeout(() => {
            swiper.slideNext();
        }, delay);
    };

    const getActiveSlideDelay = () => {
        const activeSlide = swiper.slides[swiper.activeIndex];
        const isVideoSlide = activeSlide?.dataset.video === 'true';

        return isVideoSlide ? VideoSlideDelay : ImageSlideDelay;
    };

    startAutoplay(getActiveSlideDelay());

    swiper.on('slideChange', () => {
        startAutoplay(getActiveSlideDelay());
    });

    swiper.on('touchEnd', () => {
        startAutoplay(getActiveSlideDelay());
    });

    swiper.on('destroy', () => {
        clearTimeout(timer);
    });
});