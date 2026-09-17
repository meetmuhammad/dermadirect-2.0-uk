@extends('layouts.app')

@section('content')
    @if (! have_posts())
        <section class="flex flex-col items-center gap-10 pb-5">

            <div class="bg-black py-20 px-5 w-full">
                <div class="max-w-[1440px] w-full mx-auto font-poppins text-white flex flex-col items-center">
                    <h2 class="text-[135px] leading-[0.9] font-bold">404</h2>
                    <h4 class="text-3xl font-medium uppercase">Page Not Found</h4>
                    <p class="font-quicksand text-sm max-w-[400px] text-center mt-3">
                        The page you're looking for may have been moved, renamed, or is temporarily unavailable.
                    </p>
                </div>
            </div>

            <a class="px-8 py-3 rounded-md bg-black text-white font-quicksand"
                href="{{ esc_url(home_url('/')) }}">
                Back to Homepage
            </a>

        </section>
    @endif
@endsection
