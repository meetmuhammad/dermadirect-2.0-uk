@extends('layouts.app')

@section('content')
    <section class="section-wrapper-padding py-10">
        <div class="container">

            <div class="font-quicksand mb-8">
                <h1 class="text-2xl font-bold text-secondary-black">All Brands</h1>
            </div>

            @if (!empty($brands))
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-5">
                    @foreach ($brands as $brand)
                        <a href="{{ esc_url($brand['permalink']) }}" class="flex flex-col gap-3 bg-white rounded-md p-4 hover:shadow-md transition-shadow duration-200 group">
                            <div class="flex justify-center items-center aspect-square overflow-hidden rounded">
                                @if (!empty($brand['thumbnail_url']))
                                    <img src="{{ esc_url($brand['thumbnail_url']) }}" alt="{{ esc_attr($brand['name']) }}" class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-200" />
                                @else
                                    <div class="w-full h-full bg-gray-100 flex items-center justify-center">
                                        <span class="text-gray-400 text-4xl">🏷️</span>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-col gap-1">
                                <h2 class="font-quicksand font-bold text-sm text-secondary-black leading-snug group-hover:text-primary transition-colors">{!! esc_html($brand['name']) !!}</h2>
                                @if ($brand['count'] > 0)
                                    <span class="text-xs text-secondary-grey font-normal">{{ $brand['count'] }} {{ $brand['count'] === 1 ? __('product', 'sage') : __('products', 'sage') }}</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="font-quicksand text-secondary-grey">{{ __('No brands found.', 'sage') }}</p>
            @endif

        </div>
    </section>
@endsection