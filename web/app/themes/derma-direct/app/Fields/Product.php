<?php

namespace App\Fields;

use Log1x\AcfComposer\Builder;
use Log1x\AcfComposer\Field;

class Product extends Field
{
    public function fields(): array
    {
        $builder = Builder::make('product');

        $builder
            ->setLocation('post_type', '==', 'product');

        $builder->addTrueFalse('buy_bulk_button', [
            'label' => 'Use Buy Bulk Button',
            'instructions' => 'Replace the archive Add to Cart text with "Buy Bulk".',
            'ui' => 1,
            'ui_on_text' => 'Buy Bulk',
            'ui_off_text' => 'Default',
            'default_value' => 0,
        ]);

        $builder
            ->addRepeater('package_includes')
                ->addText('item', [ 'wrapper' => ['width' => '50'] ])
            ->endRepeater();

        $builder
            ->addRepeater('key_benefits')
                ->addText('benefit')
            ->endRepeater();

        $builder
            ->addWysiwyg('punch_line_before_atc', [
                'label' => __('Punch line before Add To Cart button', 'sage'),
                'instructions' => __('Before Add to cart button', 'sage'),
                'wrapper' => ['width' => '70'],
            ])
            ->addImage('icon_before_atc', [
                'label' => __('Icon', 'sage'),
                'return_format' => 'url',
                'wrapper' => ['width' => '30'],
            ])

            ->addWysiwyg('punch_line_after_atc', [
                'label' => __('Punch line after Add To Cart button', 'sage'),
                'instructions' => __('After Add to cart button', 'sage'),
                'wrapper' => ['width' => '70'],
            ])
            ->addImage('icon_after_atc', [
                'label' => __('Icon', 'sage'),
                'return_format' => 'url',
                'wrapper' => ['width' => '30'],
            ]);

        $builder
            ->addRepeater('product_notes_points', [
                'instructions' => __('This will be shown below the Add to Cart button', 'sage'),
            ])
                ->addWysiwyg('point')
            ->endRepeater();


        $builder
            ->addWysiwyg('treatment_areas', [
                'label' => __('Treatment Areas', 'sage'),
                'wrapper' => ['width' => '50'],
            ])
            ->addWysiwyg('key_features', [
                'label' => __('Key Features', 'sage'),
                'wrapper' => ['width' => '50'],
            ])
            ->addWysiwyg('composition', [
                'label' => __('Composition', 'sage'),
                'wrapper' => ['width' => '50'],
            ])
            ->addWysiwyg('storage_information', [
                'label' => __('Storage Information', 'sage'),
                'wrapper' => ['width' => '50'],
            ])
            ->addWysiwyg('delivery_information', [
                'label' => __('Delivery Information', 'sage'),
                'wrapper' => ['width' => '50'],
            ])
            ->addWysiwyg('other_info', [
                'label' => __('Other Information', 'sage'),
                'wrapper' => ['width' => '50'],
            ]);

        /**
         * The choices options will be dynamically filled via Theme Settings > Product tab.
         * There is a repeater field with name `ingredients_list`.
        */
        $builder
            ->addSelect('ingredients', [
                'choices' => [], // Left empty intentionally.
                'return_format' => 'value',
                'multiple' => true,
            ]);

        /**
         * The choices options will be dynamically filled via Theme Settings > Product tab.
         * There is a repeater field with name `ingredients_list`.
        */
        $builder
            ->addSelect('product_gauge', [
                'choices' => [], // Left empty intentionally.
                'return_format' => 'value',
                'multiple' => true,
            ]);

        /**
         * The choices options will be dynamically filled via Theme Settings > Product tab.
         * There is a repeater field with name `ingredients_list`.
        */
        $builder
            ->addSelect('product_length', [
                'choices' => [], // Left empty intentionally.
                'return_format' => 'value',
                'multiple' => true,
            ]);

        /**
         * The choices options will be dynamically filled via Theme Settings > Product tab.
         * There is a repeater field with name `product_type_list`.
        */
        $builder
            ->addSelect('product_type', [
                'choices' => [], // Left empty intentionally.
                'return_format' => 'value',
                'multiple' => true,
            ]);

        /**
         * The choices options will be dynamically filled via Theme Settings > Product tab.
         * There is a repeater field with name `product_protocols_list`.
        */
        $builder
            ->addSelect('product_protocols', [
                'choices' => [], // Left empty intentionally.
                'return_format' => 'value',
                'multiple' => true,
            ]);

        $builder
            ->addNumber('best_selling_count', [
                'label' => __('Best Selling Count', 'sage'),
                'instructions' => __('Automatically updated from orders in the last 30 days.', 'sage'),
                'default_value' => 0,
                'wrapper' => [
                    'width' => '50',
                ],
            ]);

        return $builder->build();
    }
}
