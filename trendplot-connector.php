<?php
/**
 * Plugin Name: Trendplot Connector
 * Plugin URI:  https://github.com/magpern/TrendplotConnector
 * Description: Write-first content bridge between Trendplot and WordPress. Creates drafts, stores metadata, links products to articles.
 * Version:     1.0.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Author:      Trendplot
 * Text Domain: trendplot-connector
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('TRENDPLOT_CONNECTOR_VERSION', '1.0.0');
define('TRENDPLOT_CONNECTOR_API_VERSION', '1.0');
define('TRENDPLOT_CONNECTOR_DIR', plugin_dir_path(__FILE__));
define('TRENDPLOT_CONNECTOR_URL', plugin_dir_url(__FILE__));

require_once TRENDPLOT_CONNECTOR_DIR . 'src/autoload.php';

add_action('plugins_loaded', [\TrendplotConnector\Plugin::class, 'init']);
