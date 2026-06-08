<?php
declare(strict_types=1);

namespace TrendplotConnector\Admin;

use TrendplotConnector\Meta\MetaStore;

class SettingsPage
{
    private const OPTION_NAME = 'trendplot_connector_settings';
    private const PAGE_SLUG   = 'trendplot-connector';

    public static function register_menu(): void
    {
        add_options_page(
            'Trendplot Connector',
            'Trendplot Connector',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(
            'trendplot_connector_options',
            self::OPTION_NAME,
            ['sanitize_callback' => [self::class, 'sanitize_settings']]
        );

        add_settings_section(
            'trendplot_main',
            'API Settings',
            null,
            self::PAGE_SLUG
        );

        add_settings_field(
            'enabled',
            'Enable Connector',
            [self::class, 'field_enabled'],
            self::PAGE_SLUG,
            'trendplot_main'
        );
        add_settings_field(
            'site_id',
            'Site ID',
            [self::class, 'field_site_id'],
            self::PAGE_SLUG,
            'trendplot_main'
        );
        add_settings_field(
            'shared_secret',
            'Shared Secret',
            [self::class, 'field_shared_secret'],
            self::PAGE_SLUG,
            'trendplot_main'
        );
        add_settings_field(
            'allowed_origin',
            'Allowed Origin',
            [self::class, 'field_allowed_origin'],
            self::PAGE_SLUG,
            'trendplot_main'
        );

        add_settings_section(
            'trendplot_related_articles',
            'Related Research Articles',
            [self::class, 'section_related_articles'],
            self::PAGE_SLUG
        );

        add_settings_field(
            'related_articles_enabled',
            'Enable Block',
            [self::class, 'field_related_articles_enabled'],
            self::PAGE_SLUG,
            'trendplot_related_articles'
        );
        add_settings_field(
            'related_articles_limit',
            'Maximum Articles',
            [self::class, 'field_related_articles_limit'],
            self::PAGE_SLUG,
            'trendplot_related_articles'
        );
        add_settings_field(
            'related_articles_placement',
            'Placement',
            [self::class, 'field_related_articles_placement'],
            self::PAGE_SLUG,
            'trendplot_related_articles'
        );
    }

    public static function handle_generate_secret(): void
    {
        if (!isset($_POST['trendplot_action']) || $_POST['trendplot_action'] !== 'generate_secret') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('trendplot_generate_secret_nonce');

        $settings = get_option(self::OPTION_NAME, []);
        $new_secret = wp_generate_password(64, false);
        $settings['shared_secret'] = $new_secret;
        update_option(self::OPTION_NAME, $settings);
        set_transient('trendplot_new_secret_' . get_current_user_id(), $new_secret, 300);

        wp_redirect(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&secret_generated=1'));
        exit;
    }

    public static function sanitize_settings(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $existing = get_option(self::OPTION_NAME, []);
        $clean    = [];

        $clean['enabled']        = !empty($input['enabled']) ? '1' : '0';
        $clean['site_id']        = sanitize_text_field($input['site_id'] ?? '');
        $clean['allowed_origin'] = esc_url_raw($input['allowed_origin'] ?? '');

        $submitted_secret = $input['shared_secret'] ?? '';
        $clean['shared_secret'] = $submitted_secret !== ''
            ? sanitize_text_field($submitted_secret)
            : ($existing['shared_secret'] ?? '');

        $clean['related_articles_enabled']   = !empty($input['related_articles_enabled']) ? '1' : '0';
        $limit = (int) ($input['related_articles_limit'] ?? 5);
        $clean['related_articles_limit']     = (string) max(1, min(20, $limit));
        $allowed_placements = ['after_short_description', 'after_meta', 'after_tabs'];
        $placement = $input['related_articles_placement'] ?? 'after_tabs';
        $clean['related_articles_placement'] = in_array($placement, $allowed_placements, true)
            ? $placement
            : 'after_tabs';

        return $clean;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings     = get_option(self::OPTION_NAME, []);
        $draft_count  = MetaStore::count_trendplot_posts();
        $health_url   = esc_url(rest_url('trendplot/v1/health'));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php
            $reveal_secret = false;
            if (isset($_GET['secret_generated'])) {
                $transient_key = 'trendplot_new_secret_' . get_current_user_id();
                $reveal_secret = get_transient($transient_key);
                if ($reveal_secret) {
                    delete_transient($transient_key);
                }
            }
            ?>
            <?php if ($reveal_secret) : ?>
                <div class="notice notice-warning" style="padding: 12px 16px 16px;">
                    <p><strong>New secret generated.</strong> Copy it now — this is the only time it will be shown.</p>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:6px;">
                        <input type="text"
                               id="trendplot-reveal-secret"
                               value="<?php echo esc_attr($reveal_secret); ?>"
                               readonly
                               style="width:520px;font-family:monospace;font-size:13px;" />
                        <button type="button"
                                class="button"
                                onclick="
                                    var el = document.getElementById('trendplot-reveal-secret');
                                    el.select();
                                    navigator.clipboard.writeText(el.value).then(function() {
                                        this.textContent = 'Copied!';
                                        setTimeout(function(btn) { btn.textContent = 'Copy'; }, 2000, this);
                                    }.bind(this));
                                ">Copy</button>
                    </div>
                </div>
            <?php elseif (isset($_GET['secret_generated'])) : ?>
                <div class="notice notice-success"><p>New shared secret generated and saved.</p></div>
            <?php endif; ?>

            <p>Trendplot-managed posts: <strong><?php echo esc_html((string) $draft_count); ?></strong></p>

            <form method="post" action="options.php">
                <?php
                settings_fields('trendplot_connector_options');
                do_settings_sections(self::PAGE_SLUG);
                submit_button('Save Settings');
                ?>
            </form>

            <hr>
            <h2>Generate Secret</h2>
            <p>Creates a new cryptographically random 64-character shared secret and saves it immediately.</p>
            <form method="post" action="">
                <?php wp_nonce_field('trendplot_generate_secret_nonce'); ?>
                <input type="hidden" name="trendplot_action" value="generate_secret" />
                <?php submit_button('Generate New Secret', 'secondary', 'submit', false); ?>
            </form>

            <hr>
            <h2>Test Connection</h2>
            <a href="<?php echo $health_url; ?>" target="_blank" class="button">
                Open /health endpoint
            </a>
        </div>
        <?php
    }

    public static function field_enabled(): void
    {
        $settings = get_option(self::OPTION_NAME, []);
        $checked  = !empty($settings['enabled']) ? 'checked' : '';
        echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[enabled]" value="1" ' . $checked . ' />';
        echo '<label> Enable the Trendplot REST API</label>';
    }

    public static function field_site_id(): void
    {
        $settings = get_option(self::OPTION_NAME, []);
        $value    = esc_attr($settings['site_id'] ?? '');
        echo '<input type="text" name="' . esc_attr(self::OPTION_NAME) . '[site_id]" value="' . $value . '" class="regular-text" />';
        echo '<p class="description">Unique identifier for this site, must match what Trendplot sends in X-Trendplot-Site-Id.</p>';
    }

    public static function field_shared_secret(): void
    {
        $settings = get_option(self::OPTION_NAME, []);
        $value    = esc_attr($settings['shared_secret'] ?? '');
        echo '<input type="password" name="' . esc_attr(self::OPTION_NAME) . '[shared_secret]" value="' . $value . '" class="regular-text" autocomplete="new-password" />';
        echo '<p class="description">HMAC-SHA256 signing secret. Use the Generate button below or enter manually.</p>';
    }

    public static function field_allowed_origin(): void
    {
        $settings = get_option(self::OPTION_NAME, []);
        $value    = esc_attr($settings['allowed_origin'] ?? '');
        echo '<input type="url" name="' . esc_attr(self::OPTION_NAME) . '[allowed_origin]" value="' . $value . '" class="regular-text" placeholder="https://app.trendplot.io" />';
        echo '<p class="description">Base URL of the Trendplot service. Reserved for future CORS enforcement.</p>';
    }

    public static function section_related_articles(): void
    {
        echo '<p>Displays a list of Trendplot-created articles linked to a product on WooCommerce product pages.</p>';
    }

    public static function field_related_articles_enabled(): void
    {
        $settings = get_option(self::OPTION_NAME, []);
        $checked  = ($settings['related_articles_enabled'] ?? '1') === '1' ? 'checked' : '';
        echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[related_articles_enabled]" value="1" ' . $checked . ' />';
        echo '<label> Show Related Research Articles block on product pages</label>';
    }

    public static function field_related_articles_limit(): void
    {
        $settings = get_option(self::OPTION_NAME, []);
        $value    = esc_attr((string) ($settings['related_articles_limit'] ?? '5'));
        echo '<input type="number" name="' . esc_attr(self::OPTION_NAME) . '[related_articles_limit]" value="' . $value . '" min="1" max="20" style="width:70px;" />';
        echo '<p class="description">Maximum articles to show per product (1–20). Default: 5.</p>';
    }

    public static function field_related_articles_placement(): void
    {
        $settings  = get_option(self::OPTION_NAME, []);
        $current   = $settings['related_articles_placement'] ?? 'after_tabs';
        $name      = esc_attr(self::OPTION_NAME) . '[related_articles_placement]';
        $options   = [
            'after_short_description' => 'After short description',
            'after_meta'              => 'After product meta',
            'after_tabs'              => 'After product tabs (default)',
        ];
        echo '<select name="' . $name . '">';
        foreach ($options as $value => $label) {
            $selected = selected($current, $value, false);
            echo '<option value="' . esc_attr($value) . '" ' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Where the block appears on the product page.</p>';
    }
}
