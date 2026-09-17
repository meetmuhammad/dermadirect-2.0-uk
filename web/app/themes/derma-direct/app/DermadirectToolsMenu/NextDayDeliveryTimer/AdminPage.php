<?php

declare(strict_types=1);

namespace App\DermadirectToolsMenu\NextDayDeliveryTimer;

defined('ABSPATH') || exit;

class AdminPage
{
    public static function init(): void
    {
        NextDayDeliveryTimerManager::init();

        add_action('acf/init', [self::class, 'registerOptionsPage']);
        add_action('acf/init', [self::class, 'registerFieldGroup']);
    }

    public static function registerOptionsPage(): void
    {
        if (!function_exists('acf_add_options_sub_page')) {
            return;
        }

        acf_add_options_sub_page([
            'page_title'  => __('Next Day Delivery Timer', 'derma-direct'),
            'menu_title'  => __('Next Day Delivery Timer', 'derma-direct'),
            'menu_slug'   => NextDayDeliveryTimerManager::OPTION_PAGE_SLUG,
            'parent_slug' => 'dermadirect-tools',
            'capability'  => 'manage_options',
            'redirect'    => false,
            'autoload'    => true,
        ]);
    }

    public static function registerFieldGroup(): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key' => 'group_nddt_settings',
            'title' => __('Next Day Delivery Timer', 'derma-direct'),
            'fields' => [
                [
                    'key' => 'field_nddt_banner_enabled',
                    'label' => __('Banner status', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_BANNER_ENABLED,
                    'type' => 'true_false',
                    'ui' => 1,
                    'default_value' => 1,
                    'message' => __('Enable the next-day delivery banner.', 'derma-direct'),
                ],
                [
                    'key' => 'field_nddt_fallback_enabled',
                    'label' => __('Fallback status', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_FALLBACK_ENABLED,
                    'type' => 'true_false',
                    'ui' => 1,
                    'default_value' => 1,
                    'message' => __('Show fallback content when the countdown is unavailable.', 'derma-direct'),
                ],
                [
                    'key' => 'field_nddt_fallback_message',
                    'label' => __('Fallback message', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_FALLBACK_MESSAGE,
                    'type' => 'wysiwyg',
                    'tabs' => 'all',
                    'toolbar' => 'basic',
                    'media_upload' => 0,
                    'delay' => 0,
                    'conditional_logic' => [
                        [
                            [
                                'field' => 'field_nddt_fallback_enabled',
                                'operator' => '==',
                                'value' => '1',
                            ],
                        ],
                    ],
                    'default_value' => 'To get next day delivery, place your order on Monday &ndash; Thursday before 10pm',
                ],
                [
                    'key' => 'field_nddt_cutoff_time',
                    'label' => __('Cutoff time', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_CUTOFF_TIME,
                    'type' => 'group',
                    'layout' => 'row',
                    'sub_fields' => [
                        [
                            'key' => 'field_nddt_cutoff_hour',
                            'label' => 'HH',
                            'name' => 'hour',
                            'type' => 'number',
                            'default_value' => 22,
                            'min' => 0,
                            'max' => 23,
                            'step' => 1,
                            'wrapper' => [
                                'width' => '50',
                            ],
                        ],
                        [
                            'key' => 'field_nddt_cutoff_minute',
                            'label' => 'MM',
                            'name' => 'minute',
                            'type' => 'number',
                            'default_value' => 0,
                            'min' => 0,
                            'max' => 59,
                            'step' => 1,
                            'wrapper' => [
                                'width' => '50',
                            ],
                        ],
                    ],
                ],
                [
                    'key' => 'field_nddt_holiday_ranges',
                    'label' => __('Holiday exclusions', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_HOLIDAY_RANGES,
                    'type' => 'repeater',
                    'layout' => 'table',
                    'button_label' => __('Add holiday exclusion', 'derma-direct'),
                    'sub_fields' => [
                        [
                            'key' => 'field_nddt_holiday_date',
                            'label' => __('Date', 'derma-direct'),
                            'name' => 'date',
                            'type' => 'date_picker',
                            'display_format' => 'Y-m-d',
                            'return_format' => 'Y-m-d',
                            'first_day' => 1,
                        ],
                        [
                            'key' => 'field_nddt_holiday_start_hour',
                            'label' => 'Start HH',
                            'name' => 'start_hour',
                            'type' => 'number',
                            'default_value' => 0,
                            'min' => 0,
                            'max' => 23,
                            'step' => 1,
                        ],
                        [
                            'key' => 'field_nddt_holiday_start_minute',
                            'label' => 'Start MM',
                            'name' => 'start_minute',
                            'type' => 'number',
                            'default_value' => 0,
                            'min' => 0,
                            'max' => 59,
                            'step' => 1,
                        ],
                        [
                            'key' => 'field_nddt_holiday_end_hour',
                            'label' => 'End HH',
                            'name' => 'end_hour',
                            'type' => 'number',
                            'default_value' => 23,
                            'min' => 0,
                            'max' => 23,
                            'step' => 1,
                        ],
                        [
                            'key' => 'field_nddt_holiday_end_minute',
                            'label' => 'End MM',
                            'name' => 'end_minute',
                            'type' => 'number',
                            'default_value' => 59,
                            'min' => 0,
                            'max' => 59,
                            'step' => 1,
                        ],
                    ],
                ],
                [
                    'key' => 'field_nddt_active_weekdays',
                    'label' => __('Countdown days', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_ACTIVE_WEEKDAYS,
                    'type' => 'checkbox',
                    'choices' => NextDayDeliveryTimerManager::getWeekdayChoices(),
                    'default_value' => ['1', '2', '3', '4'],
                    'layout' => 'vertical',
                    'return_format' => 'value',
                ],
                [
                    'key' => 'field_nddt_require_in_stock',
                    'label' => __('Stock rule', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_REQUIRE_IN_STOCK,
                    'type' => 'true_false',
                    'ui' => 1,
                    'default_value' => 1,
                    'message' => __('Only show the countdown when the current product is in stock.', 'derma-direct'),
                ],
                [
                    'key' => 'field_nddt_border_color',
                    'label' => __('Border color', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_BORDER_COLOR,
                    'type' => 'color_picker',
                    'default_value' => '#000000',
                ],
                [
                    'key' => 'field_nddt_countdown_color',
                    'label' => __('Countdown color', 'derma-direct'),
                    'name' => NextDayDeliveryTimerManager::FIELD_COUNTDOWN_COLOR,
                    'type' => 'color_picker',
                    'default_value' => '#008000',
                ],
            ],
            'location' => [
                [
                    [
                        'param' => 'options_page',
                        'operator' => '==',
                        'value' => NextDayDeliveryTimerManager::OPTION_PAGE_SLUG,
                    ],
                ],
            ],
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
        ]);
    }
}
