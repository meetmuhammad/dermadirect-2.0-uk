<ul class="flex flex-wrap gap-3">
    <li>
        <a href="{{ get_permalink(get_option('page_for_posts')) }}"
            class="{{ is_home() ? 'blogs-active-category' : 'bg-white hover:bg-black hover:text-white' }} font-quicksand px-5 py-2 rounded-md cursor-pointer transition border border-[#eee]">
            All Posts
        </a>
    </li>

    @foreach (get_categories() as $cat)
        <li>
            <a href="{{ get_category_link($cat->term_id) }}"
                class="{{ is_category() && get_queried_object_id() === $cat->term_id ? 'blogs-active-category' : 'bg-white hover:bg-black hover:text-white' }} font-quicksand px-5 py-2 rounded-md cursor-pointer transition border border-[#eee]">
                {{ $cat->name }}
            </a>
        </li>
    @endforeach
</ul>
