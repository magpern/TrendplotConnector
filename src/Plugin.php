<?php
declare(strict_types=1);

namespace TrendplotConnector;

use TrendplotConnector\Admin\SettingsPage;
use TrendplotConnector\Rest\Router;

class Plugin
{
    public static function init(): void
    {
        add_action('rest_api_init', [Router::class, 'register']);
        add_action('admin_menu', [SettingsPage::class, 'register_menu']);
        add_action('admin_init', [SettingsPage::class, 'register_settings']);
        add_action('admin_init', [SettingsPage::class, 'handle_generate_secret']);
    }
}
