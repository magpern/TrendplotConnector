<?php
declare(strict_types=1);

namespace TrendplotConnector\Publishing;

use TrendplotConnector\Meta\MetaStore;
use WP_Error;
use WP_Query;

class DraftManager
{
    private const CONTENT_MAX_LENGTH = 200000;

    public function create(array $data): array|WP_Error
    {
        if (empty($data['title'])) {
            return new WP_Error('validation_error', 'Field "title" is required.', ['status' => 400]);
        }
        if (empty($data['content'])) {
            return new WP_Error('validation_error', 'Field "content" is required.', ['status' => 400]);
        }
        if (strlen($data['content']) > self::CONTENT_MAX_LENGTH) {
            return new WP_Error('content_too_large', 'Content exceeds 200,000 character limit.', ['status' => 413]);
        }

        $title   = sanitize_text_field($data['title']);
        $content = wp_kses_post($data['content']);
        $excerpt = wp_strip_all_tags($data['excerpt'] ?? '');

        $categories = array_map('intval', $data['categories'] ?? []);
        $tags        = array_map('intval', $data['tags'] ?? []);

        foreach ($categories as $cat_id) {
            if (!term_exists($cat_id, 'category')) {
                return new WP_Error(
                    'validation_error',
                    "Category ID {$cat_id} does not exist.",
                    ['status' => 400]
                );
            }
        }

        foreach ($tags as $tag_id) {
            if (!term_exists($tag_id, 'post_tag')) {
                return new WP_Error(
                    'validation_error',
                    "Tag ID {$tag_id} does not exist.",
                    ['status' => 400]
                );
            }
        }

        $related_products = array_map('intval', $data['related_products'] ?? []);
        foreach ($related_products as $product_id) {
            if (!$this->is_valid_product($product_id)) {
                return new WP_Error(
                    'validation_error',
                    "Product ID {$product_id} is not a valid WooCommerce product.",
                    ['status' => 400]
                );
            }
        }

        $article_id = sanitize_text_field($data['trendplot_article_id'] ?? '');

        if ($article_id !== '') {
            $existing = $this->find_by_article_id($article_id);
            if ($existing !== null) {
                return new WP_Error(
                    'duplicate_article_id',
                    "A draft with trendplot_article_id \"{$article_id}\" already exists (post ID {$existing}).",
                    ['status' => 409, 'existing_id' => $existing]
                );
            }
        }

        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ], true);

        if (is_wp_error($post_id)) {
            return new WP_Error('creation_failed', $post_id->get_error_message(), ['status' => 500]);
        }

        if ($categories) {
            wp_set_post_categories($post_id, $categories);
        }
        if ($tags) {
            wp_set_post_tags($post_id, $tags);
        }

        $meta_fields = [];
        if ($article_id !== '') {
            $meta_fields['_trendplot_article_id'] = $article_id;
        }
        if (!empty($data['trendplot_generated'])) {
            $meta_fields['_trendplot_generated'] = sanitize_text_field($data['trendplot_generated']);
        }
        if (!empty($data['trendplot_source'])) {
            $meta_fields['_trendplot_source'] = sanitize_text_field($data['trendplot_source']);
        }
        if ($related_products) {
            $meta_fields['_trendplot_related_products'] = $related_products;
        }
        $related_articles = array_map('intval', $data['related_articles'] ?? []);
        if ($related_articles) {
            $meta_fields['_trendplot_related_articles'] = $related_articles;
        }

        if ($meta_fields) {
            $meta_result = MetaStore::write($post_id, $meta_fields);
            if (is_wp_error($meta_result)) {
                return $meta_result;
            }
        }

        $post = get_post($post_id);

        return [
            'id'                  => $post_id,
            'title'               => $title,
            'slug'                => $post->post_name,
            'status'              => 'draft',
            'url'                 => get_permalink($post_id),
            'edit_url'            => admin_url("post.php?post={$post_id}&action=edit"),
            'created_at'          => get_the_date('c', $post_id),
            'trendplot_article_id' => $article_id ?: null,
        ];
    }

    private function find_by_article_id(string $article_id): ?int
    {
        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => ['draft', 'pending', 'future', 'publish', 'private'],
            'meta_key'       => '_trendplot_article_id',
            'meta_value'     => $article_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $ids = $query->posts;
        return !empty($ids) ? (int) $ids[0] : null;
    }

    private function is_valid_product(int $product_id): bool
    {
        if (function_exists('wc_get_product')) {
            return (bool) wc_get_product($product_id);
        }
        $post = get_post($product_id);
        return $post !== null && $post->post_type === 'product';
    }
}
