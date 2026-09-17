<?php

namespace App\Fields\Partials;

use Log1x\AcfComposer\Builder;
use Log1x\AcfComposer\Partial;

class CtaButtonGroup extends Partial
{
    protected $args = [];


    // Overriding compose() to store $args.
    public function compose(array $args = [])
    {
        $this->args = $args;
        return parent::compose($args);
    }

    public function fields(): Builder
    {
        // Safely getting name or using fallback.
        $name = $this->args['name'] ?? 'cta_button_group';

        $builder = Builder::make($name);

        $builder
            ->addGroup($name)
                ->addLink('link')
                ->addImage('icon')
                ->addTrueFalse('reverse_icon_direction', [
                    'default_value' => false,
                ])
                ->addSelect('button_color', [
                    'choices' => [
                        'dark' => __('Dark', 'sage'),
                        'light' => __('Light', 'sage'),
                    ],
                    'default_value' => 'dark',
                        'return_format' => 'value',
                    ])
                ->endGroup();

        return $builder;
    }

    public static function getData( string $name='cta_button_group' ): array
    {
        $cta_button_group = (array) get_field($name ?? 'cta_button_group');

        return [
            'title' => $cta_button_group['link']['title'] ?? '',
            'url' => $cta_button_group['link']['url'] ?? '',
            'target' => $cta_button_group['link']['target'] ?? '',
            'icon' => $cta_button_group['icon'] ?? '',
            'reverse_icon_direction' => $cta_button_group['reverse_icon_direction'] ?? false,
            'button_color' => $cta_button_group['button_color'] ?? 'dark',
        ];
    }
}
