<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use TrendplotConnector\Auth\HmacAuth;
use WP_REST_Request;
use WP_REST_Response;

class PublishEndpoint
{
    private static function resolve(int $post_id): \WP_Post|WP_REST_Response
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash' || $post->post_status === 'auto-draft') {
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

    public static function handle_publish(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $result  = self::resolve($post_id);

        if ($result instanceof WP_REST_Response) {
            return $result;
        }

        $allowed = ['draft', 'pending', 'future'];
        if (!in_array($result->post_status, $allowed, true)) {
            return new WP_REST_Response([
                'code'    => 'invalid_status_transition',
                'message' => "Cannot publish a post with status '{$result->post_status}'. Allowed source statuses: draft, pending, future.",
            ], 409);
        }

        $wp_result = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
        if (is_wp_error($wp_result)) {
            return new WP_REST_Response(
                ['code' => 'publish_failed', 'message' => $wp_result->get_error_message()],
                500
            );
        }

        return new WP_REST_Response([
            'id'        => $post_id,
            'status'    => 'publish',
            'published' => true,
            'url'       => (string) get_permalink($post_id),
            'edit_url'  => admin_url("post.php?post={$post_id}&action=edit"),
        ], 200);
    }

    public static function handle_unpublish(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $result  = self::resolve($post_id);

        if ($result instanceof WP_REST_Response) {
            return $result;
        }

        if ($result->post_status !== 'publish') {
            return new WP_REST_Response([
                'code'    => 'invalid_status_transition',
                'message' => "Cannot unpublish a post with status '{$result->post_status}'. Post must be published.",
            ], 409);
        }

        $wp_result = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
        if (is_wp_error($wp_result)) {
            return new WP_REST_Response(
                ['code' => 'unpublish_failed', 'message' => $wp_result->get_error_message()],
                500
            );
        }

        return new WP_REST_Response([
            'id'        => $post_id,
            'status'    => 'draft',
            'published' => false,
        ], 200);
    }
}
