<?php
declare(strict_types=1);

namespace TrendplotConnector;

use TrendplotConnector\Admin\ContentPage;
use TrendplotConnector\Admin\SettingsPage;
use TrendplotConnector\RelatedArticles\RelatedArticlesBlock;
use TrendplotConnector\Rest\Router;

class Plugin
{
    public static function init(): void
    {
        add_action('rest_api_init', [Router::class, 'register']);
        add_action('admin_menu', [ContentPage::class, 'register_menu']);
        add_action('admin_menu', [SettingsPage::class, 'register_menu']);
        add_action('admin_init', [SettingsPage::class, 'register_settings']);
        add_action('admin_init', [SettingsPage::class, 'handle_generate_secret']);
        add_action('admin_init', [SettingsPage::class, 'redirect_legacy']);
        add_action('admin_init', [ContentPage::class, 'handle_bulk_actions']);
        add_action('admin_post_tp_publish_post', [ContentPage::class, 'handle_row_publish']);
        add_action('admin_post_tp_unpublish_post', [ContentPage::class, 'handle_row_unpublish']);
        RelatedArticlesBlock::register_hooks();
    }
}
