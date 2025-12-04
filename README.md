![Banner](./docs/img/banner.png)

# Lantern for Craft CMS

Lantern tracks how many times each of your templates is used and displays the data in a useful utility. This can help you make informed decisions about when to legacy templates to keep your codebase clean.

Lantern also provides an optional legacy templates folder, where you can move templates to if you're not quite ready to delete them.

## 🔧 Quick Start

### 1. Install Lantern

You can install Lantern by searching for "Lantern" in the Craft Plugin Store, or install manually using composer.

```bash
composer require tallowandsons/craft-lantern
```
## Requirements
This plugin supports
- Craft CMS 5.0.0 or later

## Optional Configuration and commands

### Automatic flushing and scanning

For performance, Lantern initially stores template logging data in the cache. This needs to be periodically flushed to the database. By default Lantern will do this automatically during a web request. If you'd rather set this via a cronjob, you can do this using the `php craft lantern/cache/flush` command.

Lantern occasionally needs to scan the @templates directory to keep its inventory up to date. By default a scan will run once per day during a web request. If you'd rather, you can also run this via the `php craft lantern/inventory/scan` command.

## Credits

Made with care and attention by [Tallow &amp; Sons](https://github.com/tallowandsons)
