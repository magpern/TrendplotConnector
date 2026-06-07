<?php
declare(strict_types=1);

namespace TrendplotConnector\Auth;

use WP_Error;
use WP_REST_Request;

class HmacAuth
{
    private const TIMESTAMP_TOLERANCE = 300;

    public static function verify(WP_REST_Request $request): bool|WP_Error
    {
        $site_id_header  = $request->get_header('X-Trendplot-Site-Id');
        $timestamp_header = $request->get_header('X-Trendplot-Timestamp');
        $signature_header = $request->get_header('X-Trendplot-Signature');

        if (!$site_id_header || !$timestamp_header || !$signature_header) {
            return new WP_Error(
                'missing_headers',
                'Required authentication headers are missing.',
                ['status' => 401]
            );
        }

        $timestamp = (int) $timestamp_header;
        if (abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return new WP_Error(
                'timestamp_expired',
                'Request timestamp is outside the 5-minute window.',
                ['status' => 401]
            );
        }

        $settings = get_option('trendplot_connector_settings', []);
        $configured_site_id = $settings['site_id'] ?? '';
        $shared_secret      = $settings['shared_secret'] ?? '';

        if (empty($configured_site_id) || $site_id_header !== $configured_site_id) {
            return new WP_Error(
                'invalid_site_id',
                'Site ID does not match.',
                ['status' => 401]
            );
        }

        if (empty($shared_secret)) {
            return new WP_Error(
                'not_configured',
                'Shared secret is not configured.',
                ['status' => 401]
            );
        }

        $method = strtoupper($request->get_method());
        $rest_prefix = rtrim(parse_url(rest_url(), PHP_URL_PATH), '/');
        $path   = $rest_prefix . $request->get_route();
        $body   = $request->get_body();

        $signing_string = implode("\n", [$method, $path, $timestamp_header, $body]);
        $expected = hash_hmac('sha256', $signing_string, $shared_secret);

        if (!hash_equals($expected, strtolower($signature_header))) {
            return new WP_Error(
                'invalid_signature',
                'HMAC signature verification failed.',
                ['status' => 401]
            );
        }

        return true;
    }

    public static function verify_if_configured(WP_REST_Request $request): bool|WP_Error
    {
        $settings = get_option('trendplot_connector_settings', []);
        if (empty($settings['shared_secret'])) {
            return true;
        }
        return self::verify($request);
    }
}
