# Trendplot Connector

WordPress plugin that acts as a write-first content bridge between Trendplot and WordPress. Trendplot discovers content through crawling; this plugin does what crawling cannot: push drafts into WordPress, store Trendplot metadata as persistent post meta, and maintain product/article relationships across sync cycles.

---

## 1. Installation

```bash
# Clone the repository to your development machine
git clone https://github.com/magpern/TrendplotConnector.git \
  /home/magpern/plugin-development/TrendplotConnector

# Deploy to WordPress (run from development machine)
rsync -av --delete --exclude='.git' \
  /home/magpern/plugin-development/TrendplotConnector/ \
  /home/magpern/woocommerce/wp-content/plugins/trendplot-connector/
```

> **Warning:** The `--delete` flag removes any files in the deploy target that are not in the repository. Never edit the deploy directory directly.

---

## 2. Activation

```bash
# From /home/magpern/woocommerce/
docker compose run --rm wpcli wp plugin activate trendplot-connector
docker compose run --rm wpcli wp rewrite flush
```

---

## 3. Configuration

Navigate to **Settings → Trendplot Connector** in the WordPress admin.

| Field | Description |
|---|---|
| **Enable Connector** | Master on/off switch for the REST API |
| **Site ID** | Unique identifier for this site; must match the `X-Trendplot-Site-Id` header sent by Trendplot |
| **Shared Secret** | HMAC-SHA256 signing secret; use the Generate button or enter manually |
| **Allowed Origin** | Base URL of the Trendplot service (reserved for future CORS enforcement) |

**Generate Secret** — Click to create a new cryptographically random 64-character secret. The secret is stored in `wp_options` and is never written to any file.

**Test Connection** — Opens the `/health` endpoint in a new browser tab.

---

## 4. Endpoint Overview

All routes use namespace `trendplot/v1` under `/wp-json/`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | Conditional¹ | Connectivity check |
| GET | `/site-info` | Required | WordPress/WooCommerce version info |
| GET | `/categories` | Required | List all post categories |
| GET | `/tags` | Required | List all post tags |
| GET | `/drafts` | Required | List Trendplot-created drafts |
| POST | `/drafts` | Required | **Create a draft post** |
| PATCH | `/posts/{id}/meta` | Required | **Write Trendplot metadata to any post** |

¹ If no shared secret is configured, `/health` is public. Once a secret is configured, HMAC auth is required.

---

## 5. HMAC Signing

All authenticated requests must include three headers:

```
X-Trendplot-Site-Id:    <your-site-id>
X-Trendplot-Timestamp:  <unix-timestamp>
X-Trendplot-Signature:  <hmac-hex>
```

The signature is `HMAC-SHA256` over the following string:

```
METHOD\n
/wp-json/trendplot/v1/path\n
timestamp\n
body
```

For GET requests, `body` is an empty string. For POST/PATCH, `body` is the exact JSON string as sent (do not re-serialize).

**Bash signing example (GET):**

```bash
SITE_ID="YOUR_SITE_ID"
SECRET="YOUR_SECRET_HERE"
METHOD="GET"
ENDPOINT="/wp-json/trendplot/v1/site-info"
TS=$(date +%s)
BODY=""

SIG=$(printf '%s\n%s\n%s\n%s' "$METHOD" "$ENDPOINT" "$TS" "$BODY" | \
  openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -s \
  -H "X-Trendplot-Site-Id: $SITE_ID" \
  -H "X-Trendplot-Timestamp: $TS" \
  -H "X-Trendplot-Signature: $SIG" \
  "https://example.com${ENDPOINT}"
```

**Bash signing example (POST with body):**

```bash
METHOD="POST"
ENDPOINT="/wp-json/trendplot/v1/drafts"
TS=$(date +%s)
BODY='{"title":"Test Article","content":"<p>Content here.</p>","trendplot_article_id":"art_001"}'

SIG=$(printf '%s\n%s\n%s\n%s' "$METHOD" "$ENDPOINT" "$TS" "$BODY" | \
  openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -s -X POST \
  -H "Content-Type: application/json" \
  -H "X-Trendplot-Site-Id: $SITE_ID" \
  -H "X-Trendplot-Timestamp: $TS" \
  -H "X-Trendplot-Signature: $SIG" \
  -d "$BODY" \
  "https://example.com${ENDPOINT}"
```

> The timestamp must be within 5 minutes of the server clock. Sign the body bytes exactly as sent — do not re-serialize or reformat the JSON.

---

## 6. Creating a Draft

```bash
METHOD="POST"
ENDPOINT="/wp-json/trendplot/v1/drafts"
TS=$(date +%s)
BODY='{
  "title": "Why BPC-157 and TB-500 Are Often Discussed Together",
  "content": "<p>BPC-157 and TB-500 are two peptides...</p>",
  "excerpt": "An overview of why these two peptides are frequently discussed together.",
  "categories": [12, 34],
  "tags": [5, 8],
  "trendplot_article_id": "art_abc123",
  "trendplot_source": "campaign_xyz",
  "trendplot_generated": "2026-06-07T12:00:00Z",
  "related_products": [101, 102],
  "related_articles": []
}'

# ... compute SIG as above ...

curl -s -X POST \
  -H "Content-Type: application/json" \
  -H "X-Trendplot-Site-Id: $SITE_ID" \
  -H "X-Trendplot-Timestamp: $TS" \
  -H "X-Trendplot-Signature: $SIG" \
  -d "$BODY" \
  "https://example.com${ENDPOINT}"
```

**Response (201 Created):**

```json
{
  "id": 200,
  "title": "Why BPC-157 and TB-500 Are Often Discussed Together",
  "slug": "",
  "status": "draft",
  "url": "https://example.com/?p=200",
  "edit_url": "https://example.com/wp-admin/post.php?post=200&action=edit",
  "created_at": "2026-06-07T12:00:00+00:00",
  "trendplot_article_id": "art_abc123"
}
```

**Idempotency:** If a draft with the same `trendplot_article_id` already exists, the endpoint returns `409 Conflict` with the existing post ID. Trendplot must explicitly decide to update the existing draft (Phase 2) rather than creating a duplicate.

**Required fields:** `title`, `content`. All other fields are optional.

---

## 7. Tagging Existing Content

Use `PATCH /posts/{id}/meta` to attach Trendplot metadata to any existing WordPress post or WooCommerce product:

```bash
POST_ID=101
METHOD="PATCH"
ENDPOINT="/wp-json/trendplot/v1/posts/${POST_ID}/meta"
TS=$(date +%s)
BODY='{
  "trendplot_article_id": "art_abc123",
  "trendplot_source": "campaign_xyz",
  "trendplot_last_sync": "2026-06-07T12:00:00Z",
  "related_products": [101, 102],
  "related_articles": [200, 201]
}'

# ... compute SIG as above ...

curl -s -X PATCH \
  -H "Content-Type: application/json" \
  -H "X-Trendplot-Site-Id: $SITE_ID" \
  -H "X-Trendplot-Timestamp: $TS" \
  -H "X-Trendplot-Signature: $SIG" \
  -d "$BODY" \
  "https://example.com${ENDPOINT}"
```

**Response (200 OK):**

```json
{
  "id": 101,
  "post_type": "product",
  "trendplot_article_id": "art_abc123",
  "trendplot_generated": null,
  "trendplot_source": "campaign_xyz",
  "trendplot_last_sync": "2026-06-07T12:00:00Z",
  "related_products": [101, 102],
  "related_articles": [200, 201],
  "updated_at": "2026-06-07T12:00:00+00:00"
}
```

Only keys in the `_trendplot_*` whitelist are accepted. Unknown keys return `400 invalid_meta_key`.

---

## 8. Deployment Workflow

```bash
# 1. Develop in the git repository
cd /home/magpern/plugin-development/TrendplotConnector
# ... make changes ...

# 2. Sync to WordPress
rsync -av --delete --exclude='.git' \
  /home/magpern/plugin-development/TrendplotConnector/ \
  /home/magpern/woocommerce/wp-content/plugins/trendplot-connector/

# 3. Verify plugin is active
cd /home/magpern/woocommerce
docker compose run --rm wpcli wp plugin list --name=trendplot-connector

# 4. Commit and push
cd /home/magpern/plugin-development/TrendplotConnector
git add .
git commit -m "feat: description of changes"
git push origin main
```

---

## 9. WP-CLI Verification

```bash
# Activate plugin
docker compose run --rm wpcli wp plugin activate trendplot-connector

# Confirm active
docker compose run --rm wpcli wp plugin list --name=trendplot-connector

# Flush rewrite rules
docker compose run --rm wpcli wp rewrite flush

# List all registered Trendplot routes
docker compose run --rm wpcli wp eval \
  'foreach(rest_get_server()->get_routes() as $r => $h) { if(str_contains($r,"trendplot")) echo $r."\n"; }'

# Test /health (no auth when no secret configured)
curl -sk https://test.biopentra.eu/wp-json/trendplot/v1/health | python3 -m json.tool

# Set test credentials (placeholder values only — replace with real values)
docker compose run --rm wpcli wp option update trendplot_connector_settings \
  '{"enabled":"1","site_id":"YOUR_SITE_ID","shared_secret":"YOUR_SECRET_HERE","allowed_origin":"https://app.trendplot.io"}' \
  --format=json

# Verify meta stored on a post
docker compose run --rm wpcli wp post meta get <POST_ID> _trendplot_article_id
docker compose run --rm wpcli wp post meta get <POST_ID> _trendplot_source

# List Trendplot-created drafts
docker compose run --rm wpcli wp post list \
  --post_status=draft \
  --meta_key=_trendplot_article_id \
  --fields=ID,post_title,post_status
```

---

## 10. Metadata Namespace

All Trendplot metadata uses the `_trendplot_` prefix. The leading underscore marks them as private (hidden from the default WordPress Custom Fields UI).

| Key | Type | Purpose |
|---|---|---|
| `_trendplot_article_id` | string | Trendplot's internal article/content ID |
| `_trendplot_generated` | string (ISO 8601) | When Trendplot generated or last updated the content |
| `_trendplot_source` | string | Source identifier (campaign ID, template ID, etc.) |
| `_trendplot_last_sync` | string (ISO 8601) | When Trendplot last successfully synced this item |
| `_trendplot_related_products` | int[] | WooCommerce product IDs linked to this post |
| `_trendplot_related_articles` | int[] | WordPress post IDs of related Trendplot articles |

The connector enforces a hardcoded whitelist (`MetaStore::ALLOWED_KEYS`). Any write request containing keys outside this list is rejected with `400 invalid_meta_key`.

---

## 11. Phase 2 Preview

The following capabilities are designed and ready to implement in Phase 2:

- **`GET /products`** — paginated WooCommerce products including variable/variation support
- **`GET /posts`** and **`GET /pages`** — paginated published content
- **`GET /inventory`** — full site snapshot for initial discovery
- **`GET /content/search`** — cross-type search with pagination
- **`PATCH /drafts/{id}`** — update an existing Trendplot draft
- **`POST /posts/{id}/status`** — lifecycle transitions (draft → pending review)
- Content fingerprints (`content_hash`) for change detection

Phase 2 inventory endpoints are valuable but are not the plugin's unique contribution — Trendplot can crawl public content. Phase 1 focuses exclusively on the write path that crawling can never provide.
