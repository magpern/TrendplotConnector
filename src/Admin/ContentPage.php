<?php
declare(strict_types=1);

namespace TrendplotConnector\Admin;

class ContentPage
{
    private const PAGE_SLUG = 'trendplot';

    public static function register_menu(): void
    {
        add_menu_page(
            __('Trendplot Content', 'trendplot-connector'),
            'Trendplot',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page'],
            'dashicons-chart-line',
            26
        );

        // Rename the auto-generated duplicate first submenu item to "Content"
        add_submenu_page(
            self::PAGE_SLUG,
            __('Trendplot Content', 'trendplot-connector'),
            __('Content', 'trendplot-connector'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    // -------------------------------------------------------------------------
    // Bulk action handler (hooked on admin_init)
    // -------------------------------------------------------------------------

    public static function handle_bulk_actions(): void
    {
        if (!is_admin() || !isset($_REQUEST['page']) || $_REQUEST['page'] !== self::PAGE_SLUG) {
            return;
        }

        $action = (string) ($_REQUEST['action'] ?? '-1');
        if ($action === '-1') {
            $action = (string) ($_REQUEST['action2'] ?? '-1');
        }

        if (!in_array($action, ['tp_bulk_publish', 'tp_bulk_unpublish'], true)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        check_admin_referer('bulk-trendplot_posts');

        $ids       = array_map('intval', (array) ($_POST['trendplot_post'] ?? []));
        $done      = 0;
        $skipped   = 0;
        $target    = $action === 'tp_bulk_publish' ? 'publish' : 'draft';
        $need_from = $action === 'tp_bulk_publish' ? ['draft', 'pending', 'future'] : ['publish'];

        foreach ($ids as $post_id) {
            if ($post_id <= 0) {
                continue;
            }
            $post       = get_post($post_id);
            $article_id = $post ? (string) get_post_meta($post_id, '_trendplot_article_id', true) : '';
            if (!$post || $article_id === '' || !in_array($post->post_status, $need_from, true)) {
                $skipped++;
                continue;
            }
            $result = wp_update_post(['ID' => $post_id, 'post_status' => $target], true);
            if (is_wp_error($result)) {
                $skipped++;
            } else {
                $done++;
            }
        }

        $verb    = $action === 'tp_bulk_publish' ? 'published' : 'unpublished';
        $redirect = add_query_arg(
            ['tp_bulk_done' => $done, 'tp_bulk_skipped' => $skipped, 'tp_bulk_verb' => $verb],
            admin_url('admin.php?page=' . self::PAGE_SLUG)
        );
        wp_redirect($redirect);
        exit;
    }

    // -------------------------------------------------------------------------
    // Row action handlers (hooked on admin_post_*)
    // -------------------------------------------------------------------------

    public static function handle_row_publish(): void
    {
        $post_id = absint($_GET['post_id'] ?? 0);
        check_admin_referer('tp_publish_' . $post_id);

        if (!current_user_can('manage_options') || $post_id === 0) {
            wp_die('Unauthorized.');
        }

        $post       = get_post($post_id);
        $article_id = $post ? (string) get_post_meta($post_id, '_trendplot_article_id', true) : '';

        if (!$post || $article_id === '' || !in_array($post->post_status, ['draft', 'pending', 'future'], true)) {
            wp_redirect(add_query_arg('tp_notice', 'publish_invalid', admin_url('admin.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
        wp_redirect(add_query_arg('tp_notice', 'published', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    public static function handle_row_unpublish(): void
    {
        $post_id = absint($_GET['post_id'] ?? 0);
        check_admin_referer('tp_unpublish_' . $post_id);

        if (!current_user_can('manage_options') || $post_id === 0) {
            wp_die('Unauthorized.');
        }

        $post       = get_post($post_id);
        $article_id = $post ? (string) get_post_meta($post_id, '_trendplot_article_id', true) : '';

        if (!$post || $article_id === '' || $post->post_status !== 'publish') {
            wp_redirect(add_query_arg('tp_notice', 'unpublish_invalid', admin_url('admin.php?page=' . self::PAGE_SLUG)));
            exit;
        }

        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        wp_redirect(add_query_arg('tp_notice', 'unpublished', admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $table = new ContentListTable();
        $table->prepare_items();

        $status_filter = sanitize_text_field($_REQUEST['status_filter'] ?? '');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Trendplot Content</h1>
            <hr class="wp-header-end">

            <?php self::render_notices(); ?>
            <?php self::render_dashboard($table); ?>

            <?php $table->views(); ?>

            <form id="tp-content-filter" method="post">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <?php if ($status_filter !== '') : ?>
                    <input type="hidden" name="status_filter" value="<?php echo esc_attr($status_filter); ?>" />
                <?php endif; ?>
                <?php $table->search_box('Search content', 'tp-content-search'); ?>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
        self::render_inline_assets();
    }

    // -------------------------------------------------------------------------
    // Admin notices
    // -------------------------------------------------------------------------

    private static function render_notices(): void
    {
        // Bulk action result
        if (isset($_GET['tp_bulk_done'])) {
            $done    = (int) $_GET['tp_bulk_done'];
            $skipped = (int) ($_GET['tp_bulk_skipped'] ?? 0);
            $verb    = sanitize_text_field($_GET['tp_bulk_verb'] ?? 'processed');
            $msg     = sprintf('%s %d %s.', ucfirst($verb), $done, $done === 1 ? 'post' : 'posts');
            if ($skipped > 0) {
                $msg .= sprintf(' Skipped %d (invalid transition or not Trendplot-managed).', $skipped);
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        // Row action result
        if (isset($_GET['tp_notice'])) {
            $notice  = sanitize_text_field($_GET['tp_notice']);
            $map     = [
                'published'        => ['success', 'Post published.'],
                'unpublished'      => ['success', 'Post moved back to draft.'],
                'publish_invalid'  => ['error',   'Could not publish: invalid status or not a Trendplot-managed post.'],
                'unpublish_invalid'=> ['error',   'Could not unpublish: post is not published or not a Trendplot-managed post.'],
            ];
            if (isset($map[$notice])) {
                [$type, $text] = $map[$notice];
                echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
            }
        }
    }

    // -------------------------------------------------------------------------
    // Dashboard summary cards
    // -------------------------------------------------------------------------

    private static function render_dashboard(ContentListTable $table): void
    {
        $counts    = $table->get_status_counts();
        $total     = array_sum($counts);
        $drafts    = ($counts['draft'] ?? 0) + ($counts['pending'] ?? 0) + ($counts['future'] ?? 0);
        $published = $counts['publish'] ?? 0;
        $avg_score = $table->get_avg_seo_score();
        ?>
        <div class="tp-dashboard">
            <div class="tp-card">
                <div class="tp-card-value"><?php echo (int) $total; ?></div>
                <div class="tp-card-label">Articles</div>
            </div>
            <div class="tp-card">
                <div class="tp-card-value"><?php echo (int) $drafts; ?></div>
                <div class="tp-card-label">Drafts</div>
            </div>
            <div class="tp-card">
                <div class="tp-card-value"><?php echo (int) $published; ?></div>
                <div class="tp-card-label">Published</div>
            </div>
            <div class="tp-card">
                <div class="tp-card-value">
                    <?php echo $avg_score !== null ? (int) $avg_score : '—'; ?>
                </div>
                <div class="tp-card-label">Avg SEO Score</div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Inline CSS + JS
    // -------------------------------------------------------------------------

    private static function render_inline_assets(): void
    {
        ?>
        <style>
        /* --- badges --- */
        .tp-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .tp-badge-draft   { background: #e2e2e2; color: #555; }
        .tp-badge-pending { background: #fff3cd; color: #856404; }
        .tp-badge-future  { background: #cfe2ff; color: #084298; }
        .tp-badge-publish { background: #d1e7dd; color: #0a3622; }

        /* --- SEO score chips --- */
        .tp-score {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
        }
        .tp-score-green      { background: #1a7f37; }
        .tp-score-lightgreen { background: #2da44e; }
        .tp-score-yellow     { background: #9a6700; }
        .tp-score-orange     { background: #bc4c00; }
        .tp-score-red        { background: #cf222e; }
        .tp-score-na         { color: #999; font-size: 13px; }

        /* --- row action colours --- */
        .tp-row-action-publish   { color: #1a7f37 !important; }
        .tp-row-action-unpublish { color: #bc4c00 !important; }

        /* --- URL column --- */
        .tp-url-link { font-size: 12px; white-space: nowrap; }

        /* --- details expand row --- */
        .tp-details-row .tp-details-cell {
            padding: 0 0 0 24px;
            background: #f6f7f7;
            border-top: 1px solid #e1e1e1;
        }
        table.tp-details-inner {
            margin: 10px 0 12px;
            border-collapse: collapse;
        }
        table.tp-details-inner th,
        table.tp-details-inner td {
            padding: 3px 16px 3px 0;
            font-size: 12px;
            text-align: left;
            vertical-align: top;
        }
        table.tp-details-inner th {
            color: #666;
            min-width: 170px;
            font-weight: 600;
        }

        /* --- dashboard cards --- */
        .tp-dashboard {
            display: flex;
            gap: 12px;
            margin: 16px 0 20px;
            flex-wrap: wrap;
        }
        .tp-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 14px 24px;
            min-width: 110px;
            text-align: center;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
        }
        .tp-card-value {
            font-size: 28px;
            font-weight: 700;
            color: #1d2327;
            line-height: 1.2;
        }
        .tp-card-label {
            font-size: 12px;
            color: #646970;
            margin-top: 4px;
        }

        /* --- column widths --- */
        #tp-content-filter .wp-list-table th.column-seo_score,
        #tp-content-filter .wp-list-table td.column-seo_score { width: 80px; text-align: center; }
        #tp-content-filter .wp-list-table th.column-wp_url,
        #tp-content-filter .wp-list-table td.column-wp_url { width: 80px; }
        #tp-content-filter .wp-list-table th.column-related_products,
        #tp-content-filter .wp-list-table td.column-related_products { width: 80px; text-align: center; }
        #tp-content-filter .wp-list-table th.column-related_articles,
        #tp-content-filter .wp-list-table td.column-related_articles { width: 80px; text-align: center; }
        #tp-content-filter .wp-list-table th.column-created,
        #tp-content-filter .wp-list-table td.column-created,
        #tp-content-filter .wp-list-table th.column-modified,
        #tp-content-filter .wp-list-table td.column-modified { width: 90px; }
        #tp-content-filter .wp-list-table th.column-status,
        #tp-content-filter .wp-list-table td.column-status { width: 90px; }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.tp-details-toggle').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    var row = document.getElementById(this.dataset.row);
                    if (!row) return;
                    var show = row.style.display === 'none';
                    row.style.display = show ? '' : 'none';
                    this.textContent = show ? 'Details ▴' : 'Details ▾';
                });
            });
        });
        </script>
        <?php
    }
}
