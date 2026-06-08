<?php
declare(strict_types=1);

namespace TrendplotConnector\RelatedArticles;

use WP_Query;

class RelatedArticlesBlock
{
    private const TRANSIENT_PREFIX = 'trendplot_rel_arts_';
    private const TRANSIENT_TTL    = HOUR_IN_SECONDS;

    public static function register_hooks(): void
    {
        if (!function_exists('WC')) {
            return;
        }

        $settings = get_option('trendplot_connector_settings', []);

        if (empty($settings['related_articles_enabled'])) {
            return;
        }

        $placement = $settings['related_articles_placement'] ?? 'after_tabs';

        switch ($placement) {
            case 'after_short_description':
                add_action('woocommerce_single_product_summary', [self::class, 'render'], 21);
                break;
            case 'after_meta':
                add_action('woocommerce_single_product_summary', [self::class, 'render'], 45);
                break;
            case 'after_tabs':
            default:
                // Tabs render at woocommerce_after_single_product_summary priority 10.
                // Priority 11 places the block immediately after the tabs, before upsells (15).
                add_action('woocommerce_after_single_product_summary', [self::class, 'render'], 11);
                break;
        }

        // Clear per-product cache when Trendplot meta is written to any post.
        add_action('updated_post_meta', [self::class, 'maybe_clear_cache'], 10, 4);
        add_action('added_post_meta',   [self::class, 'maybe_clear_cache'], 10, 4);
    }

    public static function render(): void
    {
        if (!is_product()) {
            return;
        }

        $product_id = get_the_ID();
        if (!$product_id) {
            return;
        }

        $articles = self::get_related_articles($product_id);
        if (empty($articles)) {
            return;
        }

        $title = (string) apply_filters(
            'trendplot_related_articles_title',
            __('Related Research Articles', 'trendplot-connector')
        );

        echo '<section class="trendplot-related-articles">';
        echo '<h2 class="trendplot-related-articles__title">' . esc_html($title) . '</h2>';
        echo '<ul class="trendplot-related-articles__list">';
        foreach ($articles as $article) {
            echo '<li class="trendplot-related-articles__item">';
            echo '<a class="trendplot-related-articles__link" href="' . esc_url($article['url']) . '">';
            echo esc_html($article['title']);
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    private static function get_related_articles(int $product_id): array
    {
        $cache_key = self::TRANSIENT_PREFIX . $product_id;
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $settings = get_option('trendplot_connector_settings', []);
        $limit    = max(1, min(20, (int) ($settings['related_articles_limit'] ?? 5)));

        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => [[
                'key'     => '_trendplot_related_products',
                // serialize(int) produces e.g. "i:101;" which is the exact token
                // WordPress stores inside the PHP-serialised array value.
                'value'   => serialize($product_id),
                'compare' => 'LIKE',
            ]],
        ]);

        $articles = [];
        foreach ($query->posts as $post) {
            $articles[] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'url'   => get_permalink($post->ID),
            ];
        }

        set_transient($cache_key, $articles, self::TRANSIENT_TTL);

        return $articles;
    }

    /**
     * Clear the per-product transient cache when _trendplot_related_products
     * is written on any post, so new associations appear within one request.
     */
    public static function maybe_clear_cache(int $meta_id, int $post_id, string $meta_key, mixed $meta_value): void
    {
        if ($meta_key !== '_trendplot_related_products') {
            return;
        }

        $ids = maybe_unserialize($meta_value);
        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $product_id) {
            delete_transient(self::TRANSIENT_PREFIX . (int) $product_id);
        }
    }
}
