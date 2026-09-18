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
