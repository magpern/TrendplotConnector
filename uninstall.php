<?php
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('trendplot_connector_settings');
// _trendplot_* post meta is intentionally NOT deleted on uninstall.
// Trendplot-created drafts and relationship data remain in WordPress.
// Operators should manually review and clean up Trendplot-created content.
