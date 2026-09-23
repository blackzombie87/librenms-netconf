# Development environment

Local LibreNMS test instance (Docker / OrbStack) used while developing this plugin.

| Container | Purpose |
|---|---|
| `librenms-dev-db` | MariaDB 11, published on 127.0.0.1:33306 |
| `lnms-web` | image `lnms-devweb` (Alpine, PHP 8.4), `php artisan serve` on 127.0.0.1:8001, LibreNMS checkout in the `lnms-code` volume at `/code/librenms_new`, runs as uid 501 (`dev`) |

`lnms-web` is started with this plugin bind-mounted and registered as a composer path
repository (`/code/librenms-netconf`, symlinked into `vendor/saffer-it/librenms-netconf`),
so edits on the host are live in the container:

```bash
docker start librenms-dev-db
docker run -d --name lnms-web -p 127.0.0.1:8001:8001 \
  -v lnms-code:/code \
  -v "$PWD:/code/librenms-netconf" \
  -e DB_HOST=host.docker.internal -e DB_PORT=33306 -w /code/librenms_new \
  lnms-devweb sh -c 'adduser -D -u 501 dev 2>/dev/null; export LIBRENMS_USER=dev; exec su -m dev -c "php artisan serve --host 0.0.0.0 --port 8001"'

# one-time: path repository + install (lnms refuses to run as root)
docker exec -u 501 -e HOME=/tmp -e LIBRENMS_USER=dev lnms-web sh -c \
  'cd /code/librenms_new && php lnms plugin:add saffer-it/librenms-netconf "@dev"'
```

Running the plugin commands inside the instance:

```bash
alias lnms-dev='docker exec -u 501 -e HOME=/tmp -e LIBRENMS_USER=dev lnms-web sh -c'
lnms-dev 'cd /code/librenms_new && php lnms netconf:test 10.0.0.5 -u librenms -P'
lnms-dev 'cd /code/librenms_new && php lnms netconf:run 10.0.0.5 show version'
```

Files matching `dev/*.local.*` are ignored by git and can hold throw-away helper scripts
that are executed inside the container via `/code/librenms-netconf/dev/…`.

## The device tab seam

The NETCONF device tab (`src/Http/DeviceTab/`) is not a plugin hook: core has none for device
tabs. `TabRegistration::register()` inserts `NetconfTab` into `App\View\Components\Device\PageTabs::$tabsClasses`
(public static array, tab order = array order, inserted before `edit`) and adds
`resources/lnms-views` to the default view path so core's `view()->exists('device.tabs.netconf')`
finds the Blade file. Verified against LibreNMS 26.7.0 (the dev instance and CI) and the
26.7.0-280 master checkout at `~/VScode/librenms_new` on 2026-09-22; `DeviceController::index`
reads the tab from the third path segment, the plugin reads its section from the fourth.
When the seam disappears, `register()` logs once and `DevicePage::url()` falls back to the
standalone `/plugin/netconf/device/{id}[/section]` pages — CI's pinned core tag is where a seam
change shows up first. Upstreaming a `DeviceTabHook` (plan §8 U5) removes the need for this.

## Static analysis

`vendor/bin/phpstan analyse` runs at level 6 with the Larastan extension. Larastan wants to
boot the application it analyses; the application here is LibreNMS, which is not part of the
checkout, so `dev/phpstan-bootstrap.php` stands in for it: a bare `Illuminate\Foundation\Application`
with the providers Larastan resolves while analysing (config, filesystem, events, view), plus
the two view locations `NetconfPluginProvider` adds at runtime — the `netconf::` namespace and
`resources/lnms-views`. That last part is what makes `view('netconf::status')` check out as a
`view-string`: a Blade file that does not exist fails the analysis.

What LibreNMS's own classes look like to the analysis is `stubs/librenms.stub.php`, so a
relation or method used from core has to be declared there (`Device::attribs()` for instance).

## Feature tests

`tests/Feature/` runs against a LibreNMS installation and its **testing** database connection
(`config/database.php`: `DB_TEST_*`), every test inside a transaction. On a bare checkout
(`composer test`) the suite loads and skips itself. Inside the dev instance:

```bash
# one-time: a test database the librenms user may use, its settings in the LibreNMS .env,
# the schema (Laravel loads database/schema/testing-schema.sql through the mysql client)
docker exec librenms-dev-db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE librenms_test; GRANT ALL ON librenms_test.* TO librenms@\"%\""'
docker exec -u root lnms-web apk add --no-cache mariadb-client
lnms-dev 'cd /code/librenms_new && printf "DB_TEST_HOST=host.docker.internal\nDB_TEST_PORT=33306\nDB_TEST_DATABASE=librenms_test\nDB_TEST_USERNAME=librenms\nDB_TEST_PASSWORD=<DB_PASSWORD>\n" >> .env'
lnms-dev 'cd /code/librenms_new && DB_CONNECTION=testing php artisan migrate --force'   # not lnms migrate

# every run: the plugin's phpunit with the LibreNMS autoloader in front (tests/Feature/bootstrap.php)
lnms-dev 'cd /code/librenms-netconf && LIBRENMS_PATH=/code/librenms_new php vendor/bin/phpunit -c phpunit.feature.xml'
```

Gotcha for a fresh checkout (the CI job builds one): LibreNMS's `composer install` ends with
`artisan optimize`, so a route cache written *before* the plugin was required hides every
plugin route (legacy 404 with an open output buffer, nine HTTP tests red). Run
`php artisan route:clear` after adding the plugin; `optimize:clear` needs the default database
connection. LibreNMS also refuses artisan for any user other than `LIBRENMS_USER`, and
`composer require` exits in its `pre-update-cmd` hook unless `FORCE=1` is set.

The base class (`tests/Feature/LibrenmsTestCase.php`) enables the plugin in the test database
once (routes and hooks register only for an enabled plugin) and boots the application again.
Users come from LibreNMS's factory with `enabled => 1` (the default is a disabled account).

## Pre-tag checklist

The `feature` job of `.github/workflows/ci.yml` runs steps 1–4 on every push (first green run
2026-09-22, 40 tests against LibreNMS 26.7.0); before a tag they still run by hand here, and
steps 5–6 only exist here because they need the fixtures directory and a real device:

1. `vendor/bin/pest` — unit suite green on the bare checkout (the Feature tests skip there).
2. `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer check --diff` — clean.
3. `actionlint` (`brew install actionlint`) — the workflow file validates. GitHub rejects a
   workflow with an expression in the wrong place *before* any job runs, which a green local
   suite cannot show; the `workflow` job runs the same check in CI.
4. Feature suite **green in Docker**, the command above, with **no skipped test**
   (`phpunit.feature.xml` sets `failOnSkipped`, so a container without `rrdtool` fails the
   run instead of hiding `RrdLayoutTest`); the count belongs in the release notes. Note that
   `UninstallTest` drops and re-creates the plugin tables of `librenms_test`; it restores
   them in its teardown, so a suite that aborts mid-run can leave the test database without
   them — re-run `DB_CONNECTION=testing php artisan migrate --force` if the next run complains.
5. `lnms netconf:validate` on the definitions, and `--replay` over the fixtures.
6. One live discovery and poll against a real device.
