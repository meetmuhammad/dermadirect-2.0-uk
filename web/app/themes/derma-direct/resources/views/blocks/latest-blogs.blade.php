<section class="latest-blogs section-wrapper-padding pt-[30px] md:pt-[50px] py-3 md:py-6 md:pb-10 lg:pb-15">
    <div class="max-w-[1440px] w-full mx-auto">

        @if (!empty($latestBlogs) && is_array($latestBlogs) && count($latestBlogs))

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            @foreach ($latestBlogs as $post)

            @php
                $link = $post['link'] ?? '';
                $title = $post['title'] ?? '';
                $excerpt = $post['excerpt'] ?? '';
                $image_id = $post['image_id'] ?? null;
            @endphp

            @if (!empty($title) || !empty($image_id))

                <div class="bg-white rounded-md overflow-hidden transition duration-300 flex flex-col h-full">

                {{-- Image --}}
                @if (!empty($image_id))

                    @if (!empty($link))
                    <a href="{{ esc_url($link) }}">
                    @endif

                    {!! wp_get_attachment_image(
                    $image_id,
                    'large',
                    false,
                    [
                        'class' => 'h-48 w-full object-cover',
                        'alt' => esc_attr($title),
                    ]
                    ) !!}

                    @if (!empty($link))
                    </a>
                    @endif

                @endif

                {{-- Content --}}
                @if (!empty($title) || !empty($excerpt))

                    <div class="p-4 md:p-6 flex flex-col flex-1">

                    {{-- Title --}}
                    @if (!empty($title))

                        <h3 class="mt-3 font-semibold text-lg text-black leading-[1.2] font-poppins">

                        @if (!empty($link))
                            <a href="{{ esc_url($link) }}">
                            {!! wp_kses($title, wp_kses_allowed_html('post')) !!}
                            </a>
                        @else
                            {!! wp_kses($title, wp_kses_allowed_html('post')) !!}
                        @endif

                        </h3>

                    @endif

                    {{-- Excerpt --}}
                    @if (!empty($excerpt))
                        <p class="font-quicksand font-medium text-sm text-primary mt-2">
                        {!! wp_kses($excerpt, wp_kses_allowed_html('post')) !!}
                        </p>
                    @endif

                    </div>

                @endif

                </div>

            @endif

            @endforeach

        </div>

        @else
        <p class="text-center text-gray-500">
            No blog posts available.
        </p>
        @endif

    </div>
</section>
