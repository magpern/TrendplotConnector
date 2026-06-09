=== Trendplot Connector ===
Contributors: trendplot
Tags: trendplot, content, seo, rank math, rest api
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write-first content bridge between Trendplot and WordPress.

== Description ==

Trendplot Connector exposes a HMAC-authenticated REST API that lets the Trendplot platform:

* Create and update draft posts with full metadata, taxonomy assignments, and related product/article links.
* Write Rank Math SEO fields (title, description, focus keyword, canonical URL, robots, schema type).
* Publish and unpublish posts — triggering Rank Math IndexNow instant indexing on publish.
* Query site info, categories, and tags.

An admin Content page provides a dashboard view of all Trendplot-managed posts with status badges, SEO score chips, row-level Publish/Unpublish actions, and bulk actions.

== Installation ==

1. Upload the plugin ZIP via **Plugins → Add New → Upload**, or extract to `wp-content/plugins/trendplot-connector/`.
2. Activate the plugin.
3. Navigate to **Trendplot → Settings** to generate the HMAC shared secret.
4. Supply the site ID and shared secret to the Trendplot platform.

== Frequently Asked Questions ==

= Does this plugin require Rank Math? =

No. Rank Math SEO is optional. If Rank Math is not active the `seo` fields in request bodies are accepted but silently ignored, and the `rank_math_active` flag in responses will be `false`.

= What happens to Trendplot-created posts if the plugin is uninstalled? =

The plugin removes only its own settings option on uninstall. Trendplot-created posts, their metadata, and all content remain in WordPress.

== Changelog ==

= 1.0.0 =
* Initial release. Full REST API, admin Content page, Rank Math SEO integration, publishing workflow, IndexNow instant indexing trigger. See CHANGELOG.md.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
