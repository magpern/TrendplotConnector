<?php
/**
 * Plugin Name: Trendplot Connector
 * Plugin URI:  https://github.com/magpern/TrendplotConnector
 * Description: Write-first content bridge between Trendplot and WordPress. Creates drafts, stores metadata, links products to articles.
 * Version:     1.0.1
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Author:      Trendplot
 * Text Domain: trendplot-connector
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('TRENDPLOT_CONNECTOR_VERSION', '1.0.1');
define('TRENDPLOT_CONNECTOR_API_VERSION', '1.0');
define('TRENDPLOT_CONNECTOR_DIR', plugin_dir_path(__FILE__));
define('TRENDPLOT_CONNECTOR_URL', plugin_dir_url(__FILE__));

require_once TRENDPLOT_CONNECTOR_DIR . 'src/autoload.php';

/**
 * Automatic updates via the private update server.
 *
 * Define PRIVATE_UPDATE_SERVER (scheme + host, no trailing slash) in
 * wp-config.php to enable; when it is not defined the plugin does not check
 * for updates.
 */
if (defined('PRIVATE_UPDATE_SERVER') && PRIVATE_UPDATE_SERVER) {
    require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
    \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        rtrim(PRIVATE_UPDATE_SERVER, '/') . '/?action=get_metadata&slug=trendplot-connector',
        __FILE__,
        'trendplot-connector'
    );
}


add_action('plugins_loaded', [\TrendplotConnector\Plugin::class, 'init']);
