<?php

return [
    'panel_types' => ['cpanel', 'aapanel', 'openpanel'],

    'ssh' => [
        'timeout' => env('SSH_TIMEOUT', 30),
        'port' => env('SSH_PORT', 22),
        'auth_methods' => ['password', 'key'],
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
        'api_url' => 'https://api.anthropic.com/v1/messages',
    ],

    'cpanel' => [
        'default_port' => 2083,
        'uapi_path' => '/execute/',
    ],

    'aapanel' => [
        'default_port_http' => 7800,
        'default_port_https' => 7843,
    ],

    'openpanel' => [
        // OpenPanel splits its UI in two: OpenAdmin (server-wide admin, 2087)
        // and OpenPanel (per-user account UI, 2083). The REST API lives on the
        // OpenAdmin port — see https://dev.openpanel.com/api/
        'default_admin_port' => 2087,
        'default_user_port' => 2083,
        'api_path' => '/api/',
    ],

    'deploy' => [
        'excluded_paths' => [
            '.env',
            'node_modules',
            'vendor',
            '.git',
            'tests',
            'public/hot',
            '.claude',
            'CLAUDE.md',
            // aaPanel writes .user.ini (open_basedir) into the site root and marks it
            // immutable (chattr +i) — unzip dies with "Operation not permitted" trying
            // to replace it. It's server-managed config; never ship it (any depth —
            // source trees pulled down from a server often contain one).
            '.user.ini',
            'public/.user.ini',
            // Storage — user uploads + per-env cache must survive deploys
            'storage/logs',
            'storage/app/public',
            // Published mobile builds. Same reasoning as user uploads: an APK
            // is put on the server once per release and must not be shipped
            // from, or clobbered by, a code deploy.
            'storage/app/releases',
            // A mobile-app project kept beside the web app (e.g. a Flutter app in
            // <project>/mobile/). It is not part of the website, and its build
            // output runs to gigabytes — the CBPA one is ~4 GB. The trailing slash
            // limits this to a top-level `mobile` directory.
            'mobile/',
            // Digital-repository data (HSKP portal): preservation masters, derivatives,
            // replica copies, BagIt exports and resumable-upload chunks. Server-side
            // content that must never be shipped from, or overwritten by, a laptop.
            'storage/app/repository',
            'storage/app/repository-secondary',
            'storage/app/bags',
            'storage/app/private',
            'storage/app/tmp',
            'storage/framework',
            // A Flutter app living beside the Laravel one (CoraDocs keeps its
            // in /mobile) puts gigabytes of build output inside the source
            // tree — 3.4GB of build/ and 387MB of .dart_tool on CBPA alone,
            // which the zip walked file by file and uploaded on every deploy.
            // None of it is served by the web server. A project without a
            // Flutter app has neither directory, so this costs it nothing.
            'mobile/build',
            'mobile/.dart_tool',
            // Laravel's compiled caches contain absolute paths from the build machine.
            // On Windows → Linux, these break with "Please provide a valid cache path".
            'bootstrap/cache/config.php',
            'bootstrap/cache/routes-v7.php',
            'bootstrap/cache/services.php',
            'bootstrap/cache/packages.php',
            'bootstrap/cache/events.php',
        ],
        'dir_permissions' => '755',
        'file_permissions' => '644',
        'writable_dirs' => ['storage', 'bootstrap/cache'],
        'writable_perms' => '775',
        'snapshots_path' => storage_path('app/snapshots'),
        'keep_snapshots' => 5,
        'disk_warning_mb' => 500,
        'min_php_version' => '8.2',
    ],

    'health' => [
        // Laravel ships /up out of the box; the old /health default meant almost
        // every site 404'd and the check silently degraded to a warning.
        'endpoint' => env('AUTOPILOT_HEALTH_PATH', '/up'),
        'timeout' => 10,
        'retries' => 3,
        'retry_seconds' => 5,
        'expected_codes' => [200],
    ],

    'queue' => [
        'worker_timeout' => 300,
        'tries' => 1,
        'queue_names' => 'deployments,default',
    ],

    'tenancy' => [
        // Fallback used when a deployed site's .env omits TENANCY_DATABASE_PREFIX.
        // Must match the default baked into the deployed app's config/tenancy.php,
        // or tenant data is pushed into a database the app never reads.
        'default_prefix' => env('TENANCY_DEFAULT_PREFIX', 'alpha_elibrary_tenant_'),
    ],

    'pgsql' => [
        // pg_dump / psql binaries used to push the local source project's Postgres
        // database to the remote site. Override via .env if not on PATH.
        'dump_binary' => env('PGDUMP_BINARY', 'pg_dump'),
        'client_binary' => env('PSQL_BINARY', 'psql'),
        // Path to psql on the REMOTE server. aaPanel typically installs it at
        // /www/server/pgsql/bin/psql. Override via REMOTE_PSQL_BINARY in .env.
        'remote_client_binary' => env('REMOTE_PSQL_BINARY', '/www/server/pgsql/bin/psql'),
        'skip_tables' => [
            // migrations intentionally NOT skipped — full --clean dump must include
            // migrations data so remote `migrate --force` finds all rows and does nothing.
            'cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs',
            'job_batches', 'password_reset_tokens', 'personal_access_tokens',
        ],
    ],

    'mysql' => [
        // mysqldump binary used to dump the local source project's database
        // when the user triggers a "+ DB" deploy. Override via .env if Laragon's
        // MySQL isn't on PATH: MYSQLDUMP_BINARY=C:\laragon\bin\mysql\...\bin\mysqldump.exe
        'dump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
        // Tables excluded from the local→remote push. These are session/queue/
        // cache tables that should stay isolated per-environment.
        'skip_tables' => [
            // migrations is per-environment state — pushing local rows
            // collides with the remote rows just inserted by migrate --force.
            'migrations',
            // Session / queue / cache tables stay isolated per-env.
            'cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs',
            'job_batches', 'password_reset_tokens', 'personal_access_tokens',
        ],
    ],
];
