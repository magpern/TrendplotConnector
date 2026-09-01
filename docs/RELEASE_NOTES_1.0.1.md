# Trendplot Connector 1.0.1 — release notes

## Added

- **Self-updates** from a private update server. The plugin bundles
  [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) v5
  (`lib/plugin-update-checker/`) and registers it only when the
  `PRIVATE_UPDATE_SERVER` constant is defined in `wp-config.php`. With no
  constant defined the plugin behaves exactly as 1.0.0.
- CI workflow that publishes the release package to the update server on each
  `v*` tag.

## Install

Deploy `trendplot-connector` **1.0.1** / tag **`v1.0.1`**. Define
`PRIVATE_UPDATE_SERVER` in `wp-config.php` on the target site to receive updates.

Rollback: **1.0.0** / `v1.0.0`.
