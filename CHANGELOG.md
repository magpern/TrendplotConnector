# Changelog

## [Unreleased]

_No pending changes._

## [1.0.1] - 2026-09-02

### Added

- Automatic updates from a private update server via the bundled
  [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) v5
  library (`lib/plugin-update-checker/`). The server base URL is read from the
  `PRIVATE_UPDATE_SERVER` constant (define it in `wp-config.php`); when it is not
  defined the plugin does not check for updates.
- CI workflow that uploads the release package to the update server on each
  release tag.

## [1.0.0] - 2026-06-09

Initial release of Trendplot Connector.

### Added

- HMAC-SHA256 REST API authentication (`X-Trendplot-Site-Id`, `X-Trendplot-Timestamp`, `X-Trendplot-Signature`).
- `POST /trendplot/v1/drafts` — create WordPress drafts with full metadata, taxonomy, related products/articles, and optional Rank Math SEO fields.
- `PATCH /trendplot/v1/drafts/{id}` — update drafts; field-level optionality; slug sync via `post_name`.
- `GET /trendplot/v1/drafts` — paginated list of Trendplot-managed posts.
- `GET /trendplot/v1/drafts/{id}` — single draft with full metadata including SEO block.
- `POST /trendplot/v1/posts/{id}/publish` — publish a draft; triggers Rank Math IndexNow instant indexing when configured.
- `POST /trendplot/v1/posts/{id}/unpublish` — revert published post to draft.
- `GET /trendplot/v1/posts/{id}/seo` — read Rank Math SEO fields with `status` and `rank_math_active` flags.
- `PATCH /trendplot/v1/posts/{id}/seo` — write Rank Math SEO fields independently.
- `PATCH /trendplot/v1/posts/{id}/meta` — write Trendplot relationship metadata.
- `GET /trendplot/v1/categories` and `GET /trendplot/v1/tags` — taxonomy helpers.
- `GET /trendplot/v1/health` — connectivity probe.
- `GET /trendplot/v1/site-info` — site metadata.
- Rank Math SEO integration: title, description, focus keyword, canonical URL, robots (PHP-serialized array), schema type.
- Related Articles Gutenberg block.
- Admin Content page with status/SEO score columns, row actions (Publish/Unpublish), bulk actions, dashboard metrics cards.
- Settings page with HMAC secret generation.
- `uninstall.php` — removes plugin options on uninstall; Trendplot-created post content intentionally preserved.
- GitHub Actions CI (lint + build ZIP on push/PR).
- GitHub Actions Release (tag-based ZIP build and GitHub Release creation).
- `scripts/build-zip.sh` and `scripts/verify-plugin.sh` for local development.
