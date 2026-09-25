@php
    $stickyDesktop = get_field('header_sticky_desktop', 'option');
    $stickyMobile  = get_field('header_sticky_mobile', 'option');
@endphp

<header
    class="
        relative
        {{ $stickyMobile ? 'sticky lg-up:relative top-0 z-50' : '' }}
        {{ $stickyDesktop ? 'lg-up:sticky lg-up:top-0 lg-up:z-50' : '' }}
    "
    role="banner"
    aria-label="Website header and navigation"
>
    @includeIf('sections.partials.top-header')
    @includeIf('sections.partials.middle-header')

    {{-- Navigation --}}
    <div class="bg-white border-b border-grey-outline section-wrapper-padding py-[9px] relative bottom-header">
        <div class="container">
            <div class="flex lg-up:gap-3 2xl:gap-4 items-center justify-between">

                <div class="flex gap-6 items-center">

                    {!! wp_nav_menu([
                        'theme_location' => 'all_categories',
                        'container'      => 'nav',
                        'menu_class'     => 'all-categories-mega-menu',
                        'fallback_cb'    => false,
                        'items_wrap'     => '<ul role="menubar" class="%2$s">%3$s</ul>',
                        'menu_id'        => '',
                    ]) !!}

                    {!! wp_nav_menu([
                        'theme_location' => 'brands',
                        'container'      => 'nav',
                        'menu_class'     => 'all-brands-mega-menu',
                        'fallback_cb'    => false,
                        'items_wrap'     => '<ul role="menubar" class="%2$s">%3$s</ul>',
                        'menu_id'        => '',
                    ]) !!}

                    {{-- Main navigation menu --}}
                    @unless (empty($main_navigation_menu_items ?? []))
                        <nav
                            class="hidden z-30 absolute top-16 right-0 sm:left-5 max-sm:w-full max-lg-up:w-[350px] lg-up:static lg-up:block lg-up:w-auto max-lg-up:p-6 rounded max-lg-up:border border-grey-outline max-lg-up:bg-white"
                            id="main_nav_menu"
                            role="navigation"
                            aria-label="Main navigation menu"
                        >
                            <ul
                                class="flex flex-col gap-6 lg-up:gap-4 xl:gap-6 lg-up:flex-row lg-up:items-center text-primary text-base font-poppins uppercase font-[700] [&_a]:flex [&_a]:items-center [&_a]:gap-[5px] 2xl:[&_a]:gap-1 [&_a]:cursor-pointer [&_button]:cursor-pointer"
                                role="menubar"
                            >

                                @foreach ($main_navigation_menu_items as $menu_item)

                                    @continue (empty($menu_item->title ?? '') || empty($menu_item->url ?? ''))

                                    @php
                                        $menu_classes = !empty($menu_item->classes)
                                            ? implode(' ', array_filter($menu_item->classes))
                                            : '';
                                    @endphp

                                    <li
                                        class="relative {{ esc_attr($menu_classes) }}"
                                        role="none"
                                    >

                                        <a
                                            href="{!! esc_attr($menu_item->url) !!}"
                                            class="submenu-btn flex items-center gap-1 2xl:gap-2 max-lg-up:justify-between w-full"
                                            role="menuitem"
                                            @if (!empty($menu_item->children ?? []))
                                                aria-haspopup="true"
                                                aria-expanded="false"
                                                aria-label="Open submenu for {{ esc_attr($menu_item->title) }}"
                                            @endif
                                        >
                                            <div class="flex items-center gap-1 2xl:gap-2">

                                                @unless (empty($menu_item->icon ?? ''))
                                                    {!! wp_get_attachment_image($menu_item->icon, 'full', false, [
                                                        'alt' => esc_attr($menu_item->title),
                                                        'aria-hidden' => 'true'
                                                    ]) !!}
                                                @endunless

                                                {!! $menu_item->title !!}
                                            </div>

                                            @unless (empty($menu_item->children ?? []))
                                                @svg('images.chevron', 'w-2 h-2 [&_path]:fill-primary', ['aria-hidden' => 'true'])
                                            @endunless

                                        </a>

                                        {{-- Submenu --}}
                                        @unless (empty($menu_item->children ?? []))
                                            <ul
                                                class="submenu hidden relative lg-up:absolute left-0 mt-4.5 bg-white rounded max-lg-up:text-sm lg-up:border border-grey-outline lg-up:shadow-lg hidden z-20 overflow-hidden md:min-w-[180px] z-10 lg-up:[&_li:not(:last-child)]:border-b [&_li]:border-grey-outline [&_a]:px-3 [&_a]:py-2 lg-up:[&_a]:p-2.5"
                                                role="menu"
                                                aria-label="Submenu for {{ esc_attr($menu_item->title) }}"
                                            >

                                                @foreach ($menu_item->children as $child_item)

                                                    @continue (empty($child_item->title ?? '') || empty($child_item->url ?? ''))

                                                    <li role="none">
                                                        <a
                                                            href="{!! esc_attr($child_item->url) !!}"
                                                            role="menuitem"
                                                        >
                                                            @unless (empty($child_item->icon ?? ''))
                                                                {!! wp_get_attachment_image($child_item->icon, 'full', false, [
                                                                    'alt' => esc_attr($child_item->title),
                                                                    'aria-hidden' => 'true'
                                                                ]) !!}
                                                            @endunless

                                                            {!! $child_item->title !!}
                                                        </a>
                                                    </li>

                                                @endforeach

                                            </ul>
                                        @endunless

                                    </li>

                                @endforeach

                            </ul>
                        </nav>
                    @endunless

                </div>

                <!-- for mobile -->
                <div class="w-full flex items-center header-search header-search-mobile lg-up:hidden">
                    {!! do_shortcode('[fibosearch]') !!}
                </div>

                <!-- for mobile -->
                <ul class="flex  gap-1 lg:gap-2 items-center justify-end text-secondary-grey text-xs lg-up:!text-sm font-lato leading-none ml-4 md:ml-5 lg-up:ml-auto lg-up:hidden" aria-label="User options">

                    {{-- Currency dropdown --}}
                    <li aria-label="Select currency">
                        @php
                            global $WOOCS;
                            $selected_currency = is_object($WOOCS) ? $WOOCS->current_currency : 'GBP';
                        @endphp
                        @includeIf('sections.partials.lang-currency-dropdown', [
                            'dropdown_items' => [
                                [ 'label' => 'EUR', 'url' => '#'],
                                [ 'label' => 'USD', 'url' => '#'],
                            ],
                            'additional_classes' => 'currency-switcher-dropdown [&_.dropdown-label]:!min-w-5',
                            'selected_value' => $selected_currency,
                        ])
                    </li>
                </ul>

            </div>
        </div>
    </div>
</header>

{{-- Mini cart --}}
@includeIf('woocommerce.mini-cart')

<div id="open_minicart"
    class="fixed right-6 top-1/2 origin-right rotate-270 px-4 py-2 text-white font-bold bg-primary border-2 border-white border-b-0 rounded-t-md z-30 cursor-pointer hidden lg:block">
    {!! __('View Cart', 'sage') !!}
</div>
