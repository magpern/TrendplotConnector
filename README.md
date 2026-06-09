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

Navigate to **Trendplot → Settings** in the WordPress admin sidebar.

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
| GET | `/drafts/{id}` | Required | **Retrieve current status of a Trendplot-managed post** |
| PATCH | `/drafts/{id}` | Required | **Update a Trendplot-created draft** |
| GET | `/posts/{id}/seo` | Required | **Read current Rank Math SEO values for any Trendplot-managed post** |
| PATCH | `/posts/{id}/seo` | Required | **Update Rank Math SEO for any Trendplot-managed post (published or draft)** |
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
  "slug": "bpc-157-tb-500-research-overview",
  "categories": [12, 34],
  "tags": [5, 8],
  "trendplot_article_id": "art_abc123",
  "trendplot_source": "campaign_xyz",
  "trendplot_generated": "2026-06-07T12:00:00Z",
  "related_products": [101, 102],
  "related_articles": [],
  "seo": {
    "title": "BPC-157 and TB-500 Research Overview | Example Site",
    "description": "A research overview of the BPC-157 and TB-500 peptides.",
    "focus_keyword": "bpc-157 tb-500 research",
    "canonical_url": "https://example.com/bpc-157-tb-500/",
    "robots": [],
    "schema_type": "Article"
  }
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
  "trendplot_article_id": "art_abc123",
  "seo_status": "written"
}
```

**Idempotency:** If a draft with the same `trendplot_article_id` already exists, the endpoint returns `409 Conflict` with the existing post ID. Trendplot must explicitly decide to update the existing draft (Phase 2) rather than creating a duplicate.

**Required fields:** `title`, `content`. All other fields are optional.

**`slug`** (optional): Sets `post_name` (the URL slug) via `sanitize_title()`. If omitted, WordPress generates a slug from the title. Two draft posts may share the same slug — WordPress only enforces slug uniqueness at publish time (appending `-2`, `-3`, etc.).

**SEO fields** (`seo` object, optional — requires Rank Math SEO active):

| Field | Type | Constraint | Description |
|---|---|---|---|
| `title` | string | max 300 chars | SEO title (`rank_math_title`) |
| `description` | string | max 500 chars | Meta description (`rank_math_description`) |
| `focus_keyword` | string | max 300 chars | Focus keyword (`rank_math_focus_keyword`) |
| `canonical_url` | string (URL) | valid URL or `""` | Canonical URL (`rank_math_canonical_url`) |
| `robots` | string[] | see whitelist | Robots directives (`rank_math_robots`) |
| `schema_type` | string | see whitelist | Rich snippet type (`rank_math_rich_snippet`) |

Omit `seo` entirely to skip SEO writes. Pass `seo: {}` to no-op. An empty string for any string field deletes that meta value. An empty `robots` array deletes the stored value.

**`seo_status` values:**

| Value | Meaning |
|---|---|
| `"written"` | Rank Math active; SEO fields written |
| `"skipped"` | Rank Math not active; SEO fields ignored |
| `"none"` | No `seo` key in the request |

---

## 7. Updating a Draft

Use `PATCH /drafts/{id}` to update an existing Trendplot-created draft. All request fields are optional; supply only what needs changing. At least one updateable field must be present.

**Allowed target posts:**

| Condition | Result |
|---|---|
| Post does not exist | `404 not_found` |
| Post has no `_trendplot_article_id` | `403 not_trendplot_draft` |
| Post is `publish` or `private` | `409 published_post_rejected` |
| Post is `draft`, `pending`, or `future` | ✅ Allowed |

**Request body** (all fields optional; at least one required):

```json
{
  "title": "Updated article title",
  "content": "<p>Updated HTML content.</p>",
  "excerpt": "Updated excerpt.",
  "slug": "updated-article-slug",
  "categories": [12, 34],
  "tags": [5, 8],
  "trendplot_article_id": "art_abc123",
  "trendplot_source": "campaign_xyz",
  "trendplot_generated": "2026-06-07T12:00:00Z",
  "trendplot_last_sync": "2026-06-07T12:00:00Z",
  "related_products": [101, 102],
  "related_articles": [200, 201],
  "seo": {
    "title": "Updated SEO title | Example Site",
    "description": "Updated meta description.",
    "robots": ["noindex"]
  }
}
```

**`slug`** (optional): Updates `post_name` via `sanitize_title()`. Only applies to `draft`, `pending`, and `future` posts — published posts are rejected entirely by this endpoint. If the slug is already taken by a published post, WordPress appends `-2`, `-3`, etc. at publish time; two drafts may share the same slug without conflict. The updated slug is reflected in the response `slug` field.

**Endpoint scope:** `PATCH /drafts/{id}` is the right endpoint for updating article body, title, excerpt, **and slug**. Use `PATCH /posts/{id}/seo` exclusively for Rank Math SEO field updates on already-published posts — that endpoint never touches content, title, excerpt, or slug.

**SEO field rules for PATCH:** Same fields and validation as `POST /drafts`. Only the fields present in the `seo` object are written — omitted fields are left unchanged. Pass `""` to delete a stored value; pass `[]` to delete stored robots. All validation runs before any WordPress write; on failure the post is left completely unchanged.

**Identity rule:** If `trendplot_article_id` is supplied in the body, it must match the `_trendplot_article_id` already on the post. A mismatch returns `409 article_id_mismatch` to prevent accidentally updating the wrong draft.

**`_trendplot_last_sync`** is always written. If not supplied in the body, it is set to the current UTC timestamp automatically.

**Elementor behavior:** If `content` is updated, `_elementor_data` is rebuilt from the new HTML using the same boxed container structure as `POST /drafts`. The Full Width template assignment and all Elementor meta keys are preserved.

**Bash example (PATCH with new title and content):**

```bash
SITE_ID="YOUR_SITE_ID"
SECRET="YOUR_SECRET_HERE"
DRAFT_ID=200
METHOD="PATCH"
ENDPOINT="/wp-json/trendplot/v1/drafts/${DRAFT_ID}"
TS=$(date +%s)
BODY='{
  "title": "Why BPC-157 and TB-500 Are Often Studied Together — Updated",
  "content": "<p>Updated research overview.</p>",
  "trendplot_article_id": "art_abc123"
}'

SIG=$(printf '%s\n%s\n%s\n%s' "$METHOD" "$ENDPOINT" "$TS" "$BODY" | \
  openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

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
  "id": 200,
  "title": "Why BPC-157 and TB-500 Are Often Studied Together — Updated",
  "slug": "why-bpc-157-and-tb-500-are-often-studied-together",
  "status": "draft",
  "url": "https://example.com/?p=200",
  "edit_url": "https://example.com/wp-admin/post.php?post=200&action=edit",
  "modified_at": "2026-06-07T12:05:00+00:00",
  "trendplot_article_id": "art_abc123",
  "updated": true,
  "seo_status": "written"
}
```

**Error codes:**

| Code | HTTP | Meaning |
|---|---|---|
| `not_found` | 404 | Post ID does not exist |
| `not_trendplot_draft` | 403 | Post has no `_trendplot_article_id` — not created by Trendplot |
| `published_post_rejected` | 409 | Post is published or private — cannot be updated via this endpoint |
| `article_id_mismatch` | 409 | Supplied `trendplot_article_id` does not match the post's stored value |
| `validation_error` | 400 | Invalid category/tag ID, invalid product ID, empty body, etc. |
| `content_too_large` | 413 | Content exceeds 200,000 characters |

---

## 8. Retrieving Draft Status

Use `GET /drafts/{id}` to check the current WordPress status of a post that Trendplot created or manages. This is the primary way to detect whether a human editor has published, moved to pending, or otherwise changed the state of a Trendplot-created post.

**Rules:**

| Condition | Result |
|---|---|
| Post does not exist | `404 not_found` |
| Post has no `_trendplot_article_id` | `403 not_trendplot_post` |
| Post is `trash`, `private`, or other non-standard status | `404 not_found` |
| Post is `draft`, `pending`, `future`, or `publish` | ✅ `200 OK` |

No request body is required — HMAC auth headers only.

**Bash example:**

```bash
SITE_ID="YOUR_SITE_ID"
SECRET="YOUR_SECRET_HERE"
DRAFT_ID=200
METHOD="GET"
ENDPOINT="/wp-json/trendplot/v1/drafts/${DRAFT_ID}"
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

**Response (200 OK):**

```json
{
  "id": 200,
  "title": "Why BPC-157 and TB-500 Are Often Studied Together",
  "status": "draft",
  "url": "https://example.com/?p=200",
  "edit_url": "https://example.com/wp-admin/post.php?post=200&action=edit",
  "modified_at": "2026-06-07T12:05:00+00:00",
  "trendplot_article_id": "art_abc123",
  "trendplot_last_sync": "2026-06-07T12:00:00+00:00",
  "related_products": [101, 102],
  "related_articles": [],
  "seo": {
    "title": "BPC-157 and TB-500 Research Overview | Example Site",
    "description": "A research overview of the BPC-157 and TB-500 peptides.",
    "focus_keyword": "bpc-157 tb-500 research",
    "canonical_url": "https://example.com/bpc-157-tb-500/",
    "robots": [],
    "schema_type": "Article"
  }
}
```

**Notes:**

- Post `content` and `excerpt` are never returned — this endpoint exposes status and metadata only.
- `related_products` and `related_articles` are returned as arrays (empty array `[]` if not set).
- `trendplot_last_sync` is `null` if never written.
- A `status` of `"publish"` means a human editor has published the post in WordPress admin.
- `seo` contains current Rank Math meta for the post. Fields with no stored value are `null`; `robots` is always an array. `seo` is `null` (not an object) when Rank Math is not active on the site.

**Error codes:**

| Code | HTTP | Meaning |
|---|---|---|
| `not_found` | 404 | Post ID does not exist or is not in a supported status |
| `not_trendplot_post` | 403 | Post has no `_trendplot_article_id` — not managed by Trendplot |

**Verification checklist:**

- [ ] `GET /drafts/<nonexistent-id>` → `404 not_found`
- [ ] `GET /drafts/<id-without-trendplot-meta>` → `403 not_trendplot_post`
- [ ] `GET /drafts/<valid-trendplot-draft-id>` → `200` with correct fields, no `content`
- [ ] `GET /drafts/<published-trendplot-post-id>` → `200` with `"status": "publish"`
- [ ] Response contains `related_products` and `related_articles` as arrays
- [ ] No `content`, `excerpt`, or customer data in response

---

## 9. Dedicated SEO Endpoint

Use `PATCH /posts/{id}/seo` to update Rank Math SEO metadata on any Trendplot-managed post — including published posts. Use `GET /posts/{id}/seo` to read the current values. Neither endpoint touches content, title, excerpt, Elementor data, categories, or tags.

### Allowed posts

| Condition | Result |
|---|---|
| Post does not exist | `404 not_found` |
| Post is `trash` | `404 not_found` |
| Post has no `_trendplot_article_id` | `403 not_trendplot_post` |
| Post is `draft`, `pending`, `future`, or `publish` | ✅ Allowed |

Unlike `PATCH /drafts/{id}`, published posts are accepted.

### Supported SEO fields

| Field | Type | Notes |
|---|---|---|
| `title` | string (max 300) | SEO title tag |
| `description` | string (max 500) | Meta description |
| `focus_keyword` | string (max 300) | Primary keyword |
| `canonical_url` | URL string or `""` | Empty string deletes |
| `robots` | string array | See robots whitelist in [Section 16](#16-rank-math-seo-integration) |
| `schema_type` | string | `off`, `Article`, `NewsArticle`, `BlogPosting` |

All fields are optional per request. An explicit empty string deletes the stored value. An explicit empty `robots` array (`[]`) deletes the stored robots directive. Unknown keys return `400 validation_error`.

### Rank Math inactive behavior

When Rank Math is not active (`rank_math_active: false`):
- `GET /posts/{id}/seo` returns `"seo": null`
- `PATCH /posts/{id}/seo` returns `"seo_status": "skipped"` — the draft/post is not modified
- The `rank_math_active` field in every response signals this state to the caller

### PATCH /posts/{id}/seo

**Request body:**

```json
{
  "seo": {
    "title": "Updated SEO Title | Example Site",
    "description": "Updated meta description.",
    "focus_keyword": "bpc-157 tb-500 research",
    "canonical_url": "https://example.com/bpc-157-tb-500/",
    "robots": [],
    "schema_type": "Article"
  }
}
```

**Bash example:**

```bash
SITE_ID="YOUR_SITE_ID"
SECRET="YOUR_SECRET_HERE"
POST_ID=200
METHOD="PATCH"
ENDPOINT="/wp-json/trendplot/v1/posts/${POST_ID}/seo"
TS=$(date +%s)
BODY='{
  "seo": {
    "title": "BPC-157 and TB-500 — Updated SEO Title | Example Site",
    "description": "Updated research overview meta description.",
    "focus_keyword": "bpc-157 tb-500",
    "robots": [],
    "schema_type": "Article"
  }
}'

SIG=$(printf '%s\n%s\n%s\n%s' "$METHOD" "$ENDPOINT" "$TS" "$BODY" | \
  openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

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
  "id": 200,
  "rank_math_active": true,
  "seo_status": "written",
  "seo": {
    "title": "BPC-157 and TB-500 — Updated SEO Title | Example Site",
    "description": "Updated research overview meta description.",
    "focus_keyword": "bpc-157 tb-500",
    "canonical_url": null,
    "schema_type": "Article",
    "robots": [],
    "score": null
  }
}
```

`seo_status` values: `"written"` (Rank Math active, fields written), `"skipped"` (Rank Math inactive), `"none"` (empty `seo: {}`).

### GET /posts/{id}/seo

Returns the current Rank Math SEO values without modifying anything.

**Bash example:**

```bash
METHOD="GET"
ENDPOINT="/wp-json/trendplot/v1/posts/${POST_ID}/seo"
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

**Response (200 OK):**

```json
{
  "id": 200,
  "status": "publish",
  "rank_math_active": true,
  "seo": {
    "title": "BPC-157 and TB-500 — Updated SEO Title | Example Site",
    "description": "Updated research overview meta description.",
    "focus_keyword": "bpc-157 tb-500",
    "canonical_url": null,
    "schema_type": "Article",
    "robots": [],
    "score": 72
  }
}
```

`seo` is `null` when Rank Math is not active. `score` is `null` when the post has not been opened and saved in the Rank Math editor (score is computed by the editor's JavaScript and persisted only on save).

### Rank Math score and analysis

| Field | Availability |
|---|---|
| `seo.score` | ✅ Read from `rank_math_seo_score` post meta (integer 0–100, or `null` if not yet scored) |
| Analysis breakdown (errors / warnings / passed) | ⛔ Deferred — Rank Math computes the per-post analysis entirely in its editor JavaScript; it is never persisted to the database and cannot be read back server-side without re-implementing the analysis engine |

Trendplot-created posts have `score: null` until the post is opened in the WordPress Rank Math editor and saved. The score is then available on subsequent GET requests.

### Verification checklist

- [x] `PATCH /posts/{id}/seo` on a published Trendplot post → `200`, `seo_status: "written"`, `rank_math_active: true`
- [x] `GET /posts/{id}/seo` returns `status`, `rank_math_active`, and all SEO fields including `score`
- [x] Rank Math meta confirmed in DB after PATCH (`rank_math_title`, `rank_math_description`, etc.)
- [x] Post content, title, excerpt, Elementor data unchanged after PATCH
- [x] `score` reads back correctly when `rank_math_seo_score` meta is present
- [x] Non-Trendplot post → `403 not_trendplot_post`
- [x] Non-existent post → `404 not_found`
- [x] Trashed post → `404 not_found`
- [x] Unknown `seo` key → `400 validation_error`

---

## 10. Tagging Existing Content

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

## 11. Deployment Workflow

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

## 12. WP-CLI Verification

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

## 13. Metadata Namespace

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

## 14. Related Research Articles Block

When a WooCommerce product has Trendplot articles linked to it, the plugin automatically renders a **Related Research Articles** section on that product's front-end page.

### How it works

1. When Trendplot creates a draft via `POST /drafts` it includes `related_products: [<product_id>]` in the body.
2. The connector stores this as `_trendplot_related_products` (serialized int array) on the article post.
3. On each product page request, the block queries for published posts whose `_trendplot_related_products` array contains the current product ID.
4. Only **published** posts are returned — drafts, private, and pending posts are never shown.
5. If no articles match, the block renders nothing and leaves no empty markup.
6. Results are cached per product with a one-hour transient. The cache is invalidated immediately when `_trendplot_related_products` is written on any post via `POST /drafts` or `PATCH /posts/{id}/meta`.

### Linking an article to a product

Include `related_products` in the `POST /drafts` body:

```bash
BODY='{
  "title": "Why BPC-157 Matters for Recovery Research",
  "content": "<p>Research on BPC-157...</p>",
  "trendplot_article_id": "art_bpc_001",
  "related_products": [101]
}'
```

Or add the relationship to an existing post via `PATCH /posts/{article_id}/meta`:

```bash
BODY='{"related_products": [101, 102]}'
```

### Admin settings

Navigate to **Trendplot → Settings → Related Research Articles**.

| Setting | Default | Description |
|---|---|---|
| **Enable Block** | On | Show or hide the block site-wide |
| **Block Title** | *(empty)* | Override the heading text; leave blank for "Related Research Articles" |
| **Maximum Articles** | 5 | Cap on how many links to show per product (1–20) |
| **Placement** | After product tabs | Where the block appears on the product page |

**Placement options:**

| Value | Hook | Position |
|---|---|---|
| After product tabs *(default)* | `woocommerce_product_after_tabs` | Inside `.woocommerce-tabs`, below the last tab panel |
| After short description | `woocommerce_single_product_summary` p.21 | Below the short description, above Add to Cart |
| After product meta | `woocommerce_single_product_summary` p.45 | Below the SKU/category meta line |

### CSS classes

The block uses BEM class names. Override styles in your theme's CSS:

```css
.trendplot-related-articles { }
.trendplot-related-articles__title { }
.trendplot-related-articles__list { }
.trendplot-related-articles__item { }
.trendplot-related-articles__link { }
```

The title text can also be changed programmatically without touching settings:

```php
add_filter('trendplot_related_articles_title', fn() => 'Further Reading');
```

### Fallback behaviour

- **No linked articles** — the block outputs nothing; no empty `<section>` is rendered.
- **All linked articles are drafts/private** — same as above; the WP_Query filters to `post_status = publish` only.
- **Block disabled in settings** — the WooCommerce hook is never registered; zero HTML output and zero database queries.

### WP-CLI verification

```bash
# Confirm related_products meta is stored on a Trendplot article
docker compose run --rm wpcli wp post meta get <ARTICLE_ID> _trendplot_related_products

# Run the same query the block uses (replace 101 with your product ID)
docker compose run --rm wpcli wp eval '
$q = new WP_Query([
    "post_type"      => "post",
    "post_status"    => "publish",
    "posts_per_page" => 5,
    "no_found_rows"  => true,
    "meta_query"     => [["key"=>"_trendplot_related_products","value"=>serialize(101),"compare"=>"LIKE"]],
]);
foreach ($q->posts as $p) { echo $p->ID . " " . $p->post_title . "\n"; }
'

# Clear the per-product transient cache (forces a live query on next page load)
docker compose run --rm wpcli wp transient delete trendplot_rel_arts_<PRODUCT_ID>

# Confirm block HTML on the product page
curl -sk "https://example.com/product/<slug>/" | grep -A 10 'trendplot-related-articles'
```

### Verification checklist

- [ ] `_trendplot_related_products` meta is set on at least one published article
- [ ] Product page shows `<section class="trendplot-related-articles">` in HTML
- [ ] Only published articles appear; drafts are absent
- [ ] Articles are ordered newest first
- [ ] Changing **Maximum Articles** to 1 shows exactly 1 link
- [ ] Changing **Placement** moves the block to the correct position
- [ ] Changing **Block Title** updates the `<h2>` text
- [ ] Disabling the block via **Enable Block** → unchecked removes all block HTML
- [ ] Re-enabling restores the block
- [ ] A product with no linked articles shows no block and no empty markup
- [ ] Clearing the transient and reloading refreshes the article list

---

## 15. Trendplot Content Admin Screen

The **Trendplot → Content** screen gives WordPress administrators a read-only view of all posts created or managed by Trendplot. It is the primary tool for monitoring sync status, debugging relationships, and tracking which drafts have been published.

### Purpose

- See all Trendplot-managed posts in one place
- Monitor draft vs. published vs. scheduled status
- Inspect product and article relationship counts
- Navigate to the WordPress editor for any post
- Debug article IDs and sync timestamps

### Location

**Trendplot → Content** in the WordPress admin sidebar (top-level Trendplot menu, position 26).

### Columns

| Column | Description |
|---|---|
| **Title** | Post title with a "Details ▾" expand link |
| **Status** | Colour-coded badge: Draft · Pending · Scheduled · Published |
| **WP ID** | WordPress post ID |
| **Article ID** | `_trendplot_article_id` meta value |
| **Products** | Count of entries in `_trendplot_related_products` |
| **Articles** | Count of entries in `_trendplot_related_articles` |
| **Created** | Post creation date (`YYYY-MM-DD`) |
| **Modified** | Last modified date (`YYYY-MM-DD`) |
| **Actions** | **Edit** (all statuses) · **View** (published) · **Preview** (draft/scheduled) |

### Filters

**Status tabs** — All · Draft · Pending · Scheduled · Published. Tabs only appear for statuses that have at least one post. Counts are based on the full Trendplot corpus regardless of any active search.

**Search** — matches against the post title OR the `_trendplot_article_id` value.

**Sorting** — click the **Created** or **Modified** column headers to toggle ascending/descending order.

### Detail row

Click **Details ▾** on any row to expand an inline panel showing:

- Trendplot Article ID
- Trendplot Source
- Trendplot Generated (timestamp)
- Trendplot Last Sync (timestamp)
- Related Product IDs (comma-separated)
- Related Article IDs (comma-separated)

Click **Details ▴** to collapse.

### Actions (per row)

| Action | Condition |
|---|---|
| **Edit** | Always shown — opens WordPress post editor |
| **View** | Post is `publish` — opens the live URL |
| **Preview** | Post is `draft`, `pending`, or `future` — opens WordPress preview |

No delete, publish, or status-change actions are available. This screen is intentionally read-only.

### Performance

- Queries only posts with `meta_key = _trendplot_article_id` via an indexed JOIN
- Status counts use a single aggregate SQL query (not one per status)
- 50 posts per page with standard WordPress pagination
- Search runs at most two queries to resolve title + article-ID matches

### Troubleshooting

**No posts appear** — Confirm at least one post has `_trendplot_article_id` meta set:

```bash
docker compose run --rm wpcli wp post list \
  --meta_key=_trendplot_article_id \
  --fields=ID,post_title,post_status
```

**Menu not visible** — Verify the current user has `manage_options` capability.

**Search returns unexpected results** — The search matches any substring of the article ID. Use a more specific prefix to narrow results.

### Verification checklist

- [ ] **Trendplot → Content** appears in the admin sidebar
- [ ] All Trendplot-managed posts appear (draft, pending, future, publish)
- [ ] Non-Trendplot drafts do not appear
- [ ] Status tabs show correct counts
- [ ] Clicking a status tab filters the table correctly
- [ ] Searching by title returns matching posts
- [ ] Searching by `trendplot_article_id` returns matching posts
- [ ] Product count column shows correct count for each row
- [ ] Article count column shows correct count for each row
- [ ] "Details ▾" expands the metadata panel; "Details ▴" collapses it
- [ ] Edit link opens the correct WordPress editor URL
- [ ] View link appears only for published posts
- [ ] Preview link appears for draft and scheduled posts

---

## 16. Rank Math SEO Integration

The connector writes Rank Math SEO metadata as part of `POST /drafts` and `PATCH /drafts/{id}`. Rank Math SEO (v1.0.x+) must be installed and active. If absent, the `seo` block is silently ignored and the response includes `"seo_status": "skipped"`.

### Meta key mapping

| `seo` field | Rank Math meta key | Storage format |
|---|---|---|
| `title` | `rank_math_title` | plain string |
| `description` | `rank_math_description` | plain string |
| `focus_keyword` | `rank_math_focus_keyword` | plain string |
| `canonical_url` | `rank_math_canonical_url` | plain URL string |
| `robots` | `rank_math_robots` | PHP-serialized string array |
| `schema_type` | `rank_math_rich_snippet` | plain string |

### Whitelists

**Robots directives:** `noindex`, `nofollow`, `noarchive`, `nosnippet`, `noimageindex`, `noodp`

**Schema types:** `off`, `Article`, `NewsArticle`, `BlogPosting`

Any value not in the whitelist returns `400 validation_error`.

### Unknown seo keys

Any key not in the six allowed fields returns `400 validation_error` immediately — before any WordPress write:

```json
{
  "code": "validation_error",
  "message": "Unknown SEO field: \"seo_title\". Allowed fields: title, description, focus_keyword, canonical_url, robots, schema_type."
}
```

On `POST /drafts`, the draft is not created. On `PATCH /drafts/{id}`, the post is left unchanged.

### WP-CLI verification

```bash
# Confirm Rank Math is active
docker compose run --rm wpcli wp plugin list --name=seo-by-rank-math

# After POST /drafts with seo fields, check written meta
docker compose run --rm wpcli wp post meta get <ID> rank_math_title
docker compose run --rm wpcli wp post meta get <ID> rank_math_description
docker compose run --rm wpcli wp post meta get <ID> rank_math_focus_keyword
docker compose run --rm wpcli wp post meta get <ID> rank_math_rich_snippet

# Verify robots stored as PHP-serialized array
docker compose run --rm wpcli wp post meta get <ID> rank_math_robots
# Expected for robots: ["noindex"] → a:1:{i:0;s:7:"noindex";}

# Test unknown seo key on POST → 400, no draft created
BODY='{"title":"Test","content":"<p>Test.</p>","trendplot_article_id":"seo-test-x",
      "seo":{"seo_title":"Wrong key name"}}'
# Expected: 400 validation_error
docker compose run --rm wpcli wp post list \
  --meta_key=_trendplot_article_id --meta_value=seo-test-x --fields=ID
# Expected: empty (no post created)
```

### Verification checklist

- [ ] `POST /drafts` with seo fields → `seo_status: "written"`, Rank Math meta visible in WP admin
- [ ] `PATCH /drafts/{id}` with `seo.robots: ["noindex"]` → `rank_math_robots` = `a:1:{i:0;s:7:"noindex";}`
- [ ] `PATCH /drafts/{id}` with `seo.title: ""` → `rank_math_title` meta deleted
- [ ] `PATCH /drafts/{id}` with `seo.robots: []` → `rank_math_robots` meta deleted
- [ ] Rank Math deactivated → `seo_status: "skipped"`, draft still created, no rank_math_* meta written
- [ ] `seo: {"robots": ["arbitrary-value"]}` → `400 validation_error`
- [ ] `seo: {"schema_type": "WooCommerceProduct"}` → `400 validation_error`
- [ ] `seo: {"seo_title": "Wrong key"}` on POST → `400 validation_error`, no post created
- [ ] `seo: {"meta_description": "Wrong key"}` on PATCH → `400 validation_error`, post unchanged
- [ ] `GET /drafts/{id}` → response includes `seo` block with deserialized `robots` array
- [ ] `GET /drafts/{id}` with Rank Math deactivated → `"seo": null`

---

## 17. Phase 2 Preview

The following capabilities are designed and ready to implement in Phase 2:

- **`GET /products`** — paginated WooCommerce products including variable/variation support
- **`GET /posts`** and **`GET /pages`** — paginated published content
- **`GET /inventory`** — full site snapshot for initial discovery
- **`GET /content/search`** — cross-type search with pagination
- **`PATCH /drafts/{id}`** — update an existing Trendplot draft
- **`POST /posts/{id}/status`** — lifecycle transitions (draft → pending review)
- Content fingerprints (`content_hash`) for change detection

Phase 2 inventory endpoints are valuable but are not the plugin's unique contribution — Trendplot can crawl public content. Phase 1 focuses exclusively on the write path that crawling can never provide.
