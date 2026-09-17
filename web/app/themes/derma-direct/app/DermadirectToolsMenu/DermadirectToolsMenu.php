<?php
// Dermadirect Tools menu registration
namespace App\DermadirectToolsMenu;

class DermadirectToolsMenu
{
    /**
     * Register the Dermadirect Tools top-level menu.
     * This groups the functionalities which are rewritten from the 3rd party plugins directly into
     * the theme.
     */
    public static function register(): void
    {
        add_menu_page(
            'Dermadirect Tools',
            'Dermadirect Tools',
            'manage_options',
            'dermadirect-tools',
            '__return_null',
            'dashicons-admin-tools',
            3
        );
    }
}

// Hook into admin_menu early so submenus can attach
add_action('admin_menu', [\App\DermadirectToolsMenu\DermadirectToolsMenu::class, 'register'], 9);
