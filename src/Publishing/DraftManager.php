<?php
declare(strict_types=1);

namespace TrendplotConnector\Publishing;

use TrendplotConnector\Meta\MetaStore;
use TrendplotConnector\Seo\SeoManager;
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
        $slug    = (!empty($data['slug']) && is_string($data['slug'])) ? sanitize_title($data['slug']) : '';

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

        $clean_seo = null;
        if (array_key_exists('seo', $data)) {
            if (!is_array($data['seo'])) {
                return new WP_Error('validation_error', 'Field "seo" must be an object.', ['status' => 400]);
            }
            $clean_seo = SeoManager::validate($data['seo']);
            if (is_wp_error($clean_seo)) {
                return $clean_seo;
            }
        }

        $insert_args = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ];
        if ($slug !== '') {
            $insert_args['post_name'] = $slug;
        }
        $post_id = wp_insert_post($insert_args, true);

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

        $seo_status = 'none';
        if ($clean_seo !== null) {
            if (SeoManager::is_active()) {
                SeoManager::write($post_id, $clean_seo);
                $seo_status = 'written';
            } else {
                $seo_status = 'skipped';
            }
        }

        $post = get_post($post_id);

        return [
            'id'                   => $post_id,
            'title'                => $title,
            'slug'                 => $post->post_name,
            'status'               => 'draft',
            'url'                  => get_permalink($post_id),
            'edit_url'             => admin_url("post.php?post={$post_id}&action=edit"),
            'created_at'           => get_the_date('c', $post_id),
            'trendplot_article_id' => $article_id ?: null,
            'seo_status'           => $seo_status,
        ];
    }

    public function update(int $post_id, array $data): array|WP_Error
    {
        // --- pre-flight checks ---

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', "Post ID {$post_id} does not exist.", ['status' => 404]);
        }

        $existing_article_id = (string) get_post_meta($post_id, '_trendplot_article_id', true);
        if ($existing_article_id === '') {
            return new WP_Error(
                'not_trendplot_draft',
                "Post ID {$post_id} is not a Trendplot-created draft.",
                ['status' => 403]
            );
        }

        if ($post->post_status === 'publish' || $post->post_status === 'private') {
            return new WP_Error(
                'published_post_rejected',
                "Cannot update a post with status '{$post->post_status}'.",
                ['status' => 409]
            );
        }

        $allowed_statuses = ['draft', 'pending', 'future'];
        if (!in_array($post->post_status, $allowed_statuses, true)) {
            return new WP_Error(
                'invalid_post_status',
                "Cannot update a post with status '{$post->post_status}'.",
                ['status' => 409]
            );
        }

        // --- ownership / identity ---

        if (array_key_exists('trendplot_article_id', $data)) {
            $supplied = sanitize_text_field($data['trendplot_article_id']);
            if ($supplied !== $existing_article_id) {
                return new WP_Error(
                    'article_id_mismatch',
                    "Supplied trendplot_article_id \"{$supplied}\" does not match the existing \"{$existing_article_id}\" on post {$post_id}.",
                    ['status' => 409]
                );
            }
        }

        // --- parse and validate fields ---

        if (isset($data['content']) && strlen($data['content']) > self::CONTENT_MAX_LENGTH) {
            return new WP_Error('content_too_large', 'Content exceeds 200,000 character limit.', ['status' => 413]);
        }

        $categories = null;
        if (array_key_exists('categories', $data)) {
            $categories = array_map('intval', (array) $data['categories']);
            foreach ($categories as $cat_id) {
                if (!term_exists($cat_id, 'category')) {
                    return new WP_Error('validation_error', "Category ID {$cat_id} does not exist.", ['status' => 400]);
                }
            }
        }

        $tags = null;
        if (array_key_exists('tags', $data)) {
            $tags = array_map('intval', (array) $data['tags']);
            foreach ($tags as $tag_id) {
                if (!term_exists($tag_id, 'post_tag')) {
                    return new WP_Error('validation_error', "Tag ID {$tag_id} does not exist.", ['status' => 400]);
                }
            }
        }

        $related_products = null;
        if (array_key_exists('related_products', $data)) {
            $related_products = array_map('intval', (array) $data['related_products']);
            foreach ($related_products as $product_id) {
                if (!$this->is_valid_product($product_id)) {
                    return new WP_Error(
                        'validation_error',
                        "Product ID {$product_id} is not a valid WooCommerce product.",
                        ['status' => 400]
                    );
                }
            }
        }

        $related_articles = null;
        if (array_key_exists('related_articles', $data)) {
            $related_articles = array_map('intval', (array) $data['related_articles']);
            foreach ($related_articles as $ref_id) {
                $ref = get_post($ref_id);
                if (!$ref || !in_array($ref->post_type, ['post', 'page'], true)) {
                    return new WP_Error(
                        'validation_error',
                        "Article ID {$ref_id} is not a valid post or page.",
                        ['status' => 400]
                    );
                }
            }
        }

        $clean_seo = null;
        if (array_key_exists('seo', $data)) {
            if (!is_array($data['seo'])) {
                return new WP_Error('validation_error', 'Field "seo" must be an object.', ['status' => 400]);
            }
            $clean_seo = SeoManager::validate($data['seo']);
            if (is_wp_error($clean_seo)) {
                return $clean_seo;
            }
        }

        // at least one updateable field must be present
        $has_post_fields = isset($data['title']) || isset($data['content']) || isset($data['excerpt']) || isset($data['slug']);
        $has_taxonomy    = $categories !== null || $tags !== null;
        $has_meta        = $related_products !== null || $related_articles !== null
            || isset($data['trendplot_source'])
            || isset($data['trendplot_generated'])
            || isset($data['trendplot_last_sync']);
        $has_seo         = $clean_seo !== null && count($clean_seo) > 0;

        if (!$has_post_fields && !$has_taxonomy && !$has_meta && !$has_seo) {
            return new WP_Error(
                'validation_error',
                'Request body must contain at least one updateable field.',
                ['status' => 400]
            );
        }

        // --- apply post field updates ---

        $update_args = ['ID' => $post_id];
        $content_changed = false;

        if (isset($data['title'])) {
            $update_args['post_title'] = sanitize_text_field($data['title']);
        }
        if (isset($data['content'])) {
            $update_args['post_content'] = wp_kses_post($data['content']);
            $content_changed = true;
        }
        if (isset($data['excerpt'])) {
            $update_args['post_excerpt'] = wp_strip_all_tags($data['excerpt']);
        }
        if (isset($data['slug']) && is_string($data['slug']) && $data['slug'] !== '') {
            $update_args['post_name'] = sanitize_title($data['slug']);
        }

        if (count($update_args) > 1) {
            $wp_result = wp_update_post($update_args, true);
            if (is_wp_error($wp_result)) {
                return new WP_Error('update_failed', $wp_result->get_error_message(), ['status' => 500]);
            }
        }

        // --- apply taxonomy updates ---

        if ($categories !== null) {
            wp_set_post_categories($post_id, $categories);
        }
        if ($tags !== null) {
            wp_set_post_tags($post_id, $tags);
        }

        // --- apply meta updates ---

        $meta_fields = [];
        if (!empty($data['trendplot_source'])) {
            $meta_fields['_trendplot_source'] = sanitize_text_field($data['trendplot_source']);
        }
        if (!empty($data['trendplot_generated'])) {
            $meta_fields['_trendplot_generated'] = sanitize_text_field($data['trendplot_generated']);
        }
        if ($related_products !== null) {
            $meta_fields['_trendplot_related_products'] = $related_products;
        }
        if ($related_articles !== null) {
            $meta_fields['_trendplot_related_articles'] = $related_articles;
        }
        // always stamp last_sync unless the caller explicitly provides a value
        $meta_fields['_trendplot_last_sync'] = isset($data['trendplot_last_sync'])
            ? sanitize_text_field($data['trendplot_last_sync'])
            : gmdate('c');

        $meta_result = MetaStore::write($post_id, $meta_fields);
        if (is_wp_error($meta_result)) {
            return $meta_result;
        }

        // --- rebuild Elementor data when content changed ---

        if ($content_changed) {
            $this->apply_elementor_template($post_id);
        }

        $seo_status = 'none';
        if ($clean_seo !== null) {
            if (SeoManager::is_active()) {
                SeoManager::write($post_id, $clean_seo);
                $seo_status = 'written';
            } else {
                $seo_status = 'skipped';
            }
        }

        // --- build response ---

        $updated_post = get_post($post_id);
        $meta         = MetaStore::read($post_id);

        return [
            'id'                   => $post_id,
            'title'                => $updated_post->post_title,
            'slug'                 => $updated_post->post_name,
            'status'               => $updated_post->post_status,
            'url'                  => get_permalink($post_id),
            'edit_url'             => admin_url("post.php?post={$post_id}&action=edit"),
            'modified_at'          => get_the_modified_date('c', $post_id),
            'trendplot_article_id' => $meta['_trendplot_article_id'],
            'updated'              => true,
            'seo_status'           => $seo_status,
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
