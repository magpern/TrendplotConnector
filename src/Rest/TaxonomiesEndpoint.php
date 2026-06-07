<?php
declare(strict_types=1);

namespace TrendplotConnector\Rest;

use WP_REST_Request;
use WP_REST_Response;

class TaxonomiesEndpoint
{
    public static function handle_categories(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(self::get_terms('category'), 200);
    }

    public static function handle_tags(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(self::get_terms('post_tag'), 200);
    }

    private static function get_terms(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        return array_map(fn ($term) => [
            'id'    => (int) $term->term_id,
            'name'  => $term->name,
            'slug'  => $term->slug,
            'count' => (int) $term->count,
        ], $terms);
    }
}
