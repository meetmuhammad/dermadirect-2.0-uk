<?php

namespace App\Fields;

use Log1x\AcfComposer\Builder;
use Log1x\AcfComposer\Field;

class ProductCategory extends Field
{
    /**
     * The field group.
     */
    public function fields(): array
    {
        $builder = Builder::make('product_category');

        $builder
            ->setLocation('taxonomy', '==', 'product_cat');

        $builder
            ->addSelect('filters_to_show_on_cat_page', [
                'label' => __('Filters to show', 'sage'),
                'instructions' => __('Select the filters to show on this category page.', 'sage'),
                'choices' => [
                    'brands' => __('Brands', 'sage'),
                    'treatment_areas' => __('Treatment areas', 'sage'),
                    'ingredients' => __('Ingredients', 'sage'),
                    'product_gauge' => __('Product gauge', 'sage'),
                    'product_length' => __('Product length', 'sage'),
                    'product_type' => __('Product type', 'sage'),
                    'product_protocols' => __('Product protocols', 'sage'),
                ],
                'multiple' => true,
            ]);

        return $builder->build();
    }
}
