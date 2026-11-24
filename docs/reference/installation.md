# Install the WordPress MCP plugin: requirements, zip, WP-CLI and source

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## Requirements

| Dependency | Version |
| --- | --- |
| WordPress | >= 6.9 (bundles the Abilities API) |
| PHP | >= 8.1 |
| Composer | for installing from source |

## Installation

Download [wpmcp.zip](https://github.com/wpmcp/wordpress-mcp/releases/latest/download/wpmcp.zip) (always the latest release), upload it under **Plugins > Add New > Upload Plugin**, and activate it. On activation WordPress shows an optional opt-in for update and security emails; **Skip** works and the plugin runs the same either way. The free plugin is complete on its own.

With WP-CLI:

```bash
wp plugin install https://github.com/wpmcp/wordpress-mcp/releases/latest/download/wpmcp.zip --activate
```

If your host refuses the upload for size (the zip is about 4.5 MB and some hosts cap uploads at 2 MB), use the WP-CLI line above, or unzip it into `wp-content/plugins/` so you have `wp-content/plugins/wpmcp/wpmcp.php`.

### WPMCP Pro

WPMCP Pro is a separate plugin, not a licence key for this one. After checkout on the [pricing page](https://wpmcp-pro.com/pricing) you get the licence key and the WPMCP Pro zip, which also stay in your Freemius account. Upload that zip the same way and activate it: it installs into its own folder, `wp-content/plugins/wpmcp-pro/`, and deactivates the free plugin, because only one WPMCP plugin runs on a site at a time. Pro's tools turn on once its licence is activated. Both plugins use the same settings, snapshots, History and logs, so switching keeps them, and while one of them is installed, deleting the other leaves that data in place. GitHub releases carry the free plugin only.

Every [release](https://github.com/wpmcp/wordpress-mcp/releases) also carries `safe-mcp-for-woocommerce.zip` (Safe MCP for WooCommerce, store tools and the safety core only) and `wpmcp-directory-<version>.zip` (the wordpress.org directory edition, without the licensing SDK or Pro). wpmcp is not yet on the wp.org plugin directory (planned).

### Updates

A copy installed from `wpmcp.zip` gets update notices under **Dashboard > Updates** and on the Plugins screen, and updates in one click like any other plugin. The plugin answers WordPress's own update check (the `update_plugins_wpmcp-pro.com` filter, WordPress 5.8+) from the latest [GitHub release](https://github.com/wpmcp/wordpress-mcp/releases): drafts and prereleases are skipped, the package is that release's `wpmcp-<version>.zip`, and **View details** shows its release notes. WPMCP Pro does not use this check: the licensing SDK updates it. The check is a GET to `api.github.com` with a 5 second timeout and a fixed user agent (no site address or site data), cached for 12 hours (1 hour after a failure); the update itself downloads the zip from `github.com`. The update keeps the folder the plugin is installed in and its activation.

To turn it off, for example on a site that deploys the plugin some other way:

```php
add_filter( 'wpmcp_github_updates_enabled', '__return_false' );
```

It is off by default in a git checkout (a `.git` folder next to `wpmcp.php`), so an update never replaces a working copy. The wordpress.org editions do not contain it.

### From source (developers)

```bash
git clone https://github.com/wpmcp/wordpress-mcp.git wp-content/plugins/wpmcp
cd wp-content/plugins/wpmcp
composer install --no-dev
```

Then activate **wpmcp** from the Plugins screen in wp-admin. The folder must be named `wpmcp`.
