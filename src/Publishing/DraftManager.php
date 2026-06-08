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

        $this->apply_elementor_template($post_id);

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

    private function apply_elementor_template(int $post_id): void
    {
        if (!class_exists('\Elementor\Plugin')) {
            return;
        }

        // Return '' from this filter to skip Elementor template assignment entirely.
        $template = (string) apply_filters(
            'trendplot_connector_draft_template',
            'elementor_header_footer'
        );

        if ($template === '') {
            return;
        }

        update_post_meta($post_id, '_wp_page_template', $template);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'wp-post');

        $version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '4.1.1';
        update_post_meta($post_id, '_elementor_version', $version);

        // Wrap the post_content in a boxed Elementor container so Elementor
        // renders from _elementor_data (not raw post_content).
        // Without this, Elementor returns empty output for an empty data array
        // and falls back to post_content, which renders edge-to-edge because
        // the Full Width template has already stripped the theme's width wrapper.
        $html = get_post_field('post_content', $post_id);
        $elementor_data = $this->build_elementor_data($html);
        update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));
    }

    private function build_elementor_data(string $html): string
    {
        $outer_id = $this->elementor_uid();
        $inner_id = $this->elementor_uid();
        $widget_id = $this->elementor_uid();

        $structure = [
            [
                'id'       => $outer_id,
                'elType'   => 'container',
                'settings' => [
                    // 'boxed' uses the site's global Elementor content-width setting
                    // and centres the container — no hardcoded pixel value needed.
                    'content_width'  => 'boxed',
                    'flex_direction' => 'column',
                    'padding'        => [
                        'unit'      => 'px',
                        'top'       => '48',
                        'right'     => '20',
                        'bottom'    => '64',
                        'left'      => '20',
                        'isLinked'  => false,
                    ],
                ],
                'elements' => [
                    [
                        'id'         => $widget_id,
                        'elType'     => 'widget',
                        'widgetType' => 'text-editor',
                        'settings'   => [
                            'editor' => $html,
                        ],
                        'elements'   => [],
                        'isInner'    => false,
                    ],
                ],
                'isInner' => false,
            ],
        ];

        return (string) wp_json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function elementor_uid(): string
    {
        return substr(md5(uniqid((string) mt_rand(), true)), 0, 7);
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
