<section class="relative section-wrapper-padding py-10 md:py-10 bg-[#EEEEEE]">
    <div class="max-w-[1096px] w-full mx-auto">
        <div class="flex flex-col sm:flex-row gap-5 sm:gap-10 justify-between sm:items-center">
            <div class="flex-1">
                @if(!empty($newsletter_block_title))
                    <h3 class="font-poppins text-[#231f20] font-bold text-2xl md:text-3xl lg:text-[2rem] leading-tight mb-2">{!! wp_kses($newsletter_block_title, wp_kses_allowed_html('post')) !!}</h3>
                @endif

                @if(!empty($newsletter_block_subtitle))
                    <p class="font-quicksand text-[#4a4a4a] text-base md:text-lg max-w-lg">{!! wp_kses($newsletter_block_subtitle, wp_kses_allowed_html('post')) !!}</p>
                @endif
            </div>
            <div class="flex-shrink-0">
                <button type="button" class="font-poppins subscribe-newsletter-btn bg-[#231f20] hover:bg-[#3d3d3d] text-white font-medium text-base md:text-lg px-8 py-3.5 rounded-md transition-colors duration-200">{{ esc_html($button_text ?: 'Subscribe') }}</button>
            </div>
        </div>
    </div>
</section>

@if(!empty($subscribe_form_shortcode))
    @php
        $hasImage = !empty($form_image);
        $hasForm  = !empty($subscribe_form_shortcode);

        $gridCols = ($hasImage && $hasForm)
            ? 'lg:grid-cols-2'
            : 'lg:grid-cols-1';
    @endphp
    <section class="bg-black/40 fixed w-screen h-screen top-0 left-0 flex flex-col items-center justify-center z-50 hidden newsletter-modal">
        <div class="inner bg-white lg:max-w-5xl max-h-[80vh] max-w-[90vw] md:max-w-[80vw] overflow-auto lg:mx-5">
            <div class="subscribe-form-popup grid grid-cols-1 {{ $gridCols }}">
                @if(!empty($form_image))
                    <div class="hidden lg:flex relative">
                        {!! wp_get_attachment_image($form_image, 'full', false, [
                            'class' => 'h-full w-full object-cover object-center md:absolute inset-0'
                        ]) !!}
                    </div>
                @endif
                <div class="flex flex-col justify-start px-5 py-10 gap-5 relative">

                    <div class="flex flex-col items-start">
                        <div class="flex justify-between items-center gap-5 w-full">
                            <h2 class="font-poppins tracking-widest text-2xl font-bold uppercase">Subscribe</h2>
                            <button class="close-popup cursor-pointer" type="button">
                            @svg('images.subscribe-form-close')
                            </button>
                        </div>
                        <p class="mt-5 font-poppins text-base text-black">Stay in the loop with our exclusive offers and new product launches by signing up to our email and SMS updates!</p>
                        @if(!empty($subscribe_form_shortcode))
                            <div class="mt-6 w-full">
                                {!! do_shortcode($subscribe_form_shortcode) !!}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endif
