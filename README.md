# statamic-content-mcp

<!--delete-->
## Getting Started

1. Press "Use this template" or clone this repo
2. Run `php configure.php` to set up your addon
3. Start building!

### Interactive

```bash
php configure.php
```

Follow the prompts — it'll ask for package name, Statamic version, frontend tooling, etc.

### Non-Interactive (CI / Agents)

The script auto-detects non-interactive mode when `--name` is passed or stdin isn't a TTY. All values have sensible defaults.

```bash
# Minimal — PHP-only addon for Statamic 6
php configure.php --name="My Addon"

# With frontend tooling (Vite + TS + ESLint + Prettier)
php configure.php --name="Magic Actions" --statamic=v5 --frontend --no-install

# Full control
php configure.php \
  --name="Calendar" \
  --vendor=el-schneider \
  --namespace=ElSchneider \
  --author="Jonas List" \
  --email="mail@example.com" \
  --statamic=both \
  --no-frontend \
  --no-install
```

**All flags:**

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | folder name | Package name. Auto-prefixes `statamic-` if missing. |
| `--description` | "A Statamic addon" | Package description |
| `--class` | derived from name | PHP class name prefix (e.g. `MagicActions`) |
| `--author` | `git config user.name` | Author name |
| `--email` | `git config user.email` | Author email |
| `--username` | from `gh` CLI | GitHub username |
| `--vendor` | `soft-lifes` | Vendor slug for Packagist |
| `--namespace` | derived from vendor | PHP namespace prefix |
| `--statamic` | `v6` | `v5`, `v6`, or `both` |
| `--frontend` / `--no-frontend` | no | Include Vite + TS + ESLint + Prettier + Husky |
| `--install` / `--no-install` | yes (interactive) / no (non-interactive) | Run composer/npm install |
| `--keep-script` | no | Don't delete configure.php after running |
| `--help` | | Show help |

<!--/delete-->

A Statamic addon

## Installation

```bash
composer require el-schneider/statamic-content-mcp
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag="statamic-content-mcp-config"
```

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md) for recent changes.

## License

The MIT License (MIT). See [License File](LICENSE.md) for more information.
