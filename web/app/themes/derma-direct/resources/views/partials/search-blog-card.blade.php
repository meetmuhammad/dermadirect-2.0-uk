{{--
  Blog post card for search results
  @param array $post  Keys: id, title, permalink, excerpt, date, thumbnail_id, categories, author
--}}
<article class="flex flex-col gap-4 bg-white p-4 lg:p-5 rounded-md">

    {{-- Thumbnail --}}
    @unless (empty($post['thumbnail_id'] ?? ''))
        <a href="{!! esc_url($post['permalink']) !!}" aria-hidden="true" tabindex="-1" class="overflow-hidden rounded-sm">
            {!! wp_get_attachment_image($post['thumbnail_id'], 'medium', false, [
                'class' => 'w-full h-[200px] object-cover',
                'alt'   => '',
            ]) !!}
        </a>
    @endunless

    {{-- Meta & body --}}
    <div class="flex flex-col gap-3 flex-1">

        {{-- Categories --}}
        @unless (empty($post['categories'] ?? ''))
            <span class="font-normal text-xs text-secondary-black">{!! $post['categories'] !!}</span>
        @endunless

        {{-- Title --}}
        <a
            href="{!! esc_url($post['permalink']) !!}"
            class="font-poppins font-bold text-xl text-primary leading-snug hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >{{ $post['title'] }}</a>

        {{-- Excerpt --}}
        @unless (empty($post['excerpt'] ?? ''))
            <p class="font-lato text-xs font-medium text-secondary-black leading-relaxed">{{ $post['excerpt'] }}</p>
        @endunless

        {{-- Footer: date + read more --}}
        <div class="flex items-center justify-between mt-auto pt-2 gap-2">
            <span class="font-lato text-xs text-secondary-grey">{{ $post['date'] }}</span>

            <a
                href="{!! esc_url($post['permalink']) !!}"
                class="inline-flex items-center font-quicksand font-semibold text-sm text-primary border border-primary py-2 px-4 rounded-sm leading-none hover:bg-primary hover:text-white transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
                {{ __('Read More', 'sage') }}
            </a>
        </div>
    </div>
</article>
