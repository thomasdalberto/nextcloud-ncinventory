# Local development

This setup uses existing Docker Desktop. PHP, Nextcloud and PostgreSQL run in containers. The server files, database data and optional local tool caches stay under the repository's ignored `.tools/` directory. Docker manages its own images; no Homebrew or system PHP installation is needed.

From the repository root, install the locked PHP dependencies into `vendor/`:

```sh
docker run --rm -v "$PWD:/app" -w /app \
  -e COMPOSER_HOME=/app/.tools/composer \
  composer:2 install --no-interaction --prefer-dist
docker compose -f dev/compose.yaml up -d --wait
docker compose -f dev/compose.yaml exec --user www-data nextcloud \
  php occ app:enable inventorycheck
```

Open <http://127.0.0.1:8086/index.php/apps/inventorycheck/workspaces>. The disposable local admin is `workspace-admin`, with password `workspace-local-test`. Set `NEXTCLOUD_ADMIN_PASSWORD` before the first startup to choose another password. By default the server binds only to localhost; these credentials are for this local environment.

The preview revalidates InventoryCheck's JavaScript, styles, translations and images on reload. After changing the app version, run Nextcloud's upgrade to update its installed version and invalidate existing browser caches:

```sh
docker compose -f dev/compose.yaml exec --user www-data nextcloud php occ upgrade
```

Packaged releases keep Nextcloud's normal asset caching; update `appinfo/info.xml` and `appinfo/version` together for each release.

## Phone debugging on the same Wi-Fi

Copy `dev/.env.example` to `dev/.env` and set:

```dotenv
NEXTCLOUD_BIND_ADDRESS=0.0.0.0
NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1 YOUR_MAC_WIFI_IP"
```

On macOS, `ipconfig getifaddr en0` usually prints the Wi-Fi IP. Start the preview with those local settings:

```sh
docker compose --env-file dev/.env -f dev/compose.yaml up -d --wait
```

For an already installed instance, add the Wi-Fi IP to Nextcloud's trusted domains too. Inspect `php occ config:system:get trusted_domains` and choose an unused numeric index; this example uses `3`:

```sh
docker compose --env-file dev/.env -f dev/compose.yaml exec --user www-data nextcloud \
  php occ config:system:set trusted_domains 3 --value=YOUR_MAC_WIFI_IP
docker compose --env-file dev/.env -f dev/compose.yaml exec --user www-data nextcloud \
  php occ config:system:set overwrite.cli.url --value=http://YOUR_MAC_WIFI_IP:8086
```

Open `http://YOUR_MAC_WIFI_IP:8086/apps/inventorycheck/workspaces` on the phone while connected to the same Wi-Fi. Localhost continues to work on the Mac. The CLI URL makes background-generated email links reachable on both devices. Update the settings if DHCP changes the Mac's address. `dev/.env` stays ignored inside this repository. The LAN setting exposes this development preview on the Mac's network interfaces; use it on your trusted local network.

## Checks

Run the workspace integration tests against real Nextcloud and PostgreSQL:

```sh
docker compose -f dev/compose.yaml exec -T --user www-data \
  -e NEXTCLOUD_ROOT=/var/www/html nextcloud \
  php custom_apps/inventorycheck/vendor/bin/phpunit --do-not-cache-result \
  -c custom_apps/inventorycheck/phpunit.xml \
  custom_apps/inventorycheck/tests/Integration/WorkspaceIsolationIntegrationTest.php
```

Run unit tests by replacing the last path with `--testsuite unit`. Standalone unit runs need Nextcloud's development OCP stubs; the container also supplies the real Nextcloud runtime and its dependencies. Some upstream tests depend on unpublished sibling-app fixtures and ignored `docs/uat/` artifacts, and the upstream invoice test expects an installed sibling app. Those tests cannot all pass in this standalone fork.

The workspace suite also boots Nextcloud Activity and Notifications, verifies invitation buttons in the real HTML/plain-text notification email renderer, and checks revocation and cleanup. It does not send external email. For manual email delivery, configure a test SMTP server in Nextcloud and enable notification emails for the recipient; see [WORKSPACES.md](../WORKSPACES.md#activity-and-notifications). The local setup does not configure an outbound mail server.

The new URL tests need only an existing Node installation:

```sh
node --test tests/js/workspace-urls.test.mjs
```

For the complete existing JavaScript suite, keep npm's cache local:

```sh
npm ci --cache .tools/npm-cache --ignore-scripts
npm test --cache .tools/npm-cache
```

If running Playwright locally, set `PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright"` and `npm_config_cache="$PWD/.tools/npm-cache"` before installing its browser. This keeps browser downloads inside the repository too.

Stop the local services with `docker compose -f dev/compose.yaml down`. Data remains in `.tools/nextcloud` and `.tools/postgres`; deleting those two directories after stopping services resets this disposable instance. The app source is mounted read-only in Nextcloud, so tests use `--do-not-cache-result`.
