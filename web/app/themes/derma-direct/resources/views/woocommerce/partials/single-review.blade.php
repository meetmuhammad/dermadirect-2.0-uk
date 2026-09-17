@php
    $rating = intval(get_comment_meta($review->comment_ID, 'rating', true));
    $time_ago = human_time_diff(strtotime($review->comment_date), current_time('timestamp')) . ' ago';
@endphp

<div class="bg-gray-100 p-6 rounded-xl max-w-[1208px] w-full mx-auto flex flex-col gap-5 justify-between mb-5">

    <div>
        <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
            <div class="flex items-center gap-3">
                <div class="flex flex-col">
                    <div class="flex items-center gap-2 mb-2">
                        <h4 class="font-semibold text-theme-dark-900 text-base">
                            {{ $review->comment_author }}
                        </h4>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="rating-range flex gap-2 items-center">
                            @for ($i = 1; $i <= 5; $i++)
                                <span class="star size-6 rounded-sm {{ $i <= $rating ? 'bg-primary' : 'bg-gray-300' }} flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto size-4" fill="white" viewBox="0 0 24 24">
                                        <path d="M12 17.27L18.18 21l-1.64-7.03 L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                                    </svg>
                                </span>
                            @endfor
                        </div>
                    </div>

                </div>
            </div>

            <time class="text-sm text-[#77807f] font-medium mt-1">
                {{ $time_ago }}
            </time>
        </div>

        <!-- Review text -->
        <div>
            <p class="text-theme-dark-900 text-base leading-relaxed">
                {{ $review->comment_content }}
            </p>
        </div>
    </div>

    {{-- Optional images --}}
    @if (!empty($images))
        <div class="flex gap-3 md:gap-5 overflow-x-auto">
            @foreach ($images as $img)
                <img class="h-20 object-contain" src="{{ $img }}" alt="">
            @endforeach
        </div>
    @endif

</div>
