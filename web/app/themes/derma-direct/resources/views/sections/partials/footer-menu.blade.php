@unless (empty($footer_menu_items ?? []))
    <div class="flex flex-col gap-4 md:gap-6 font-quicksand max-sm:w-[45%] md:whitespace-nowrap">
        @unless (empty($heading ?? ''))
            <h2 class="font-poppins text-base leading-[18px] tracking-[-0.3px] font-bold uppercase">{!! $heading !!}</h2>
        @endunless
        <ul class="flex flex-col gap-2 md:gap-[14px] [&_li]:hover:text-[#ffffffb3] [&_li]:text-xs sm:[&_li]:text-sm [&_li]:font-normal">
            @foreach ($footer_menu_items as $menu_item)
                @continue (empty($menu_item->title ?? '') || empty($menu_item->url ?? ''))
                @php
                    $menu_classes = !empty($menu_item->classes)
                        ? implode(' ', array_filter($menu_item->classes))
                        : '';
                @endphp
                <li class="relative {{ esc_attr($menu_classes) }}" role="none">
                    <a href="{{ esc_url($menu_item->url) }}">{{ $menu_item->title }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endunless
