<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use TrendplotConnector\Meta\MetaStore;
use TrendplotConnector\Publishing\DraftManager;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

class DraftsEndpoint
{
    public static function handle_get(WP_REST_Request $request): WP_REST_Response
    {
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));

        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => ['draft', 'pending', 'future'],
            'meta_key'       => '_trendplot_article_id',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $meta = MetaStore::read($post->ID);
            $items[] = [
                'id'                   => $post->ID,
                'title'                => $post->post_title,
                'slug'                 => $post->post_name,
                'url'                  => get_permalink($post->ID),
                'status'               => $post->post_status,
                'created_at'           => get_the_date('c', $post->ID),
                'modified_at'          => get_the_modified_date('c', $post->ID),
                'trendplot_article_id' => $meta['_trendplot_article_id'],
                'trendplot_generated'  => $meta['_trendplot_generated'],
                'trendplot_source'     => $meta['_trendplot_source'],
                'trendplot_last_sync'  => $meta['_trendplot_last_sync'],
                'related_products'     => $meta['_trendplot_related_products'],
                'related_articles'     => $meta['_trendplot_related_articles'],
            ];
        }

        $total       = (int) $query->found_posts;
        $total_pages = (int) $query->max_num_pages;

        return new WP_REST_Response([
            'items'       => $items,
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
        ], 200);
    }

    public static function handle_post(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        if (!is_array($body)) {
            return new WP_REST_Response(
                ['code' => 'invalid_body', 'message' => 'Request body must be a JSON object.'],
                400
            );
        }

        $manager = new DraftManager();
        $result  = $manager->create($body);

        if (is_wp_error($result)) {
            $status = (int) ($result->get_error_data()['status'] ?? 400);
            $data   = ['code' => $result->get_error_code(), 'message' => $result->get_error_message()];
            if (isset($result->get_error_data()['existing_id'])) {
                $data['existing_id'] = $result->get_error_data()['existing_id'];
            }
            return new WP_REST_Response($data, $status);
        }

        return new WP_REST_Response($result, 201);
    }
}
