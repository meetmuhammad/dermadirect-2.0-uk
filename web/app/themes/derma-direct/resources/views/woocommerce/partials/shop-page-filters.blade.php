<div class="relative lg:sticky lg:top-5 lg:max-w-[293px] w-full">
    {{-- Choose Filters button for mobile view  --}}
    <button class="select-category-button w-full bg-black p-[10px_40px_10px_16px] text-left rounded text-sm text-white flex lg:hidden flex-col gap-2 relative z-20 pr-5 product-toggle cursor-pointer">
        Choose Filters
    </button>

    <div class="filters-wrapper-scroll-bar absolute !z-[22] w-full max-lg:opacity-0 max-lg:z-0 max-lg:left-0 max-lg:h-[300px] max-lg:bottom-0 overflow-auto lg:top-5 bg-white shadow-[0px_0px_10px_0px_rgba(0,0,0,0.24)] lg:bg-inherit lg:shadow-none flex-1 flex flex-col gap-4 lg:sticky max-lg:hidden product-menu top-full">
        <!-- Filter Box -->
        <div class="p-6 border border-grey-outline lg:rounded-[14px] bg-white flex flex-col gap-7">
            <div class="flex flex-col gap-4 border-b border-grey-outline">
                <h2 class="text-2xl text-primary font-poppins font-bold leading-none">Filters</h2>
                <div class="w-[73px] h-[2px] bg-primary -mb-[1px]"></div>
            </div>

            <div class="flex flex-col gap-[22px]">
                <div class="filters-scroll-bar flex flex-col gap-5 lg:max-h-[70vh] lg:pr-2.5 overflow-x-hidden overflow-y-auto -mr-[14px]">
                    {{-- ===========================================================================================
                    Categories filter.
                    =========================================================================================== --}}
                    @unless (empty($categories_data ?? []))
                        <div class="flex flex-col gap-5">
                            <div class="flex flex-col gap-4">
                                <div class="flex gap-4 justify-between">
                                    <h4 class="text-[#6D6D6D] text-sm font-poppins font-[600] lg:tracking-[-0.3px]">Category</h4>
                                    <span id="selected_categories_count" class="text-secondary-grey">{{-- To be filled via JS --}}</span>
                                </div>
                            </div>

                            <ul class="flex flex-col gap-[16px]  text-[#253D4E] text-sm font-quicksand font-medium">
                                @foreach ($categories_data as $index => $cat)
                                    @continue (empty($cat['name'] ?? '') || empty($cat['slug'] ?? '') || empty($cat['permalink'] ?? ''))
                                    @continue (in_array(strtolower($cat['slug'] ?? ''), ['uncategorized', 'uncategorised']))
                                    <li
                                        @class([
                                            '[&_a.active]:bg-primary [&_a.active]:border-primary [&_a.active]:text-white [&_a.active_span]:border-white font-semibold',
                                            'category-filter-option cursor-pointer hover:bg-primary/80 hover:text-white',
                                            'border border-[#F2F3F4] bg-white rounded',
                                            'bg-primary border-primary text-white' => ($cat['is_active'] ?? false),
                                            '!hidden' => ($index ?? 0) > 5,
                                        ])
                                    >
                                        <a
                                            @class([
                                                'flex items-center justify-between py-[14px] px-5',
                                                'active' => ($cat['is_active'] ?? false),
                                            ])
                                            href="{{ esc_url($cat['permalink']) }}"
                                            data-category-slug="{{ esc_attr($cat['slug']) }}"
                                        >
                                            {!! $cat['name'] !!}
                                            <span @class([
                                                'size-[22px] bg-white border rounded-full flex items-center justify-center text-[12px] text-[#253D4E]',
                                                'border-grey-outline' => !($cat['is_active'] ?? false),
                                                'border-white' => ($cat['is_active'] ?? false),
                                            ])>{{ $cat['count'] ?? 0 }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                            @if (count($categories_data ?? []) > 6)
                                <button
                                    id="see_more_categories"
                                    class="font-poppins text-[13px] font-medium text-[#6D6D6D] cursor-pointer"
                                    type="button"
                                    data-default-qty="6"
                                >
                                    {{ __('See more', 'sage') }}
                                </button>
                            @endif
                        </div>
                    @endunless

                    {{-- ===========================================================================================
                    Dynamic filters (Brands and ACF fields).
                    =========================================================================================== --}}
                    @foreach ($filter_configs ?? [] as $filter)
                        @php
                            $filter_data = ${$filter['data_var']} ?? [];
                            $filter_key = $filter['key'];
                            $css_class = $filter['css_class'];
                        @endphp

                        @if (in_array($filter_key, $filters_to_show ?? []) && !empty($filter_data))
                            @if ($filter_key === 'brands')
                                <div class="flex flex-col gap-5">

                                <div class="flex flex-col gap-4">
                                    <div class="flex gap-4 justify-between">
                                        <h4 class="text-[#6D6D6D] text-sm font-poppins font-[600] lg:tracking-[-0.3px]">
                                            {{ $filter['title'] }}
                                        </h4>
                                        <span id="selected_brands_count" class="text-secondary-grey text-sm"></span>
                                    </div>
                                </div>

                                <ul class="flex flex-col gap-[16px] text-[#253D4E] text-sm font-quicksand font-medium">

                                    @foreach ($filter_data as $index => $item)

                                        @php
                                            $item_slug = $filter['is_object'] ? ($item->slug ?? '') : ($item['slug'] ?? '');
                                            $item_name = $filter['is_object'] ? ($item->name ?? '') : ($item['name'] ?? '');
                                            $item_count = $filter['is_object'] ? ($item->count ?? 0) : ($item['count'] ?? 0);

                                            $is_active = in_array($item_slug, $active_brands ?? []);
                                        @endphp

                                        @continue(empty($item_name) || empty($item_slug))

                                            <li
                                                @class([
                                                    '[&_a.active]:bg-primary [&_a.active]:border-primary [&_a.active]:text-white [&_a.active_span]:border-white font-semibold',
                                                    'brand-filter-option cursor-pointer hover:bg-primary/80 hover:text-white',
                                                    'border border-[#F2F3F4] bg-white rounded',
                                                    '!hidden' => ($index ?? 0) > 4,
                                                ])
                                            >
                                            <a
                                                href="{{ esc_url('/brand/' . $item_slug) }}"
                                                @class([
                                                    'flex items-center justify-between py-[14px] px-5',
                                                    'active' => ($item_slug && in_array($item_slug, $active_brands ?? [])),
                                                ])
                                            >
                                                <span>{{ $item_name }}</span>

                                                <span
                                                    @class([
                                                        'size-[22px] bg-white border rounded-full flex items-center justify-center text-[12px] text-[#253D4E]',
                                                        'border-grey-outline',
                                                    ])
                                                >
                                                    {{ $item_count }}
                                                </span>
                                            </a>
                                        </li>

                                    @endforeach

                                </ul>

                                @if (count($filter_data) > 4)
                                    <button
                                        id="see_more_{{ $filter_key }}"
                                        class="font-poppins text-[13px] font-medium text-[#6D6D6D] cursor-pointer"
                                        type="button"
                                        data-default-qty="4"
                                    >
                                        {{ __('See more', 'sage') }}
                                    </button>
                                @endif

                            </div>
                            @else
                                <div class="flex flex-col gap-[11px]">
                                    <div class="flex gap-4 justify-between">
                                        <h4 class="text-[#6D6D6D] text-sm font-poppins font-[600] lg:tracking-[-0.3px]">{{ $filter['title'] }}</h4>
                                        <span id="selected_{{ $filter_key }}_count" class="text-secondary-grey text-sm">{{-- To be filled via JS --}}</span>
                                    </div>
                                    <div class="space-y-[13px]">
                                        @foreach ($filter_data as $index => $item)
                                            @php
                                                $item_slug = $filter['is_object'] ? ($item->slug ?? '') : ($item['slug'] ?? '');
                                                $item_name = $filter['is_object'] ? ($item->name ?? '') : ($item['name'] ?? '');
                                                $item_count = $filter['is_object'] ? ($item->count ?? 0) : ($item['count'] ?? 0);

                                                $is_checked = $filter_key === 'brands' && in_array($item_slug, $active_brands ?? []);
                                            @endphp

                                            @continue (empty($item_name) || empty($item_slug) || empty($item_count))

                                            <label @class([
                                                'flex items-center space-x-2 cursor-pointer text-[#687188] text-[13px] tracking-[0.5px] leading-[17px] font-quicksand font-medium',
                                                $css_class . '-filter-option-label',
                                                '!hidden' => $index > 3,
                                            ])>
                                                <input type="checkbox" class="{{ $css_class }}-filter-option hidden peer" value="{{ $item_slug }}" @checked($is_checked)/>
                                                <div class="size-[17px] rounded-[2px] border-2 border-[#CED4DA] peer-checked:bg-primary peer-checked:border-primary flex items-center justify-center transition-all duration-200">
                                                    @svg('checkbox-checkmark')
                                                </div>
                                                <span>{{ $item_name }} ({{ $item_count }})</span>
                                            </label>
                                        @endforeach

                                        @if (count($filter_data) > 4)
                                            <button
                                                id="see_more_{{ $filter_key }}"
                                                class="font-poppins text-[13px] font-medium text-[#6D6D6D] cursor-pointer"
                                                type="button"
                                                data-default-qty="4"
                                            >
                                                {!! __('See more', 'sage') !!}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>

                {{-- ===========================================================================================
                Clear All filters button.
                =========================================================================================== --}}
                <button id="clear_all_filters" class="flex items-center justify-center gap-3 px-4 py-[13px] rounded-[7px] border border-primary text-primary text-sm font-quicksand font-[600] leading-none cursor-pointer">Clear all filters</button>
            </div>
        </div>

    </div>
</div>
