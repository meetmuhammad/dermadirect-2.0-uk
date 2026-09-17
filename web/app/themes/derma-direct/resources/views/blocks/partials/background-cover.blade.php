{{-- Desktop view image --}}
@unless (empty($background_image_desktop ?? ''))
    {!!
        wp_get_attachment_image($background_image_desktop, 'full', false, [
            'class' => join(' ', [
                'hidden sm:block w-full !h-full absolute inset-0 object-cover object-top',
                $additional_img_classes ?? '',
            ]),
            'alt' => get_post_meta($background_image_desktop, '_wp_attachment_image_alt', true) ?: 'Section background cover image',
            'loading' => $img_loading ?? 'lazy',
        ])
    !!}
@endunless

{{-- Mobile view image --}}
@unless (empty($background_image_mobile ?? ''))
    {!!
        wp_get_attachment_image($background_image_mobile, 'full', false, [
            'class' => join(' ', [
                'block sm:hidden w-full !h-full absolute inset-0 object-cover',
                $additional_img_classes ?? '',
            ]),
            'alt' => get_post_meta($background_image_mobile, '_wp_attachment_image_alt', true) ?: 'Section background cover image',
            'loading' => $img_loading ?? 'lazy',
        ])
    !!}
@endunless
