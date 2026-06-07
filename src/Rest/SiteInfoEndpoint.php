<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use WP_REST_Request;
use WP_REST_Response;

class SiteInfoEndpoint
{
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'site_name'          => get_bloginfo('name'),
            'home_url'           => home_url(),
            'wordpress_version'  => get_bloginfo('version'),
            'api_version'        => TRENDPLOT_CONNECTOR_API_VERSION,
            'plugin_version'     => TRENDPLOT_CONNECTOR_VERSION,
            'woocommerce_active' => self::is_plugin_active('woocommerce/woocommerce.php'),
            'rank_math_active'   => self::is_plugin_active('seo-by-rank-math/rank-math.php'),
            'language'           => get_bloginfo('language'),
            'timezone'           => wp_timezone_string(),
        ], 200);
    }

    private static function is_plugin_active(string $plugin): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($plugin);
    }
}
