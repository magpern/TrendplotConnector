<?php
declare(strict_types=1);

namespace TrendplotConnector\Admin;

use TrendplotConnector\Meta\MetaStore;
use WP_Query;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ContentListTable extends \WP_List_Table
{
    private const PER_PAGE = 50;

    /** @var array<string,int>|null */
    private ?array $status_count_cache = null;

    public function __construct()
    {
        parent::__construct([
            'singular' => 'trendplot_post',
            'plural'   => 'trendplot_posts',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'cb'               => '<input type="checkbox" />',
            'title'            => 'Title',
            'status'           => 'Status',
            'seo_score'        => 'SEO Score',
            'wp_url'           => 'URL',
            'article_id'       => 'Article ID',
            'related_products' => 'Products',
            'related_articles' => 'Articles',
            'created'          => 'Created',
            'modified'         => 'Modified',
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [
            'created'  => ['date', true],
            'modified' => ['modified', false],
        ];
    }

    protected function get_bulk_actions(): array
    {
        return [
            'tp_bulk_publish'   => 'Publish selected',
            'tp_bulk_unpublish' => 'Unpublish selected',
        ];
    }

    public function no_items(): void
    {
        echo 'No Trendplot-managed posts found.';
    }

    protected function column_default($item, $column_name): string
    {
        return '';
    }

    protected function column_cb($item): string
    {
        return sprintf(
            '<input type="checkbox" name="trendplot_post[]" value="%d" />',
            (int) $item['id']
        );
    }

    protected function column_title(array $item): string
    {
        $title    = $item['title'] !== '' ? $item['title'] : '(no title)';
        $edit_url = admin_url("post.php?post={$item['id']}&action=edit");
        $row_id   = 'tp-details-' . $item['id'];
        $post_id  = (int) $item['id'];

        $out = '<strong><a href="' . esc_url($edit_url) . '">' . esc_html($title) . '</a></strong>';

        // Row actions
        $actions = [];
        $actions['edit'] = '<a href="' . esc_url($edit_url) . '">Edit</a>';

        if ($item['status'] === 'publish') {
            $actions['view'] = '<a href="' . esc_url($item['url']) . '" target="_blank" rel="noopener">View</a>';

            $unpublish_url = wp_nonce_url(
                admin_url("admin-post.php?action=tp_unpublish_post&post_id={$post_id}"),
                'tp_unpublish_' . $post_id
            );
            $actions['unpublish'] = '<a href="' . esc_url($unpublish_url) . '" '
                . 'onclick="return confirm(\'Move this post back to draft?\')" '
                . 'class="tp-row-action-unpublish">Unpublish</a>';
        } else {
            $preview_url = (string) get_preview_post_link($post_id);
            $actions['preview'] = '<a href="' . esc_url($preview_url) . '" target="_blank" rel="noopener">Preview</a>';

            $publish_url = wp_nonce_url(
                admin_url("admin-post.php?action=tp_publish_post&post_id={$post_id}"),
                'tp_publish_' . $post_id
            );
            $actions['publish'] = '<a href="' . esc_url($publish_url) . '" '
                . 'onclick="return confirm(\'Publish this post now?\')" '
                . 'class="tp-row-action-publish">Publish</a>';
        }

        $actions['details'] = '<a href="#" class="tp-details-toggle" data-row="' . esc_attr($row_id) . '">Details &#9662;</a>';

        $out .= $this->row_actions($actions);
        return $out;
    }

    protected function column_status(array $item): string
    {
        $map = [
            'draft'   => ['Draft',     'tp-badge-draft'],
            'pending' => ['Pending',   'tp-badge-pending'],
            'future'  => ['Scheduled', 'tp-badge-future'],
            'publish' => ['Published', 'tp-badge-publish'],
        ];
        [$label, $class] = $map[$item['status']] ?? [ucfirst($item['status']), 'tp-badge-other'];
        return '<span class="tp-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    protected function column_seo_score(array $item): string
    {
        $score = $item['seo_score'];
        if ($score === null) {
            return '<span class="tp-score-na">—</span>';
        }
        $class = self::score_class($score);
        return '<span class="tp-score ' . esc_attr($class) . '">' . (int) $score . '</span>';
    }

    protected function column_wp_url(array $item): string
    {
        if ($item['status'] === 'publish') {
            return '<a href="' . esc_url($item['url']) . '" target="_blank" rel="noopener" class="tp-url-link" title="' . esc_attr($item['url']) . '">↗ View</a>';
        }
        $preview_url = (string) get_preview_post_link((int) $item['id']);
        return '<a href="' . esc_url($preview_url) . '" target="_blank" rel="noopener" class="tp-url-link">↗ Preview</a>';
    }

    protected function column_article_id(array $item): string
    {
        return esc_html($item['article_id']);
    }

    protected function column_related_products(array $item): string
    {
        $count = count($item['related_products']);
        return $count > 0 ? '<strong>' . $count . '</strong>' : '0';
    }

    protected function column_related_articles(array $item): string
    {
        $count = count($item['related_articles']);
        return $count > 0 ? '<strong>' . $count . '</strong>' : '0';
    }

    protected function column_created(array $item): string
    {
        return esc_html($item['created']);
    }

    protected function column_modified(array $item): string
    {
        return esc_html($item['modified']);
    }

    public function single_row(mixed $item): void
    {
        echo '<tr class="tp-content-row">';
        $this->single_row_columns($item);
        echo '</tr>';

        $col_count    = count($this->get_columns());
        $row_id       = 'tp-details-' . $item['id'];
        $products_str = !empty($item['related_products'])
            ? implode(', ', array_map('intval', $item['related_products']))
            : '—';
        $articles_str = !empty($item['related_articles'])
            ? implode(', ', array_map('intval', $item['related_articles']))
            : '—';

        echo '<tr id="' . esc_attr($row_id) . '" class="tp-details-row" style="display:none;">';
        echo '<td colspan="' . (int) $col_count . '" class="tp-details-cell">';
        echo '<table class="tp-details-inner">';
        $fields = [
            'WP Post ID'           => esc_html((string) $item['id']),
            'Trendplot Article ID' => esc_html($item['article_id'] ?: '—'),
            'Trendplot Source'     => esc_html($item['source'] ?: '—'),
            'Trendplot Generated'  => esc_html($item['generated'] ?: '—'),
            'Trendplot Last Sync'  => esc_html($item['last_sync'] ?: '—'),
            'Related Product IDs'  => esc_html($products_str),
            'Related Article IDs'  => esc_html($articles_str),
        ];
        foreach ($fields as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table>';
        echo '</td>';
        echo '</tr>';
    }

    protected function get_views(): array
    {
        $counts  = $this->get_status_counts();
        $total   = array_sum($counts);
        $current = sanitize_text_field($_REQUEST['status_filter'] ?? 'all');
        $search  = sanitize_text_field($_REQUEST['s'] ?? '');
        $base    = admin_url('admin.php?page=trendplot');
        if ($search !== '') {
            $base = add_query_arg('s', rawurlencode($search), $base);
        }

        $all_class = $current === 'all' ? ' class="current" aria-current="page"' : '';
        $views     = [];
        $views['all'] = sprintf(
            '<a href="%s"%s>All <span class="count">(%d)</span></a>',
            esc_url($base),
            $all_class,
            $total
        );

        $labels = [
            'draft'   => 'Draft',
            'pending' => 'Pending',
            'future'  => 'Scheduled',
            'publish' => 'Published',
        ];
        foreach ($labels as $status => $label) {
            $count = $counts[$status] ?? 0;
            if ($count === 0) {
                continue;
            }
            $url   = add_query_arg('status_filter', $status, $base);
            $class = $current === $status ? ' class="current" aria-current="page"' : '';
            $views[$status] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($url),
                $class,
                esc_html($label),
                $count
            );
        }

        return $views;
    }

    public function get_status_counts(): array
    {
        if ($this->status_count_cache !== null) {
            return $this->status_count_cache;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.post_status, COUNT(DISTINCT p.ID) AS cnt
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_trendplot_article_id'
               AND p.post_status IN ('draft','pending','future','publish')
               AND p.post_type = 'post'
             GROUP BY p.post_status",
            ARRAY_A
        );

        $counts = ['draft' => 0, 'pending' => 0, 'future' => 0, 'publish' => 0];
        foreach ((array) $rows as $row) {
            if (isset($counts[$row['post_status']])) {
                $counts[$row['post_status']] = (int) $row['cnt'];
            }
        }

        $this->status_count_cache = $counts;
        return $counts;
    }

    public function get_avg_seo_score(): ?int
    {
        global $wpdb;
        $avg = $wpdb->get_var(
            "SELECT AVG(CAST(pm.meta_value AS UNSIGNED))
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} tp ON p.ID = tp.post_id AND tp.meta_key = '_trendplot_article_id'
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
               AND pm.meta_key = 'rank_math_seo_score'
               AND pm.meta_value != ''
               AND pm.meta_value > 0
             WHERE p.post_status IN ('draft','pending','future','publish')
               AND p.post_type = 'post'"
        );
        return $avg !== null ? (int) round((float) $avg) : null;
    }

    public function prepare_items(): void
    {
        $per_page      = self::PER_PAGE;
        $current_page  = $this->get_pagenum();
        $status_filter = sanitize_text_field($_REQUEST['status_filter'] ?? 'all');
        $search        = sanitize_text_field($_REQUEST['s'] ?? '');
        $orderby       = sanitize_text_field($_REQUEST['orderby'] ?? 'date');
        $order         = strtoupper(sanitize_text_field($_REQUEST['order'] ?? 'DESC'));

        $order   = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
        $orderby = in_array($orderby, ['date', 'modified'], true) ? $orderby : 'date';

        $statuses = ['draft', 'pending', 'future', 'publish'];
        if (in_array($status_filter, $statuses, true)) {
            $statuses = [$status_filter];
        }

        $query_args = [
            'post_type'      => 'post',
            'post_status'    => $statuses,
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            'orderby'        => $orderby,
            'order'          => $order,
            'meta_query'     => [['key' => '_trendplot_article_id', 'compare' => 'EXISTS']],
        ];

        if ($search !== '') {
            $query_args = $this->apply_search($query_args, $search, $statuses);
        }

        $query       = new WP_Query($query_args);
        $total_items = (int) $query->found_posts;
        $this->items = [];

        foreach ($query->posts as $post) {
            $meta      = MetaStore::read($post->ID);
            $products  = is_array($meta['_trendplot_related_products']) ? $meta['_trendplot_related_products'] : [];
            $articles  = is_array($meta['_trendplot_related_articles']) ? $meta['_trendplot_related_articles'] : [];
            $score_raw = get_post_meta($post->ID, 'rank_math_seo_score', true);

            $this->items[] = [
                'id'               => $post->ID,
                'title'            => $post->post_title,
                'status'           => $post->post_status,
                'url'              => (string) get_permalink($post->ID),
                'article_id'       => (string) ($meta['_trendplot_article_id'] ?? ''),
                'source'           => (string) ($meta['_trendplot_source'] ?? ''),
                'generated'        => (string) ($meta['_trendplot_generated'] ?? ''),
                'last_sync'        => (string) ($meta['_trendplot_last_sync'] ?? ''),
                'related_products' => $products,
                'related_articles' => $articles,
                'created'          => (string) get_the_date('Y-m-d', $post->ID),
                'modified'         => (string) get_the_modified_date('Y-m-d', $post->ID),
                'seo_score'        => ($score_raw !== '' && $score_raw !== false) ? (int) $score_raw : null,
            ];
        }

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total_items / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    private function apply_search(array $query_args, string $search, array $statuses): array
    {
        $base_meta = [['key' => '_trendplot_article_id', 'compare' => 'EXISTS']];

        $title_ids = (new WP_Query([
            'post_type'      => 'post',
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            's'              => $search,
            'meta_query'     => $base_meta,
        ]))->posts;

        $meta_ids = (new WP_Query([
            'post_type'      => 'post',
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [[
                'key'     => '_trendplot_article_id',
                'value'   => $search,
                'compare' => 'LIKE',
            ]],
        ]))->posts;

        $all_ids = array_unique(array_merge(
            array_map('intval', (array) $title_ids),
            array_map('intval', (array) $meta_ids)
        ));

        unset($query_args['meta_query'], $query_args['s']);
        $query_args['post__in'] = !empty($all_ids) ? $all_ids : [0];

        return $query_args;
    }

    private static function score_class(int $score): string
    {
        if ($score >= 90) return 'tp-score-green';
        if ($score >= 80) return 'tp-score-lightgreen';
        if ($score >= 70) return 'tp-score-yellow';
        if ($score >= 60) return 'tp-score-orange';
        return 'tp-score-red';
    }
}
