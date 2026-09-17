@if($popup ?? null)

    <div
        id="customPopup"
        data-session="{{ $popup['session_close'] ? 'true' : 'false' }}"
        data-delay="{{ $popup['delay'] }}"
        class="fixed inset-0 bg-black/60 flex items-center justify-center z-[99999] p-5 opacity-0 invisible transition-all duration-1000"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Promotional popup', 'sage') }}"
    >
        <div class="relative max-w-[320px] md:max-w-[370px] lg:max-w-[530px] w-full">

            <button
                id="popupClose"
                type="button"
                class="absolute top-2 right-2 size-10 z-10 cursor-pointer"
                style="color: {{ esc_attr($popup['close_btn_color']) }}"
                aria-label="{{ __('Close popup', 'sage') }}"
            >
                @svg('images.close', 'size-[40px] [&_path]:fill-current')
            </button>

            @if($popup['image'] ?? '')
                {!! wp_get_attachment_image($popup['image'], 'full', false, [
                    'class'   => 'w-full block max-w-[320px] md:max-w-[370px] lg:max-w-[530px]',
                    'loading' => 'eager',
                    'decoding' => 'sync',
                ]) !!}
            @endif

        </div>
    </div>

@endif
