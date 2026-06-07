<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use WP_REST_Request;
use WP_REST_Response;

class HealthEndpoint
{
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'status'         => 'ok',
            'api_version'    => TRENDPLOT_CONNECTOR_API_VERSION,
            'plugin_version' => TRENDPLOT_CONNECTOR_VERSION,
            'timestamp'      => time(),
        ], 200);
    }
}
