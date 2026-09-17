<div class="product-description-heading-and-content flex flex-col gap-2 md:gap-4 w-full">
    @unless (empty($heading ?? ''))
        <h4 class="font-bold text-base sm:text-lg md:text-xl lg:text-2xl text-primary">{{ $heading }}</h4>
    @endunless

    @unless (empty($content ?? ''))
        <div class="content-wrapper flex flex-col gap-5">
            {!! $content !!}
        </div>
    @endunless
</div>
