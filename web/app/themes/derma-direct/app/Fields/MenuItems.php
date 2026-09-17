<?php

namespace App\Fields;

use Log1x\AcfComposer\Builder;
use Log1x\AcfComposer\Field;

class MenuItems extends Field
{
    public function fields(): array
    {
        $builder = Builder::make('Menu Item Extra Fields');

        $builder
            ->setLocation('nav_menu_item', '==', 'all');

        $builder
            ->addImage('icon', [
                'label' => __('Menu Item Icon', 'sage'),
                'preview_size' => 'thumbnail',
                'library' => 'all',
            ]);

        return $builder->build();
    }
}
