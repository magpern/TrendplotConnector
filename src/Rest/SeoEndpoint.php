<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use TrendplotConnector\Seo\SeoManager;
use WP_REST_Request;
use WP_REST_Response;

class SeoEndpoint
{
    /**
     * Pre-flight: resolve the post and verify it is a Trendplot-managed post.
     * Returns the WP_Post on success or a WP_REST_Response error on failure.
     */
    private static function resolve(int $post_id): \WP_Post|WP_REST_Response
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash') {
            return new WP_REST_Response(
                ['code' => 'not_found', 'message' => "Post ID {$post_id} does not exist."],
                404
            );
        }

        $article_id = (string) get_post_meta($post_id, '_trendplot_article_id', true);
        if ($article_id === '') {
            return new WP_REST_Response(
                ['code' => 'not_trendplot_post', 'message' => "Post ID {$post_id} is not a Trendplot-managed post."],
                403
            );
        }

        return $post;
    }

    public static function handle_get(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $result  = self::resolve($post_id);

        if ($result instanceof WP_REST_Response) {
            return $result;
        }

        return new WP_REST_Response([
            'id'  => $post_id,
            'seo' => SeoManager::is_active() ? SeoManager::read($post_id) : null,
        ], 200);
    }

    public static function handle_patch(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $result  = self::resolve($post_id);

        if ($result instanceof WP_REST_Response) {
            return $result;
        }

        $body = $request->get_json_params();
        if (!is_array($body) || !array_key_exists('seo', $body)) {
            return new WP_REST_Response(
                ['code' => 'validation_error', 'message' => 'Request body must contain a "seo" object.'],
                400
            );
        }

        if (!is_array($body['seo'])) {
            return new WP_REST_Response(
                ['code' => 'validation_error', 'message' => 'Field "seo" must be an object.'],
                400
            );
        }

        $clean_seo = SeoManager::validate($body['seo']);
        if (is_wp_error($clean_seo)) {
            return new WP_REST_Response(
                ['code' => $clean_seo->get_error_code(), 'message' => $clean_seo->get_error_message()],
                (int) ($clean_seo->get_error_data()['status'] ?? 400)
            );
        }

        $seo_status = 'none';
        if (!empty($clean_seo)) {
            if (SeoManager::is_active()) {
                SeoManager::write($post_id, $clean_seo);
                $seo_status = 'written';
            } else {
                $seo_status = 'skipped';
            }
        }

        return new WP_REST_Response([
            'id'         => $post_id,
            'seo_status' => $seo_status,
            'seo'        => SeoManager::is_active() ? SeoManager::read($post_id) : null,
        ], 200);
    }
}
