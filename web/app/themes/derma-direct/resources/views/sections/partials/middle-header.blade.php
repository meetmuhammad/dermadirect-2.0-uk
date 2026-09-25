<div class="bg-[#fafafa] border-b border-grey-outline section-wrapper-padding py-[21px]">
    <div class="container">
        <div class="grid grid-cols-3 lg-up:flex justify-between items-center gap-2 lg:gap-4">

            <!-- for mobile -->
            <div class="flex items-center gap-3 sm:gap-4 lg-up:hidden">

                <button
                    id="mobile_menu_btn"
                    class="flex flex-col justify-center gap-1 bg-primary text-white rounded-sm p-2 sm:p-3 cursor-pointer"
                    type="button"
                    aria-label="Open mobile navigation menu"
                    aria-controls="main_nav_menu"
                    aria-expanded="false"
                >
                    <span class="block w-5 h-[3px] bg-white rounded-full"></span>
                    <span class="block w-5 h-[3px] bg-white rounded-full"></span>
                    <span class="block w-5 h-[3px] bg-white rounded-full"></span>
                </button>

            </div>

            {{-- Logo Search area --}}
            <div class="flex max-lg-up:justify-center items-center gap-7 flex-1">
                @unless (empty($website_logo ?? ''))
                    <a href="/" aria-label="Go to homepage">
                        {!! wp_get_attachment_image($website_logo, 'full', false, [
                            'class' => 'min-w-[210px] w-full !max-w-[170px] md:!max-w-[200px] lg:!max-w-[250px]',
                            'alt' => get_post_meta($website_logo, '_wp_attachment_image_alt', true) ?: 'Dermadirect site logo',
                        ]) !!}
                    </a>
                @endunless

                <div class="max-w-[450px] lg:max-w-[580px] w-full hidden lg-up:flex items-center header-search">
                    {!! do_shortcode('[fibosearch]') !!}
                </div>

                <div class="ml-auto hidden lg-up:block">
                    @unless (empty($support_center_number ?? []) || empty($support_center_number['title'] ?? '') || empty($support_center_number['url'] ?? ''))
                        <div
                            class="flex items-center gap-1 md:gap-2"
                            role="group"
                            aria-label="Support center contact information"
                        >
                            {{-- @if (!empty($training_button_link['url'] ?? '') && !empty($training_button_link['title'] ?? ''))
                            <a class="flex gap-2 items-center text-sm sm:text-xl font-quicksand font-bold py-3 px-5 rounded-md leading-none flex-row-reverse bg-primary text-white [&_img]:brightness-0 [&_img]:invert !text-[12px] whitespace-nowrap"
                            href="{{ esc_url($training_button_link['url']) }}"
                            target="{{ esc_attr($training_button_link['target'] ?: '_self') }}">
                                {!! wp_kses_post($training_button_link['title']) !!}
                            </a>
                            @endif --}}
                            @svg('images.headphone', 'size-4 md:size-6', ['aria-hidden' => 'true'])
                            <h4 class="flex flex-col font-quicksand text-secondary-black font-[700] leading-[1.2] text-sm sm:text-base md:text-lg lg-up:!text-xl leading-none">
                                <a
                                    class="hover:!underline decoration-1"
                                    href="{!! esc_attr($support_center_number['url']) !!}"
                                    aria-label="Call support center at {{ esc_attr($support_center_number['title']) }}"
                                >
                                    {{ $support_center_number['title'] }}
                                </a>
                            </h4>
                        </div>
                    @endunless
                </div>
            </div>

            {{-- Cart area --}}
            @unless (empty($woocommerce_menu_items ?? []))
                <nav class="woocommerce-menu" role="navigation" aria-label="Shop actions">
                    <ul
                        class="flex items-center justify-end gap-4 sm:gap-3 [&_li]:flex [&_li]:items-center [&_li]:gap-3 md:[&_li]:gap-2 text-secondary-grey text-xs md:text-sm font-lato leading-none"
                    >
                        @foreach ($woocommerce_menu_items as $menu_item)
                            @php
                                $notification_icon = App\View\Composers\Header::getNotificationBadge($menu_item);
                            @endphp
                            @continue (is_cart() && (($notification_icon['id'] ?? '') === 'woo_cart_count'))
                            <li class="{{ implode(' ', array_filter($menu_item->classes ?? [])) }}">
                                <a
                                    class="flex items-center gap-[6px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    href="{!! esc_attr($menu_item->url) !!}"
                                    aria-label="{{ $menu_item->title }}"
                                >
                                    <span class="relative">
                                        {!! wp_get_attachment_image($menu_item->icon, 'full', false, [
                                            'class' => 'size-[18px]',
                                            'alt' => '',
                                            'aria-hidden' => 'true',
                                        ]) !!}
                                        @unless (empty($notification_icon['class'] ?? 0) || empty($notification_icon['id'] ?? 0))
                                            <span
                                                id="{!! esc_attr($notification_icon['id']) !!}"
                                                class="notification-badge {{ $notification_icon['class'] }} bg-primary rounded-[50px] size-5 text-white text-[9px] flex items-center justify-center absolute -top-4 -right-3"
                                                aria-hidden="true"
                                            >{{ $notification_icon['count'] ?? 0 }}</span>
                                        @endunless
                                    </span>
                                    <span class="hidden lg:block">{{ $menu_item->title }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endunless
        </div>
    </div>
</div>
