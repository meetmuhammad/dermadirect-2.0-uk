@php
    $full_width = $full_width ?? false;
    $layout = $layout ?? 'content_left';
    $ctaUrl = $link['url'] ?? '';
    $ctaText = $link['title'] ?? '';
    $ctaTarget = $link['target'] ?? '';
    $textColor = $text_color ?? '#000000';
@endphp
<section class="video-banner {{ !$full_width ? 'section-wrapper-padding py-5 lg:py-10 relative' : '' }} overflow-hidden relative flex items-center justify-center h-full px-5 max-lg:py-10">
    <div class="{{ !$full_width ? 'container' : '' }} w-full sm:w-[80%] md:w-[70%] lg:w-full max-w-[1440px] mx-auto">
        <div class="{{ !$full_width ? 'relative' : '' }}">
            @unless (empty($desktop_background_image ?? '') || empty($mobile_background_image ?? ''))
                <div class="absolute inset-0 w-full h-full">
                    {!! wp_get_attachment_image($desktop_background_image, 'full', false, [
                        'class' => 'absolute inset-0 w-full h-full object-cover hidden lg:block',
                    ]) !!}
                    {!! wp_get_attachment_image($mobile_background_image, 'full', false, [
                        'class' => 'absolute inset-0 w-full h-full object-cover block lg:hidden',
                    ]) !!}
                </div>
            @endunless

            @unless (empty($video['url'] ?? ''))
                <div class="flex flex-col-reverse lg:flex-row gap-5 sm:gap-10">

                    <div class="flex-1 relative flex items-center justify-center {{ $layout === 'content_right' ? 'lg:order-2' : 'lg:order-1' }}">
                        <div class="relative z-10 flex flex-col items-center lg:items-start gap-4 sm:gap-5 lg:gap-6 font-quicksand text-center" style="color: {{ $textColor }};">
                            @if (!empty($heading ?? ''))
                                <h2 class="text-3xl sm:text-4xl md:text-5xl xl:text-6xl font-bold leading-[1.05] font-poppins text-center lg:text-left max-sm:max-w-[450px]">{{ $heading }}</h2>
                            @endif
                            @if (!empty($description ?? ''))
                                <p class="text-base sm:text-lg lg:text-xl leading-relaxed text-center lg:text-left">{{ $description }}</p>
                            @endif
                            @if (!empty($bullets ?? []))
                                <ul class="flex flex-col sm:grid grid-cols-2
                                gap-x-5 gap-y-2">
                                    @foreach ($bullets as $bullet)
                                        @continue (empty($bullet['bullet_text'] ?? ''))
                                        <li class="text-left relative pl-5 text-sm sm:text-base lg:text-lg before:absolute before:left-0 before:top-3 before:h-2 before:w-2 before:-translate-y-1/2 before:rounded-full before:bg-current">
                                            {{ $bullet['bullet_text'] }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (!empty($ctaUrl))
                                <a href="{!! esc_url($ctaUrl) !!}" @if (!empty($ctaTarget)) target="{{ $ctaTarget }}" rel="noopener noreferrer" @endif class="flex items-center justify-center gap-4 bg-black text-white text-sm sm:text-base lg:text-lg font-quicksand font-semibold py-3.5 px-7 sm:py-4 sm:px-8 rounded-md leading-none uppercase tracking-wide mt-2 hover:opacity-90 transition-opacity">
                                    {{ $ctaText ?: 'Shop Now' }}
                                    <img class="h-4 w-4 sm:h-5 sm:w-5" src="{!! esc_url(get_stylesheet_directory_uri() . '/resources/images/slider_arrow.svg') !!}" alt="">
                                </a>
                            @endif
                        </div>
                    </div>

                    <div class="flex-1 relative max-lg:pb-5 flex items-center justify-center {{ $layout === 'content_right' ? 'lg:order-1 lg:pl-[60px]' : 'lg:order-2 lg:pr-[60px]' }}">
                        <div class="relative h-full flex items-center justify-center w-full">
                            <div class="relative w-full">
                                <video
                                    id="bannerVideo"
                                    class="hero-banner-media !w-full rounded-[16px] lg:rounded-[30px] shadow-xl/30 h-[210px] sm:h-[320px] lg:h-auto max-lg:!object-cover"
                                    autoplay
                                    playsinline
                                    muted
                                    controls
                                >
                                    <source src="<?php echo esc_url($video['url']); ?>" type="video/mp4">
                                    @if (!empty($video_captions ?? []))
                                        @foreach ($video_captions as $caption)
                                            @continue (empty($caption['caption_file']['url'] ?? ''))
                                            <track
                                                src="{{ esc_url($caption['caption_file']['url']) }}"
                                                kind="captions"
                                                srclang="{{ $caption['caption_language'] }}"
                                                label="{{ ucfirst($caption['caption_language']) }}"
                                                @if (!empty($caption['is_default'])) default @endif
                                            >
                                        @endforeach
                                    @endif
                                    Your browser does not support the video tag.
                                </video>

                                <!-- Overlay Button -->
                                <button
                                    id="soundToggleBtn"
                                    class="absolute top-3 right-3 w-[150px] h-9 bg-black/70 text-white text-sm px-4 py-2 rounded-full backdrop-blur-md hover:bg-black/80 transition"
                                >
                                    Click for sound 🔊
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            @endunless
        </div>
    </div>
</section>
