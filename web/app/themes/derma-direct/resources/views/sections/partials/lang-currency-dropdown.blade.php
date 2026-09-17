@unless (empty($dropdown_items ?? []))
    <div
        @class([
            'dropdown relative inline-block text-left',
            $additional_classes ?? '',
        ])
        role="group"
        aria-label="Dropdown menu"
    >
        <button class="dropdown-btn flex items-center gap-1 cursor-pointer" aria-haspopup="true" aria-expanded="false" aria-controls="dropdown-menu">
            <span class="dropdown-label min-w-[50px]" aria-label="Selected option">{{ $selected_value }}</span>
            @svg('images.chevron', 'w-2 h-2 [&_path]:fill-secondary-grey')
        </button>
        <div class="dropdown-menu absolute right-0 mt-5 lg-up:mt-2 w-32 lg:w-48 bg-white rounded md:rounded-lg border border-grey-outline shadow-lg hidden z-20 overflow-hidden" id="dropdown-menu" role="menu">
            <ul class="flex flex-col [&_a:not(:last-child)]:border-b [&_a]:border-grey-outline [&_a]:p-2.5 [&_a]:hover:bg-primary [&_a]:hover:text-white" role="none">
                @foreach ($dropdown_items as $item)
                    @php
                        $is_selected = ($item['label'] === $selected_value);
                    @endphp
                    <li role="none">
                        <a href="{!! esc_attr($item['url']) !!}"
                            class="dropdown-item flex items-center gap-2 {{ $is_selected ? 'bg-primary text-white' : '' }}"
                            data-currency="{{ $item['label'] }}">
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endunless
