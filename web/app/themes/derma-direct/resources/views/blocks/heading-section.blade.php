<section
    @class([
        'heading-section-wrapper',
        'section-wrapper-padding' => !($remove_section_padding ?? false),
    ])
    role="region"
    aria-label="{{ $heading ?? 'Section' }}"
>
    <div
        @class([
            'container flex flex-col gap-2.5 md:gap-3',
            'gap-[2px] md:gap-[6px]' => ($layout ?? 'no-divider') === 'divider',
            $alignment ?? '[&_h2]:text-center [&_p]:text-center [&_h2]:mx-auto items-center',
            $padding_top ?? 'pt-6 sm:pt-10 md:pt-15 lg:pt-20',
            $padding_bottom ?? 'pb-6 sm:pb-10 md:pb-15 lg:pb-20',
        ])
    >
        @unless (empty($heading ?? ''))
            <h2
                class="font-poppins {{ ($max_width ?? 'max-w-inherit') }} font-bold text-2xl sm:text-3xl md:text-4xl lg:text-5xl leading-[1.3] lg:tracking-[-1px] text-primary uppercase"
                role="heading"
                aria-level="2"
            >
                {{ $heading }}
            </h2>
        @endunless

        @if (($layout ?? 'no-divider') === 'divider')
            <div
                class="border-b-2 border-grey-outline mb-5"
                role="separator"
                aria-hidden="true"
            ></div>
        @endif

        @unless (empty($tagline ?? ''))
            <div
                @class([
                    'font-quicksand font-normal text-base sm:text-lg md:text-xl lg:text-2xl leading-[1.2] lg:tracking-[-0.1px] text-medium-grey',
                    'text-primary-light' => ($layout ?? 'no-divider') === 'divider',
                ])
                role="note"
                aria-label="Section tagline"
            >
                {!! $tagline !!}
            </div>
        @endunless
    </div>
</section>
