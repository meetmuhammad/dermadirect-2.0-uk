<footer
    class="bg-primary pt-10 pb-4 md:pb-8 md:pt-15 md:pb-10 section-wrapper-padding"
    role="contentinfo"
    aria-label="Website footer"
>
    <div
        class="container flex flex-col lg:flex-row justify-between items-start gap-6 md:gap-8 text-white pb-10 md:pb-15"
    >
        <div
            class="w-full flex flex-col lg:flex-row gap-5 sm:gap-10 lg:gap-15 xl:gap-20"
            role="group"
            aria-label="About and navigation links"
        >
            <div
                class="lg:max-w-[450px] w-full flex flex-col gap-4 md:gap-6 font-quicksand"
                role="region"
                aria-label="About Derma Direct"
            >
                @unless (empty($about_derma_direct ?? ''))
                    <h2
                        class="font-poppins text-base sm:text-lg md:text-xl md:leading-[23px] md:tracking-[-0.4px] font-bold uppercase"
                    >
                        {!! __('About Derma Direct', 'sage') !!}
                    </h2>
                    <p class="lg:max-w-[370px] text-xs sm:text-sm md:text-base md:leading-[22px] font-normal">
                        {{ $about_derma_direct }}
                    </p>
                @endunless

                @unless (empty($vat_number ?? ''))
                    <p class="text-xs sm:text-sm md:text-base md:leading-[20px] md:tracking-[-1px] font-normal">
                        VAT: {{ $vat_number }}
                    </p>
                @endunless

                <div
                    class="lg:max-w-[400px] w-full"
                    role="region"
                    aria-label="Newsletter and social media"
                >
                    <div class="flex flex-col gap-6 md:gap-10 font-quicksand">

                        @unless (empty($social_handlers ?? []))
                            <div role="region" aria-label="Social media links">
                                <ul
                                    class="flex flex-wrap gap-3 md:gap-[18px] justify-start"
                                    role="list"
                                >
                                    @foreach($social_handlers as $social_profile)
                                        <li>
                                            <a
                                                href="{{ esc_url($social_profile['profile_link']) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="py-2 md:py-[13px] px-2 md:px-4 border-[1.2px] border-[#FFFFFF1A] hover:bg-primary inline-block rounded-[5px]"
                                                aria-label="Visit our {{ ucfirst($social_profile['platform']) }} page"
                                            >
                                                @svg("images.{$social_profile['platform']}", 'w-5 h-5', ['role' => 'img', 'aria-hidden' => 'true'])
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endunless
                    </div>
                </div>
            </div>

            <div class="flex max-lg:flex-wrap sm:flex-row justify-between max-sm:w-[calc(100%_-_24px)] gap-6 md:gap-10 w-full">
                @includeIf('sections.partials.footer-menu', [
                    'footer_menu_items' => $footer_nav_menu_items ?? [],
                    'heading' => 'Legal',
                ])

                @includeIf('sections.partials.footer-menu', [
                    'footer_menu_items' => $footer_services_menu_items ?? [],
                    'heading' => 'Account',
                ])

                <div class="flex flex-col gap-4 md:gap-6 font-quicksand whitespace-nowrap">
                    <h2 class="font-poppins text-base leading-[18px] tracking-[-0.3px] font-bold uppercase">Contact Info</h2>
                    <ul class="flex flex-col gap-2 md:gap-[14px] [&_li]:hover:text-[#ffffffb3] sm:[&_li]:text-sm sm:[&_li]:text-base lg:[&_li]:text-lg [&_li]:font-normal">
                        {{-- Contact details come from Theme Settings, not hardcoded markup: this
                             footer previously shipped EU's phone and info@dermadirect.com, which
                             put EU contact details on the UK storefront. Populated by
                             `wp dermadirect configure-uk apply` from the UK source. --}}
                        @php
                            $dd_phone = get_field('support_center_number', 'option');
                            $dd_email = 'support@dermadirect.co.uk'; // @httpsdermadirectcom.get_field('support_email', 'option');
                        @endphp
                        @if (!empty($dd_phone['title']))
                            <li class="relative " role="none">
                                <a href="{{ $dd_phone['url'] ?? '#' }}" class="flex items-center gap-2">
                                    @svg('images.phone-icon')
                                {{ $dd_phone['title'] }}</a>
                            </li>
                        @endif
                        @if (!empty($dd_email))
                            <li class="relative " role="none">
                                <a href="mailto:{{ $dd_email }}" class="flex items-center gap-2">
                                 @svg('images.mail-icon')
                                {{ $dd_email }}</a></li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>


    </div>

    @unless (empty($copyright_text ?? ''))
        <div
            class="border-t border-white/40 pt-4 md:pt-8 font-quicksand text-white -mx-5"
            aria-label="Copyright notice"
        >
            <p class="text-center text-[10px] sm:text-sm font-normal px-5">
                {{ $copyright_text }}
            </p>
        </div>
    @endunless
</footer>
