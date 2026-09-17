@php global $wp_query; @endphp

@if ($wp_query->have_posts())

    <div id="blog-posts" class="mt-10 grid grid-cols-1 md:grid-cols-2 gap-6">
        @while ($wp_query->have_posts())
            @php $wp_query->the_post(); @endphp

            @php
                $title     = get_the_title();
                $excerpt   = get_the_excerpt();
                $permalink = get_permalink();
            @endphp

            @if (empty($title) || empty($permalink))
                @continue
            @endif

            <article class="bg-white rounded-md overflow-hidden transition">
                <a href="{{ esc_url($permalink) }}">

                    @if (has_post_thumbnail())
                        @php
                            echo get_the_post_thumbnail(
                                null,
                                'large',
                                ['class' => 'w-full h-[260px] object-cover']
                            );
                        @endphp
                    @endif

                    <div class="p-6">
                        <h3 class="font-poppins text-xl font-semibold mb-3">
                            {!! wp_kses($title, wp_kses_allowed_html('post')) !!}
                        </h3>
                        <p class="font-quicksand text-gray-600">
                            {!! wp_kses($excerpt, wp_kses_allowed_html('post')) !!}
                        </p>
                    </div>

                </a>
            </article>
        @endwhile
    </div>

    <div id="blog-pagination" class="flex justify-center mt-10 gap-3">
        {!! get_pagination($wp_query) !!}
    </div>

@else
    <p>No posts found.</p>
@endif

@php wp_reset_postdata(); @endphp