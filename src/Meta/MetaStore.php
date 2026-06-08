<?php
declare(strict_types=1);

namespace TrendplotConnector\Meta;

use WP_Error;
use WP_Query;

class MetaStore
{
    private const ALLOWED_KEYS = [
        '_trendplot_article_id',
        '_trendplot_generated',
        '_trendplot_source',
        '_trendplot_last_sync',
        '_trendplot_related_products',
        '_trendplot_related_articles',
    ];

    private const ARRAY_KEYS = [
        '_trendplot_related_products',
        '_trendplot_related_articles',
    ];

    public static function write(int $post_id, array $fields): bool|WP_Error
    {
        foreach ($fields as $key => $value) {
            $meta_key = str_starts_with($key, '_trendplot_') ? $key : '_trendplot_' . $key;
            if (!in_array($meta_key, self::ALLOWED_KEYS, true)) {
                return new WP_Error(
                    'invalid_meta_key',
                    "Meta key '{$key}' is not in the Trendplot allowed keys list.",
                    ['status' => 400]
                );
            }
            update_post_meta($post_id, $meta_key, $value);
        }
        return true;
    }

    public static function read(int $post_id): array
    {
        $result = [];
        foreach (self::ALLOWED_KEYS as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (in_array($key, self::ARRAY_KEYS, true)) {
                $result[$key] = is_array($value) ? array_map('intval', $value) : [];
            } else {
                $result[$key] = $value !== '' ? $value : null;
            }
        }
        return $result;
    }

    public static function count_trendplot_posts(): int
    {
        // Count only post_type=post to match the Content admin screen scope.
        // WooCommerce products tagged via PATCH /posts/{id}/meta are excluded —
        // they are product-article associations, not managed articles.
        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => ['draft', 'pending', 'future', 'publish'],
            'meta_key'       => '_trendplot_article_id',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);
        return (int) $query->found_posts;
    }

    public static function allowed_keys(): array
    {
        return self::ALLOWED_KEYS;
    }
}
