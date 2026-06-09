# Release Process — Trendplot Connector

## Version locations

The plugin version is defined in **two places** and must be kept in sync:

| Location | Example |
|---|---|
| Plugin header (`trendplot-connector.php` line `* Version:`) | `1.0.0` |
| PHP constant (`TRENDPLOT_CONNECTOR_VERSION`) | `'1.0.0'` |

`scripts/verify-plugin.sh` and `scripts/build-zip.sh` both fail if these two values differ.

## Release checklist

1. **Update version** in `trendplot-connector.php` (both header and constant).
2. **Update `readme.txt`** — bump `Stable tag:` and add a `= X.Y.Z =` entry under `== Changelog ==`.
3. **Update `CHANGELOG.md`** — move items from `[Unreleased]` into a new `[X.Y.Z] - YYYY-MM-DD` block.
4. **(Optional) Add release notes file** `docs/RELEASE_NOTES_X.Y.Z.md` — used as the GitHub Release body. If absent, GitHub auto-generates notes from commits.
5. **Run local verification**:
   ```bash
   bash scripts/verify-plugin.sh
   bash scripts/build-zip.sh
   ```
6. **Commit**:
   ```bash
   git add -p
   git commit -m "chore: release v1.0.0"
   git push origin main
   ```
7. **Tag and push**:
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```

The `v*` tag push triggers `.github/workflows/release.yml`, which:
- Runs PHP lint and brand checks.
- Builds `builds/trendplot-connector-X.Y.Z.zip`.
- Verifies ZIP contents.
- Creates a GitHub Release with the ZIP as a release asset.

## ZIP artifact

The production ZIP is named `trendplot-connector-X.Y.Z.zip` and contains only runtime files:

```
trendplot-connector/
├── trendplot-connector.php   ← main plugin file
├── uninstall.php
├── readme.txt
├── LICENSE
└── src/                      ← all PHP source classes
```

Dev-only files excluded from the ZIP: `.git`, `.github`, `scripts/`, `tests/`, `docs/`,
`README.md`, `CHANGELOG.md`, `vendor/`, `node_modules/`, `.gitignore`, `.distignore`.

## Manual ZIP install

Download the ZIP from the [GitHub Releases page](https://github.com/magpern/TrendplotConnector/releases)
and install via **WordPress Admin → Plugins → Add New → Upload Plugin**.

## Local build

```bash
bash scripts/build-zip.sh
# Output: builds/trendplot-connector-X.Y.Z.zip
```

## Rollback

To roll back to a previous version:
1. Download the previous release ZIP from GitHub Releases.
2. Deactivate the current plugin in WordPress Admin.
3. Delete the current plugin folder via FTP/SFTP or the WordPress file manager.
4. Upload and activate the previous ZIP.

Plugin settings (`trendplot_connector_settings` in `wp_options`) are preserved across version changes.
Post content and `_trendplot_*` metadata are never touched by the plugin upgrade/downgrade path.

## Prerelease tags

Tags matching `*alpha*`, `*beta*`, `*rc*`, or `*dev*` are created as GitHub prereleases.
Example: `v1.1.0-beta.1`.
