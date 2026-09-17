@unless (empty($cta ?? []) || empty($cta['title'] ?? '') || empty($cta['url'] ?? ''))
    <a
        @class([
            'flex gap-2 items-center text-sm sm:text-xl font-quicksand font-bold py-3 px-5 rounded-md leading-none',
            'flex-row-reverse' => $cta['reverse_icon_direction'] ?? false,
            'bg-primary text-white [&_img]:brightness-0 [&_img]:invert' => ($cta['button_color'] ?? 'dark') === 'dark',
            'bg-white text-secondary-black [&_img]:brightness-0' => ($cta['button_color'] ?? 'dark') === 'light',
            $additional_button_classes ?? '',
        ])
        href="{!! esc_attr($cta['url']) !!}"
        target="{!! esc_attr($cta['target'] ?? '') !!}"
    >
        @unless (empty($cta['icon'] ?? ''))
            {!! wp_get_attachment_image($cta['icon'], 'full', false, [
                'class' => $icon_classes ?? '',
            ]) !!}
        @endunless

        @unless (empty($cta['icon_svg'] ?? ''))
            @svg($cta['icon_svg'], $icon_classes ?? '')
        @endunless

        {{ $cta['title'] }}
    </a>
@endunless
