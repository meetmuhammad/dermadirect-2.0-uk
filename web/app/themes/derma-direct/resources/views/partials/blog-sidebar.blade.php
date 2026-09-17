<div class="w-full lg:w-[30%] font-quicksand flex flex-col gap-10 lg:sticky lg:top-8 self-start">

    <!-- Recent Posts -->
    <div class="bg-white p-6 rounded-md border border-[#eee]">
        <h3 class="font-poppins text-2xl font-bold">Recent Posts</h3>

        <ul class="mt-6 flex flex-col gap-5">
            @php
                $recent_posts = new WP_Query([
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'posts_per_page' => 3,
                ]);
            @endphp

            @while ($recent_posts->have_posts())
                @php $recent_posts->the_post(); @endphp
                <li>
                    <span class="font-quicksand text-sm text-gray-500">
                        {{ get_the_date('d/m/Y') }}
                    </span>
                    <a href="{{ get_permalink() }}" class="font-quicksand block text-lg font-semibold hover:underline">
                        {{ get_the_title() }}
                    </a>
                </li>
            @endwhile

            @php wp_reset_postdata(); @endphp
        </ul>
    </div>

    <!-- Recent Comments -->
    <div class="bg-white p-6 rounded-md border border-[#eee]">
        <h3 class="font-poppins text-2xl font-bold">Recent Comments</h3>

        <ul class="mt-6 flex flex-col gap-5">
            @php
                $recent_comments = get_comments([
                    'number' => 5,
                    'status' => 'approve',
                ]);
            @endphp

            @foreach ($recent_comments as $comment)
                <li class="pl-5 relative before:content-[''] before:absolute before:left-0 before:top-[9px] before:w-2 before:h-2 before:border-t-2 before:border-r-2 before:border-black before:rotate-45">
                    <span>
                        <a href="{{ get_comment_link($comment) }}">
                        {{ $comment->comment_author }} {{ "on" }}
                        </a>
                        <a href="{{ get_comment_link($comment) }}" class="font-quicksand font-semibold hover:underline">
                            {{ get_the_title($comment->comment_post_ID) }}
                        </a>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

</div>
