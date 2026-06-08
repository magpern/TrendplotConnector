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

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $table = new ContentListTable();
        $table->prepare_items();

        $status_filter = sanitize_text_field($_REQUEST['status_filter'] ?? '');
        $search        = sanitize_text_field($_REQUEST['s'] ?? '');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Trendplot Content</h1>
            <hr class="wp-header-end">

            <?php $table->views(); ?>

            <form id="tp-content-filter" method="get">
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

    private static function render_inline_assets(): void
    {
        ?>
        <style>
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
        a.tp-details-toggle {
            text-decoration: none;
            color: #2271b1;
        }
        #tp-content-filter .wp-list-table th.column-wp_id,
        #tp-content-filter .wp-list-table td.column-wp_id { width: 60px; }
        #tp-content-filter .wp-list-table th.column-related_products,
        #tp-content-filter .wp-list-table td.column-related_products { width: 80px; text-align: center; }
        #tp-content-filter .wp-list-table th.column-related_articles,
        #tp-content-filter .wp-list-table td.column-related_articles { width: 80px; text-align: center; }
        #tp-content-filter .wp-list-table th.column-created,
        #tp-content-filter .wp-list-table td.column-created,
        #tp-content-filter .wp-list-table th.column-modified,
        #tp-content-filter .wp-list-table td.column-modified { width: 100px; }
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
