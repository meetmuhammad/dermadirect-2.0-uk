@extends('layouts.app')

@section('content')
<section class="px-5 py-10 bg-[#fafafa]">
    <div class="max-w-[1440px] mx-auto flex flex-col gap-8">
        <h2 class="font-poppins text-4xl font-bold">All Blogs</h2>
        <div class="flex flex-col lg:flex-row gap-8 pt-5">

            <div class="w-full lg:w-[70%]">

                @include('partials.blog-category-filter')
                @include('partials.blog-cards')
            </div>

            @include('partials.blog-sidebar')

        </div>
    </div>
</section>
@endsection
