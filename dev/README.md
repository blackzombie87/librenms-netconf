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

The base class (`tests/Feature/LibrenmsTestCase.php`) enables the plugin in the test database
once (routes and hooks register only for an enabled plugin) and boots the application again.
Users come from LibreNMS's factory with `enabled => 1` (the default is a disabled account).

## Pre-tag checklist

Until the repository has a remote and the `feature` job of `.github/workflows/ci.yml` has run
at least once (plan G17, R5), these run by hand before a tag:

1. `vendor/bin/pest` — unit suite green on the bare checkout (the Feature tests skip there).
2. `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer check --diff` — clean.
3. Feature suite **green in Docker**, the command above; the count belongs in the release
   notes (28 tests at the time of writing). Note that `UninstallTest` drops and re-creates
   the plugin tables of `librenms_test`; it restores them in its teardown, so a suite that
   aborts mid-run can leave the test database without them — re-run
   `DB_CONNECTION=testing php artisan migrate --force` if the next run complains.
4. `lnms netconf:validate` on the definitions, and `--replay` over the fixtures.
5. One live discovery and poll against a real device.
