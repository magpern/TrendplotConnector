<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use TrendplotConnector\Meta\MetaStore;
use WP_REST_Request;
use WP_REST_Response;

class MetaEndpoint
{
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!$post) {
            return new WP_REST_Response(
                ['code' => 'not_found', 'message' => "Post ID {$post_id} does not exist."],
                404
            );
        }

        $body = $request->get_json_params();
        if (!is_array($body) || empty($body)) {
            return new WP_REST_Response(
                ['code' => 'invalid_body', 'message' => 'Request body must be a non-empty JSON object.'],
                400
            );
        }

        $allowed_keys = MetaStore::allowed_keys();

        $fields_to_write = [];
        foreach ($body as $key => $value) {
            if (str_starts_with($key, '_trendplot_')) {
                $meta_key = $key;
            } elseif (str_starts_with($key, 'trendplot_')) {
                $meta_key = '_' . $key;
            } else {
                $meta_key = '_trendplot_' . $key;
            }
            if (!in_array($meta_key, $allowed_keys, true)) {
                return new WP_REST_Response(
                    ['code' => 'invalid_meta_key', 'message' => "Key '{$key}' is not in the Trendplot allowed keys list."],
                    400
                );
            }
            $fields_to_write[$meta_key] = $value;
        }

        $related_products = $fields_to_write['_trendplot_related_products'] ?? null;
        if ($related_products !== null) {
            $related_products = array_map('intval', (array) $related_products);
            foreach ($related_products as $product_id) {
                if (!self::is_valid_product($product_id)) {
                    return new WP_REST_Response(
                        ['code' => 'invalid_product_id', 'message' => "Product ID {$product_id} is not a valid WooCommerce product."],
                        400
                    );
                }
            }
            $fields_to_write['_trendplot_related_products'] = $related_products;
        }

        $related_articles = $fields_to_write['_trendplot_related_articles'] ?? null;
        if ($related_articles !== null) {
            $related_articles = array_map('intval', (array) $related_articles);
            foreach ($related_articles as $article_id) {
                $ref_post = get_post($article_id);
                if (!$ref_post || !in_array($ref_post->post_type, ['post', 'page'], true)) {
                    return new WP_REST_Response(
                        ['code' => 'invalid_article_id', 'message' => "Article ID {$article_id} is not a valid post or page."],
                        400
                    );
                }
            }
            $fields_to_write['_trendplot_related_articles'] = $related_articles;
        }

        $result = MetaStore::write($post_id, $fields_to_write);
        if (is_wp_error($result)) {
            return new WP_REST_Response(
                ['code' => $result->get_error_code(), 'message' => $result->get_error_message()],
                (int) ($result->get_error_data()['status'] ?? 400)
            );
        }

        $meta = MetaStore::read($post_id);

        return new WP_REST_Response([
            'id'                   => $post_id,
            'post_type'            => $post->post_type,
            'trendplot_article_id' => $meta['_trendplot_article_id'],
            'trendplot_generated'  => $meta['_trendplot_generated'],
            'trendplot_source'     => $meta['_trendplot_source'],
            'trendplot_last_sync'  => $meta['_trendplot_last_sync'],
            'related_products'     => $meta['_trendplot_related_products'],
            'related_articles'     => $meta['_trendplot_related_articles'],
            'updated_at'           => gmdate('c'),
        ], 200);
    }

    private static function is_valid_product(int $product_id): bool
    {
        if (function_exists('wc_get_product')) {
            return (bool) wc_get_product($product_id);
        }
        $post = get_post($product_id);
        return $post !== null && $post->post_type === 'product';
    }
}
