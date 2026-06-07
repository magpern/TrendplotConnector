<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use TrendplotConnector\Auth\HmacAuth;

class Router
{
    private const NAMESPACE = 'trendplot/v1';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [HealthEndpoint::class, 'handle'],
            'permission_callback' => [HmacAuth::class, 'verify_if_configured'],
        ]);

        register_rest_route(self::NAMESPACE, '/site-info', [
            'methods'             => 'GET',
            'callback'            => [SiteInfoEndpoint::class, 'handle'],
            'permission_callback' => [HmacAuth::class, 'verify'],
        ]);

        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => 'GET',
            'callback'            => [TaxonomiesEndpoint::class, 'handle_categories'],
            'permission_callback' => [HmacAuth::class, 'verify'],
        ]);

        register_rest_route(self::NAMESPACE, '/tags', [
            'methods'             => 'GET',
            'callback'            => [TaxonomiesEndpoint::class, 'handle_tags'],
            'permission_callback' => [HmacAuth::class, 'verify'],
        ]);

        register_rest_route(self::NAMESPACE, '/drafts', [
            [
                'methods'             => 'GET',
                'callback'            => [DraftsEndpoint::class, 'handle_get'],
                'permission_callback' => [HmacAuth::class, 'verify'],
                'args'                => [
                    'page'     => ['default' => 1, 'sanitize_callback' => 'absint'],
                    'per_page' => ['default' => 50, 'sanitize_callback' => 'absint'],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [DraftsEndpoint::class, 'handle_post'],
                'permission_callback' => [HmacAuth::class, 'verify'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/meta', [
            'methods'             => 'PATCH',
            'callback'            => [MetaEndpoint::class, 'handle'],
            'permission_callback' => [HmacAuth::class, 'verify'],
            'args'                => [
                'id' => ['sanitize_callback' => 'absint'],
            ],
        ]);
    }
}
