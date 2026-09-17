@php
$product_id = get_the_ID();
$tabs = [
    'description' => [
        'label' => 'Description',
        'show'  => true,
    ],

    'treatment-areas' => [
        'label' => 'Treatment Areas',
        'show'  => !empty(get_field('treatment_areas', $product_id)),
        'content' => get_field('treatment_areas', $product_id),
    ],

    'key-features' => [
        'label' => 'Key Features',
        'show'  => !empty(get_field('key_features', $product_id)),
        'content' => get_field('key_features', $product_id),
    ],

    'composition' => [
        'label' => 'Composition',
        'show'  => !empty(get_field('composition', $product_id)),
        'content' => get_field('composition', $product_id),
    ],

    'storage' => [
        'label' => 'Storage',
        'show'  => !empty(get_field('storage_information', $product_id)),
        'content' => get_field('storage_information', $product_id),
    ],

    'delivery' => [
        'label' => 'Delivery Information',
        'show'  => !empty(get_field('delivery_information', $product_id)),
        'content' => get_field('delivery_information', $product_id),
    ],
    'other-info' => [
        'label' => 'Other Information',
        'show'  => !empty(get_field('other_info', $product_id)),
        'content' => get_field('other_info', $product_id),
    ],
];

$product_specifications = [
    'Gauge'        => get_field('product_gauge', $product_id),
    'Length'       => get_field('product_length', $product_id),
    'Protocols'    => get_field('product_protocols', $product_id),
    'Product Type' => get_field('product_type', $product_id),
];

$has_product_specifications = collect($product_specifications)
    ->filter(fn($value) => !empty($value))
    ->isNotEmpty();

if ($has_product_specifications) {
    $tabs['product-specifications'] = [
        'label' => 'Product Specifications',
        'show'  => true,
    ];
}
@endphp

<div class="product-tabs overflow-auto whitespace-nowrap block border border-primary mt-10 [&::-webkit-scrollbar]:w-[3px] [&::-webkit-scrollbar]:h-[3px] [&::-webkit-scrollbar-track]:bg-[#f1f1f1] [&::-webkit-scrollbar-thumb]:bg-[#888] [&::-webkit-scrollbar-thumb]:rounded-full">
    @foreach($tabs as $tab_id => $tab)
        @if($tab['show'])
            <button
                class="product-tab {{ $loop->first ? 'active bg-primary text-white' : '' }} px-5 lg:px-10 py-3 font-bold hover:cursor-pointer"
                data-tab="{{ $tab_id }}">
                {{ $tab['label'] }}
            </button>
        @endif
    @endforeach
</div>

<div class="border border-primary border-t-0 p-5 lg:p-10">

    {{-- Description --}}
    <div class="product-tab-content" id="description">
        <h2 class="font-poppins font-bold text-2xl md:text-3xl lg:text-[32px] leading-[1.2] text-primary mb-6">
            {!! get_the_title() !!}
        </h2>
        @unless(empty($product_description_content ?? ''))
            @includeIf('blocks.heading-and-content', [
                'heading' => '',
                'content' => $product_description_content,
            ])
        @endunless
    </div>


    {{-- Generic WYSIWYG Tabs --}}
    @foreach(['treatment-areas', 'key-features', 'composition', 'storage', 'other-info', 'delivery'] as $tab_id)
        @if($tabs[$tab_id]['show'])
            <div class="product-tab-content hidden font-montserrat text-black text-lg [&_ul]:list-disc [&_ul]:pl-5" id="{{ $tab_id }}">
                {!! wp_kses_post($tabs[$tab_id]['content']) !!}
            </div>
        @endif
    @endforeach

    {{-- Product Specifications --}}
    @if($has_product_specifications)
        <div class="product-tab-content hidden" id="product-specifications">
            <div class="flex flex-col gap-4">
                @foreach($product_specifications as $label => $values)
                    @if(!empty($values))
                        <div class="flex gap-3">
                            <strong class="text-primary">{{ $label }}:</strong>
                            <span>{{ implode(', ', (array) $values) }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</div>
