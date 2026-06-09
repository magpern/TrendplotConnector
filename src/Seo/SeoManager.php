<?php
declare(strict_types=1);

namespace TrendplotConnector\Seo;

use WP_Error;

class SeoManager
{
    private const ALLOWED_INPUT_KEYS = [
        'title', 'description', 'focus_keyword', 'canonical_url', 'robots', 'schema_type',
    ];

    private const ALLOWED_ROBOTS = [
        'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'noodp',
    ];

    private const ALLOWED_SCHEMA_TYPES = [
        'off', 'Article', 'NewsArticle', 'BlogPosting',
    ];

    public static function is_active(): bool
    {
        return class_exists('RankMath');
    }

    /**
     * Validate the raw seo array from the request body.
     * Returns a cleaned seo array or WP_Error on failure.
     * Unknown keys return 400 immediately — the connector is an API contract.
     */
    public static function validate(array $seo): array|WP_Error
    {
        $allowed_list = implode(', ', self::ALLOWED_INPUT_KEYS);

        foreach (array_keys($seo) as $key) {
            if (!in_array($key, self::ALLOWED_INPUT_KEYS, true)) {
                return new WP_Error(
                    'validation_error',
                    "Unknown SEO field: \"{$key}\". Allowed fields: {$allowed_list}.",
                    ['status' => 400]
                );
            }
        }

        $clean = [];

        if (array_key_exists('title', $seo)) {
            if (!is_string($seo['title'])) {
                return new WP_Error('validation_error', 'SEO field "title" must be a string.', ['status' => 400]);
            }
            if (strlen($seo['title']) > 300) {
                return new WP_Error('validation_error', 'SEO field "title" must not exceed 300 characters.', ['status' => 400]);
            }
            $clean['title'] = sanitize_text_field($seo['title']);
        }

        if (array_key_exists('description', $seo)) {
            if (!is_string($seo['description'])) {
                return new WP_Error('validation_error', 'SEO field "description" must be a string.', ['status' => 400]);
            }
            if (strlen($seo['description']) > 500) {
                return new WP_Error('validation_error', 'SEO field "description" must not exceed 500 characters.', ['status' => 400]);
            }
            $clean['description'] = sanitize_text_field($seo['description']);
        }

        if (array_key_exists('focus_keyword', $seo)) {
            if (!is_string($seo['focus_keyword'])) {
                return new WP_Error('validation_error', 'SEO field "focus_keyword" must be a string.', ['status' => 400]);
            }
            if (strlen($seo['focus_keyword']) > 300) {
                return new WP_Error('validation_error', 'SEO field "focus_keyword" must not exceed 300 characters.', ['status' => 400]);
            }
            $clean['focus_keyword'] = sanitize_text_field($seo['focus_keyword']);
        }

        if (array_key_exists('canonical_url', $seo)) {
            if (!is_string($seo['canonical_url'])) {
                return new WP_Error('validation_error', 'SEO field "canonical_url" must be a string.', ['status' => 400]);
            }
            if ($seo['canonical_url'] !== '' && !filter_var($seo['canonical_url'], FILTER_VALIDATE_URL)) {
                return new WP_Error(
                    'validation_error',
                    'SEO field "canonical_url" must be a valid URL or an empty string.',
                    ['status' => 400]
                );
            }
            $clean['canonical_url'] = $seo['canonical_url'] !== '' ? esc_url_raw($seo['canonical_url']) : '';
        }

        if (array_key_exists('robots', $seo)) {
            if (!is_array($seo['robots'])) {
                return new WP_Error('validation_error', 'SEO field "robots" must be an array.', ['status' => 400]);
            }
            foreach ($seo['robots'] as $directive) {
                if (!in_array($directive, self::ALLOWED_ROBOTS, true)) {
                    $allowed = implode(', ', self::ALLOWED_ROBOTS);
                    return new WP_Error(
                        'validation_error',
                        "Unknown robots directive: \"{$directive}\". Allowed values: {$allowed}.",
                        ['status' => 400]
                    );
                }
            }
            $clean['robots'] = array_values($seo['robots']);
        }

        if (array_key_exists('schema_type', $seo)) {
            if (!is_string($seo['schema_type'])) {
                return new WP_Error('validation_error', 'SEO field "schema_type" must be a string.', ['status' => 400]);
            }
            if (!in_array($seo['schema_type'], self::ALLOWED_SCHEMA_TYPES, true)) {
                $allowed = implode(', ', self::ALLOWED_SCHEMA_TYPES);
                return new WP_Error(
                    'validation_error',
                    "Unknown schema_type: \"{$seo['schema_type']}\". Allowed values: {$allowed}.",
                    ['status' => 400]
                );
            }
            $clean['schema_type'] = $seo['schema_type'];
        }

        return $clean;
    }

    /**
     * Write validated seo fields to Rank Math post meta.
     * Only touches keys present in $seo. Empty string / empty array deletes the meta.
     */
    public static function write(int $post_id, array $seo): bool|WP_Error
    {
        $scalar_map = [
            'title'         => 'rank_math_title',
            'description'   => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical_url' => 'rank_math_canonical_url',
            'schema_type'   => 'rank_math_rich_snippet',
        ];

        foreach ($scalar_map as $input_key => $meta_key) {
            if (!array_key_exists($input_key, $seo)) {
                continue;
            }
            if ($seo[$input_key] === '') {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $seo[$input_key]);
            }
        }

        if (array_key_exists('robots', $seo)) {
            if (empty($seo['robots'])) {
                delete_post_meta($post_id, 'rank_math_robots');
            } else {
                // Rank Math serializes robots as a PHP array, e.g. a:1:{i:0;s:7:"noindex";}
                update_post_meta($post_id, 'rank_math_robots', serialize($seo['robots']));
            }
        }

        return true;
    }

    /**
     * Read current Rank Math meta for a post.
     *
     * Returns SEO fields plus `score` (integer 0–100 or null if not yet scored).
     * Note: the per-post analysis breakdown (errors/warnings/passed) is computed
     * entirely in the Rank Math editor's JavaScript and is never persisted to the
     * database, so it cannot be read back server-side.
     */
    public static function read(int $post_id): array
    {
        $scalar_map = [
            'title'         => 'rank_math_title',
            'description'   => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical_url' => 'rank_math_canonical_url',
            'schema_type'   => 'rank_math_rich_snippet',
        ];

        $result = [];
        foreach ($scalar_map as $output_key => $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            $result[$output_key] = ($value !== '' && $value !== false) ? (string) $value : null;
        }

        $robots_raw = get_post_meta($post_id, 'rank_math_robots', true);
        if ($robots_raw === '' || $robots_raw === false) {
            $result['robots'] = [];
        } else {
            $unserialized = @unserialize((string) $robots_raw);
            $result['robots'] = is_array($unserialized)
                ? array_values(array_filter($unserialized, 'is_string'))
                : [];
        }

        $score_raw      = get_post_meta($post_id, 'rank_math_seo_score', true);
        $result['score'] = ($score_raw !== '' && $score_raw !== false) ? (int) $score_raw : null;

        return $result;
    }
}
