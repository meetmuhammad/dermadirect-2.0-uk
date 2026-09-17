<?php

use App\Helper\Helper;

add_shortcode('treatment-areas', function (): string {
    if (!is_product()) return '';

    $treatment_areas_list    = Helper::getArrayItems('treatment_areas_list', 'option');
    $product_treatment_areas = (array) get_field('treatment_areas', get_the_ID());

    if (empty($treatment_areas_list) || empty($product_treatment_areas)) return '';

    $areas = array_filter(
        $treatment_areas_list,
        fn($area) => in_array($area['area_name'], $product_treatment_areas, true)
    );

    if (empty($areas)) return '';

    ob_start();
    ?>
    <section class="flex flex-col gap-2 md:gap-4">
        <h4 class="font-bold text-base sm:text-lg md:text-xl lg:text-2xl text-primary">
            <?php echo esc_html__('Treatment Areas:', 'sage'); ?>
        </h4>
        <div class="flex flex-wrap gap-5 gap-y-8 lg:gap-10 items-start font-montserrat">
            <?php foreach ($areas as $area) : ?>
                <?php if (empty($area['area_name']) || empty($area['area_icon'])) continue; ?>
                <div class="flex flex-col gap-2 md:gap-8 items-center max-w-[80px] sm:max-w-[120px] md:max-w-[138px] w-full">
                    <div class="h-15 w-15 bg-[#0000001A] rounded-[8px] p-2.5 flex justify-center items-center">
                        <?php echo wp_get_attachment_image($area['area_icon'], 'thumbnail', false, ['class' => 'w-10 h-full']); ?>
                    </div>
                    <p class="font-medium text-sm sm:text-base md:text-lg leading-[1.2] text-black text-center">
                        <?php echo esc_html($area['area_name']); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
});
