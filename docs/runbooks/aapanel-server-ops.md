# aaPanel server ops — runbook

Manual server-side recipes that autodeploy does **not** automate. Read this
only when you actually need one of these tasks; it is deliberately kept out of
CLAUDE.md so it costs nothing on an ordinary session.

Extracted from the one-off scripts that used to litter the project root
(`raise_upload_limits.php`, `install_cron.php`, `find_fpm_ini.php`,
`check_cron.php`), deleted 2026-10-04.

Paths below assume aaPanel + PHP 8.3. Adjust `83` for another PHP version.

---

## Locating the php.ini that PHP-FPM actually loads

aaPanel keeps several `php.ini` files. Confirm which one is live before
editing, or you will edit a file nothing reads:

```bash
find /www/server/php -maxdepth 3 -iname 'php.ini'
/www/server/php/83/bin/php-fpm -i | grep 'Loaded Configuration File'
ps aux | grep -i php-fpm | grep -v grep | head -5
```

Normally: `/www/server/php/83/etc/php.ini`.

---

## Raising upload limits (large file uploads 413 / fail silently)

Two layers must agree — nginx rejects the request before PHP ever sees it, so
raising only php.ini produces a confusing `413 Request Entity Too Large`.

**1. php.ini** — `/www/server/php/83/etc/php.ini`

| Directive | Value |
|---|---|
| `upload_max_filesize` | `1024M` |
| `post_max_size` | `1024M` |
| `memory_limit` | `512M` |
| `max_execution_time` | `600` |

Anchor the `sed` to start-of-line so commented examples are left alone:

```bash
sed -i -E 's/^upload_max_filesize ?= ?.*/upload_max_filesize = 1024M/' /www/server/php/83/etc/php.ini
sed -i -E 's/^post_max_size ?= ?.*/post_max_size = 1024M/'             /www/server/php/83/etc/php.ini
sed -i -E 's/^memory_limit ?= ?.*/memory_limit = 512M/'                /www/server/php/83/etc/php.ini
sed -i -E 's/^max_execution_time ?= ?.*/max_execution_time = 600/'     /www/server/php/83/etc/php.ini
```

**2. nginx** — `/www/server/nginx/conf/nginx.conf`

```bash
sed -i -E 's/client_max_body_size [0-9]+m;/client_max_body_size 1024m;/g' /www/server/nginx/conf/nginx.conf
```

**3. Validate, then reload** — never reload without `nginx -t`; a bad conf
takes every site on the box down:

```bash
/www/server/nginx/sbin/nginx -t && /etc/init.d/nginx reload
/etc/init.d/php-fpm-83 reload
php -i | grep -E 'upload_max_filesize|post_max_size|memory_limit|max_execution_time'
```

The CLI shares the same ini, so that last line is a valid confirmation.

---

## Installing the Laravel scheduler + queue worker cron

> The root crontab on these boxes drives **many** sites. Back it up and make
> the edit idempotent — never `crontab -` a freshly written file.

```bash
crontab -l > /root/crontab.backup.$(date +%Y%m%d-%H%M%S) 2>/dev/null
crontab -l | grep -c 'yoursite.com'     # 0 means not installed yet
```

Only if the count is `0`, append:

```bash
BASE=/www/wwwroot/yoursite.com
PHP=/www/server/php/83/bin/php
(crontab -l 2>/dev/null; cat <<CRON
* * * * * su -s /bin/bash www -c "cd $BASE && $PHP artisan schedule:run" >> /dev/null 2>&1
* * * * * /usr/bin/flock -n /tmp/yoursite-queue.lock su -s /bin/bash www -c "cd $BASE && $PHP artisan queue:work --stop-when-empty --max-time=55 --tries=3" >> /dev/null 2>&1
CRON
) | crontab -
```

Why each part:

- `su -s /bin/bash www` — run as the web user, not root, so files created by
  the scheduler stay writable by PHP-FPM.
- **Second line is a drain-and-exit worker**, not a daemon. Without it queued
  jobs (notifications, mail) are written but never sent.
- `flock -n` — stops a slow minute stacking workers on top of each other.
- `--max-time=55` — each worker exits before the next tick fires.

Verify:

```bash
crontab -l | grep 'yoursite.com'
```

---

## Checking an existing install

```bash
crontab -l | head -20
crontab -u www -l | head -20          # aaPanel sometimes uses the www crontab
/www/server/php/83/bin/php -v | head -1
```
