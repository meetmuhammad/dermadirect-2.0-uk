<div class="bg-[#fafafa] top-header border-b border-grey-outline section-wrapper-padding py-[9px] hidden lg-up:block" aria-label="Top header section">
    <div class="container">
        <div class="flex justify-between items-center gap-2 md:gap-4">
            @unless (empty($top_menu_items ?? []))
                <nav class="header-top-menu hidden lg-up:block" role="navigation" aria-label="Top navigation menu">
                    <ul class="flex items-center gap-1 lg:gap-2 text-secondary-grey text-xs lg-up:!text-sm font-lato leading-none md:mr-auto">
                        @foreach ($top_menu_items as $menu_item)
                            @continue(empty($menu_item->title ?? '') || empty($menu_item->url ?? ''))

                            <li class="{{ !empty($menu_item->classes) ? implode(' ', $menu_item->classes) : '' }} max-sm:hidden">
                                <a
                                    href="{!! esc_url($menu_item->url) !!}"
                                    target="{!! esc_attr($menu_item->target ?? '') !!}"
                                    aria-label="Navigate to {{ $menu_item->title }}"
                                >
                                    {{ $menu_item->title }}
                                </a>
                            </li>

                            @if (!$loop->last)
                                <li class="w-[1px] h-[8px] bg-grey-outline max-sm:hidden" aria-hidden="true"></li>
                            @endif
                        @endforeach
                    </ul>
                </nav>
            @endunless

            @unless (empty($top_banner_text ?? ''))
                <p class="lg:w-[160px] lg:w-auto text-secondary-black text-xs lg-up:!text-sm font-[700] text-center leading-none hidden lg-up:block font-lato" aria-label="Top banner text">
                    {{ $top_banner_text }}
                </p>
            @endunless

            <ul class="flex  gap-1 lg:gap-2 items-center justify-end text-secondary-grey text-xs lg-up:!text-sm font-lato leading-none md:ml-auto lg:m-0 hidden lg-up:flex" aria-label="User options">

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
