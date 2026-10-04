<?php

namespace App\Services;

use App\Models\Site;
use RuntimeException;

/**
 * Pushes the local DB rows of a Laravel project to the remote DB configured for
 * the deployed site. Supports MySQL/MariaDB and PostgreSQL.
 *
 * Local creds come from {site.source_path}/.env. Remote creds come from
 * site.env_content (the encrypted .env autodeploy writes to the server in
 * Phase 4). Schema is owned by `migrate --force`; we dump rows only so a row
 * push can never break the schema the migrations just created.
 */
class DatabaseSyncService
{
    /** Raw .env bodies, kept so tenancy settings can be read beyond DB_* keys. */
    private string $localEnvRaw = '';

    private string $remoteEnvRaw = '';

    /**
     * @return array{rows:int, sizeMb:float}
     */
    /**
     * Dump the remote database to {deploy_path}/shared/backups/{id}.sql.gz
     * before a push overwrites it.
     *
     * Hard failure by design: if we cannot prove a backup exists, we do not
     * proceed to overwrite live data. Credentials go through the environment
     * (PGPASSWORD / MYSQL_PWD) so they never appear in the process list.
     */
    private function backupRemoteDatabase(
        SshService $ssh,
        Site $site,
        array $remote,
        int $deploymentId,
    ): void {
        $dir = rtrim($site->deploy_path, '/').'/shared/backups';
        $file = $dir.'/'.$deploymentId.'.sql.gz';

        $ssh->exec('mkdir -p '.escapeshellarg($dir));

        $host = escapeshellarg($remote['host']);
        $port = escapeshellarg((string) $remote['port']);
        $user = escapeshellarg($remote['user']);
        $db = escapeshellarg($remote['database']);
        $out = escapeshellarg($file);

        // Dump to a plain file first, then compress. Piping straight into gzip
        // would mask a failed dump behind gzip's exit code, and PIPESTATUS is a
        // bashism we can't rely on across login shells. `&&` short-circuits in
        // any POSIX shell, so a failed dump never produces a backup file.
        $tmp = '/tmp/autopilot_backup_'.$deploymentId.'.sql';
        $tmpArg = escapeshellarg($tmp);

        if ($remote['driver'] === 'pgsql') {
            $dump = 'PGPASSWORD='.escapeshellarg($remote['password'])
                ." pg_dump -h {$host} -p {$port} -U {$user} {$db} > {$tmpArg}";
        } else {
            $dump = 'MYSQL_PWD='.escapeshellarg($remote['password'])
                ." mysqldump --host={$host} --port={$port} --user={$user} --single-transaction --quick {$db} > {$tmpArg}";
        }

        $cmd = "{$dump} && gzip -f {$tmpArg} && mv ".escapeshellarg($tmp.'.gz')." {$out}";

        $result = $ssh->exec($cmd.' 2>&1');
        $exit = $result['exit_code'] ?? 1;

        if ($exit !== 0) {
            throw new RuntimeException(
                'Refusing to push: remote database backup failed (exit '.$exit.'). '
                .'No data was overwritten. Output: '.trim($result['output'] ?? '')
            );
        }

        $size = trim($ssh->exec('stat -c %s '.$out.' 2>/dev/null || echo 0')['output'] ?? '0');

        if ((int) $size <= 0) {
            throw new RuntimeException(
                'Refusing to push: remote database backup is empty ('.$file.'). '
                .'No data was overwritten.'
            );
        }
    }

    public function pushLocalToRemote(
        SshService $ssh,
        Site $site,
        string $sourcePath,
        int $deploymentId,
    ): array {
        $this->localEnvRaw = $this->readLocalEnv($sourcePath);
        $this->remoteEnvRaw = $site->env_content ?? '';

        $local = $this->parseEnvDb($this->localEnvRaw);
        $remote = $this->parseEnvDb($this->remoteEnvRaw);

        $this->assertCreds($local, 'local');
        $this->assertCreds($remote, 'remote');

        if ($local['driver'] !== $remote['driver']) {
            throw new RuntimeException(
                "DB_CONNECTION mismatch: local is {$local['driver']}, remote is {$remote['driver']}. ".
                'Cross-engine sync is not supported.'
            );
        }

        $dumpFile = storage_path('app/db_push_'.$deploymentId.'.sql');
        $remoteTmp = '/tmp/autopilot_db_'.$deploymentId.'.sql';

        // F7: take a remote backup BEFORE overwriting anything. The import uses
        // REPLACE, so without this a bad push is unrecoverable.
        $this->backupRemoteDatabase($ssh, $site, $remote, $deploymentId);

        try {
            if ($local['driver'] === 'pgsql') {
                $this->dumpLocalPgsql($local, $dumpFile);
            } else {
                $this->dumpLocalMysql($local, $dumpFile);
            }
            $sizeMb = round(filesize($dumpFile) / 1024 / 1024, 2);

            $ssh->uploadFile($dumpFile, $remoteTmp);
            $ssh->reconnect();

            if ($remote['driver'] === 'pgsql') {
                $this->importRemotePgsql($ssh, $remote, $remoteTmp);
            } else {
                $this->importRemoteMysql($ssh, $remote, $remoteTmp);
            }

            // Opt-in second pass: stancl/tenancy keeps each library's real data in
            // its OWN database, which .env never names — DB_DATABASE is only the
            // central one (tenants, plans, settings). Without this the catalog is
            // silently left behind while the deploy reports success.
            //
            // Gated on the site flag because a real multi-tenant SaaS has one
            // database per paying customer; pushing local copies over those would
            // destroy live data. Single-library installs only.
            if ($site->push_tenant_databases) {
                $sizeMb += $this->pushTenantDatabases($ssh, $local, $remote, $deploymentId);
            }

            return ['rows' => 0, 'sizeMb' => $sizeMb];
        } finally {
            @unlink($dumpFile);
        }
    }

    /**
     * Dump every local tenant database and restore it on the remote, creating the
     * remote database first if it does not exist.
     *
     * Tenant DB names are {TENANCY_DATABASE_PREFIX}{tenant uuid}. The prefix can
     * differ between local and remote .env files, so it is read from each side
     * rather than assumed; the uuid comes from the central DB's tenants table,
     * which this method reads AFTER the central push above — so local and remote
     * already agree on which tenants exist.
     *
     * @return float megabytes transferred
     */
    private function pushTenantDatabases(
        SshService $ssh,
        array $local,
        array $remote,
        int $deploymentId,
    ): float {
        if ($local['driver'] !== 'pgsql') {
            throw new RuntimeException(
                'Tenant database push currently supports PostgreSQL only; '
                ."this site is {$local['driver']}."
            );
        }

        $localPrefix = $this->tenancyPrefix($this->localEnvRaw);

        $tenants = $this->localTenantIds($local);
        if ($tenants === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($tenants as $i => [$uuid, $storedDbName]) {
            $localDb = $storedDbName ?: ($localPrefix.$uuid);

            // The REMOTE database must carry the same name, because stancl reads
            // tenancy_db_name out of the tenant row's data JSON — and that row has
            // just been overwritten by the central push, so it now names the LOCAL
            // database. Deriving the remote name from the remote prefix instead
            // creates a database the application never looks at: the push reports
            // success, and every request then 500s with "database … does not exist".
            $remoteDb = $localDb;

            $dumpFile = storage_path("app/db_tenant_{$deploymentId}_{$i}.sql");
            $remoteTmp = "/tmp/autopilot_tenant_{$deploymentId}_{$i}.sql";

            try {
                $this->dumpLocalPgsql(['db' => $localDb] + $local, $dumpFile);
                $total += round(filesize($dumpFile) / 1024 / 1024, 2);

                // The tenant DB is created by the app on the SaaS, but on a fresh
                // single install it may not exist yet — psql -f would fail on a
                // missing database before it could run a single statement.
                $this->ensureRemoteDatabase($ssh, $remote, $remoteDb);

                $ssh->uploadFile($dumpFile, $remoteTmp);
                $ssh->reconnect();

                $this->importRemotePgsql($ssh, ['db' => $remoteDb] + $remote, $remoteTmp);
            } finally {
                @unlink($dumpFile);
            }
        }

        return $total;
    }

    /**
     * TENANCY_DATABASE_PREFIX from a raw .env body.
     *
     * The fallback matters: config/tenancy.php in Alpha eLibrary declares
     * env('TENANCY_DATABASE_PREFIX', 'alpha_elibrary_tenant_'), and a server whose
     * .env omits the key really does use that default — its tenant databases are
     * named alpha_elibrary_tenant_{uuid}. Guessing a different fallback here would
     * push into a database the application never reads, and the deploy would
     * report success with an empty catalog.
     */
    private function tenancyPrefix(string $env): string
    {
        if (preg_match('/^\s*TENANCY_DATABASE_PREFIX\s*=\s*(.*)$/m', $env, $m)) {
            $val = trim($m[1]);
            $val = preg_replace('/\s+#.*$/', '', $val);
            if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
                || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }

            return $val;
        }

        return config('autopilot.tenancy.default_prefix', 'alpha_elibrary_tenant_');
    }

    /**
     * Tenants from the local central database as [uuid, tenancy_db_name] pairs.
     *
     * tenancy_db_name lives in the row's data JSON and is authoritative — stancl
     * uses it verbatim rather than recomputing from the prefix, so a tenant created
     * under a different prefix keeps its original database name forever.
     *
     * @return list<array{0:string,1:?string}>
     */
    private function localTenantIds(array $c): array
    {
        $psql = escapeshellarg(config('autopilot.pgsql.client_binary', 'psql'));
        $cmd = $psql
            .' --host='.escapeshellarg($c['host'])
            .' --port='.escapeshellarg($c['port'])
            .' --username='.escapeshellarg($c['user'])
            .' --dbname='.escapeshellarg($c['db'])
            .' -tAc '.escapeshellarg(
                "SELECT id || '\t' || COALESCE(data->>'tenancy_db_name', '') FROM tenants"
            )
            .' 2>&1';

        $ids = [];
        $this->withEnv('PGPASSWORD', $c['pass'], function () use ($cmd, &$ids) {
            $out = [];
            $exit = 0;
            exec($cmd, $out, $exit);
            if ($exit !== 0) {
                throw new RuntimeException(
                    'Could not read tenants from the local central database: '
                    .trim(implode("\n", $out))
                );
            }
            foreach ($out as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = explode("\t", $line, 2);
                $ids[] = [$parts[0], ($parts[1] ?? '') !== '' ? $parts[1] : null];
            }
        });

        return $ids;
    }

    /** CREATE DATABASE on the remote if it is not already there. */
    private function ensureRemoteDatabase(SshService $ssh, array $c, string $db): void
    {
        $psqlBin = escapeshellarg(config('autopilot.pgsql.remote_client_binary', 'psql'));
        $base = 'PGPASSWORD='.escapeshellarg($c['pass'])." {$psqlBin}"
            .' --host='.escapeshellarg($c['host'])
            .' --port='.escapeshellarg($c['port'])
            .' --username='.escapeshellarg($c['user']);

        // Connect to the central DB to issue CREATE DATABASE — you cannot create a
        // database from inside the one being created, and "postgres" may not be
        // reachable for this role.
        $check = $ssh->exec(
            $base.' --dbname='.escapeshellarg($c['db'])
            // Double-quoted for the remote shell so the single quotes Postgres needs
            // around the string literal survive — escapeshellarg() would not, for the
            // Windows reason described below.
            ." -tAc \"SELECT 1 FROM pg_database WHERE datname = '{$db}'\""
            .' 2>&1'
        );

        if (trim($check['output'] ?? '') === '1') {
            return;
        }

        $create = $ssh->exec(
            $base.' --dbname='.escapeshellarg($c['db'])
            // Quoted by hand, not with escapeshellarg(): this runs from Windows,
            // where escapeshellarg() emits DOUBLE quotes and mangles the inner ones
            // the identifier needs. A tenant database name contains hyphens (it ends
            // in a uuid), so Postgres rejects it unquoted — CREATE DATABASE
            // alpha_elibrary_tenant_ee10543c-20a4-… is a syntax error at "-".
            ." -c 'CREATE DATABASE \"{$db}\"'"
            .' 2>&1; echo "CREATE_EXIT:$?"'
        );

        $exit = preg_match('/CREATE_EXIT:(\d+)/', $create['output'] ?? '', $m) ? (int) $m[1] : 1;
        if ($exit !== 0) {
            throw new RuntimeException(
                "Could not create remote tenant database {$db}: "
                .preg_replace('/CREATE_EXIT:\d+\s*$/', '', $create['output'] ?? '')
            );
        }
    }

    private function readLocalEnv(string $sourcePath): string
    {
        $envPath = rtrim($sourcePath, '/\\').DIRECTORY_SEPARATOR.'.env';
        if (! is_file($envPath)) {
            throw new RuntimeException(
                "Local .env not found at {$envPath}. Cannot read local DB credentials."
            );
        }

        return (string) file_get_contents($envPath);
    }

    /**
     * @return array{driver:string,host:string,port:string,db:string,user:string,pass:string}
     */
    private function parseEnvDb(string $env): array
    {
        // Normalize Windows CRLF / classic-Mac CR to LF. Without this, the
        // line-end `$` anchor below fails on a stray \r and every key reads
        // as empty — which surfaced as a false "Missing DB_DATABASE" error.
        $env = preg_replace('/\r\n?/', "\n", $env);

        $get = function (string $key) use ($env): string {
            if (preg_match('/^[ \t]*'.preg_quote($key, '/').'[ \t]*=[ \t]*([^\r\n]*)$/m', $env, $m)) {
                $val = trim($m[1]);
                $val = preg_replace('/\s+#.*$/', '', $val);
                if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
                    || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }

                return $val;
            }

            return '';
        };

        $driver = strtolower($get('DB_CONNECTION') ?: 'mysql');
        $defaultPort = $driver === 'pgsql' ? '5432' : '3306';

        return [
            'driver' => $driver,
            'host' => $get('DB_HOST') ?: '127.0.0.1',
            'port' => $get('DB_PORT') ?: $defaultPort,
            'db' => $get('DB_DATABASE'),
            'user' => $get('DB_USERNAME'),
            'pass' => $get('DB_PASSWORD'),
        ];
    }

    private function assertCreds(array $c, string $label): void
    {
        if ($c['db'] === '' || $c['user'] === '') {
            throw new RuntimeException(
                "Missing DB_DATABASE or DB_USERNAME in {$label} .env — cannot sync database."
            );
        }
        if (! in_array($c['driver'], ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException(
                "Unsupported DB_CONNECTION '{$c['driver']}' in {$label} .env — only mysql/mariadb/pgsql are supported."
            );
        }
    }

    // ---------- MySQL ----------

    private function dumpLocalMysql(array $c, string $outFile): void
    {
        $bin = config('autopilot.mysql.dump_binary', 'mysqldump');
        $skip = (array) config('autopilot.mysql.skip_tables', []);

        $args = [
            '--host='.$c['host'],
            '--port='.$c['port'],
            '--user='.$c['user'],
            '--single-transaction',
            '--quick',
            '--no-create-info',
            '--no-create-db',
            '--skip-triggers',
            '--skip-add-locks',
            '--skip-comments',
            '--complete-insert',
            '--replace',
            '--default-character-set=utf8mb4',
        ];
        foreach ($skip as $t) {
            $args[] = '--ignore-table='.$c['db'].'.'.$t;
        }
        $args[] = $c['db'];

        $cmd = escapeshellarg($bin);
        foreach ($args as $a) {
            $cmd .= ' '.escapeshellarg($a);
        }
        $cmd .= ' > '.escapeshellarg($outFile).' 2> '.escapeshellarg($outFile.'.err');

        $this->withEnv('MYSQL_PWD', $c['pass'], function () use ($cmd, $outFile) {
            $exit = 0;
            $out = [];
            exec($cmd, $out, $exit);
            if ($exit !== 0) {
                $err = @file_get_contents($outFile.'.err') ?: '';
                throw new RuntimeException("mysqldump failed (exit {$exit}): ".trim($err));
            }
            @unlink($outFile.'.err');
        });
    }

    private function importRemoteMysql(SshService $ssh, array $c, string $remoteFile): void
    {
        $host = escapeshellarg($c['host']);
        $port = escapeshellarg($c['port']);
        $user = escapeshellarg($c['user']);
        $db = escapeshellarg($c['db']);
        $pass = escapeshellarg($c['pass']);
        $file = escapeshellarg($remoteFile);

        $cmd =
            "( echo 'SET FOREIGN_KEY_CHECKS=0;'; cat {$file}; echo 'SET FOREIGN_KEY_CHECKS=1;' ) | ".
            "MYSQL_PWD={$pass} mysql ".
            "--host={$host} --port={$port} --user={$user} ".
            "--default-character-set=utf8mb4 {$db} 2>&1; ".
            "EXIT_CODE=\$?; rm -f {$file}; echo \"DBPUSH_EXIT:\$EXIT_CODE\"";

        $result = $ssh->exec($cmd);
        $exit = preg_match('/DBPUSH_EXIT:(\d+)/', $result['output'], $m) ? (int) $m[1] : 1;

        if ($exit !== 0) {
            $body = preg_replace('/DBPUSH_EXIT:\d+\s*$/', '', $result['output']);
            throw new RuntimeException('Remote mysql import failed: '.trim($body));
        }
    }

    // ---------- PostgreSQL ----------

    private function dumpLocalPgsql(array $c, string $outFile): void
    {
        $bin = config('autopilot.pgsql.dump_binary', 'pg_dump');
        $skip = (array) config('autopilot.pgsql.skip_tables', []);

        $args = [
            '--host='.$c['host'],
            '--port='.$c['port'],
            '--username='.$c['user'],
            '--dbname='.$c['db'],
            '--clean',           // DROP before CREATE — clears existing data/schema
            '--if-exists',       // don't error if objects don't exist yet
            '--no-owner',
            '--no-privileges',
            '--no-comments',
            '--encoding=UTF8',
        ];
        foreach ($skip as $t) {
            $args[] = '--exclude-table-data='.$t;
        }

        $cmd = escapeshellarg($bin);
        foreach ($args as $a) {
            $cmd .= ' '.escapeshellarg($a);
        }
        $cmd .= ' > '.escapeshellarg($outFile).' 2> '.escapeshellarg($outFile.'.err');

        $this->withEnv('PGPASSWORD', $c['pass'], function () use ($cmd, $outFile) {
            $exit = 0;
            $out = [];
            exec($cmd, $out, $exit);
            if ($exit !== 0) {
                $err = @file_get_contents($outFile.'.err') ?: '';
                throw new RuntimeException("pg_dump failed (exit {$exit}): ".trim($err));
            }
            @unlink($outFile.'.err');
        });

        $this->stripUnportableExtensions($outFile);
    }

    /**
     * Remove optional-extension dependencies the target server may not have.
     *
     * pgvector is the case this exists for: a dev box with the extension dumps
     * both CREATE EXTENSION vector and a vector-typed column, and a server without
     * it fails the whole restore — first on the extension, then on the unknown
     * type. Alpha eLibrary treats pgvector as optional (the add_vector_search_support
     * migration checks pg_available_extensions and logs "semantic search disabled"
     * rather than failing), so a database without the column is a supported state
     * and stripping it here is safe.
     *
     * Only the schema is touched. If the column ever holds data, that data is NOT
     * transferred — acceptable while embeddings are regenerable from the records
     * themselves, which is how the app treats them.
     */
    private function stripUnportableExtensions(string $file): void
    {
        $in = @fopen($file, 'r');
        if ($in === false) {
            return;
        }

        $tmp = $file.'.filtered';
        $out = fopen($tmp, 'w');

        // Index of the embedding field inside the COPY block currently being read,
        // or null when not inside one. The data section is tab-separated and can be
        // hundreds of megabytes, so the file is streamed rather than loaded whole.
        $dropField = null;

        // One-line lookbehind, so a dropped final column's trailing comma can be
        // repaired on the line before ");".
        $prev = null;

        $emit = function (?string $line) use ($out) {
            if ($line !== null) {
                fwrite($out, $line);
            }
        };

        while (($line = fgets($in)) !== false) {
            if ($dropField !== null) {
                if (rtrim($line, "\r\n") === '\.') {
                    $dropField = null;
                    $emit($prev);
                    $prev = $line;

                    continue;
                }

                $eol = str_ends_with($line, "\r\n") ? "\r\n" : "\n";
                $fields = explode("\t", rtrim($line, "\r\n"));
                if (count($fields) > $dropField) {
                    array_splice($fields, $dropField, 1);
                }

                $emit($prev);
                $prev = implode("\t", $fields).$eol;

                continue;
            }

            // COPY header naming the column: drop it from the list and remember
            // which field position to strip from the rows that follow.
            if (preg_match('/^COPY\s+\S+\s*\(([^)]*)\)\s+FROM stdin;/i', $line, $m)) {
                $cols = array_map('trim', explode(',', $m[1]));
                $idx = array_search('embedding', $cols, true);
                if ($idx !== false) {
                    $dropField = $idx;
                    unset($cols[$idx]);
                    $line = preg_replace(
                        '/\(([^)]*)\)/',
                        '('.implode(', ', $cols).')',
                        $line,
                        1
                    );
                }
            }

            // Extension statements. \b around "vector" matters: the same dump is
            // full of tsvector artefacts (search_vector columns,
            // update_biblio_search_vector functions, trig_*_search_vector triggers)
            // that are plain PostgreSQL and must survive untouched.
            $drop = preg_match('/^\s*CREATE EXTENSION[^;]*\bvector\b[^;]*;\s*$/i', $line)
                || preg_match('/^\s*DROP EXTENSION IF EXISTS\s+vector\s*;\s*$/i', $line)
                || preg_match('/^\s*COMMENT ON EXTENSION\s+vector[^;]*;\s*$/i', $line)
                // The vector-typed column inside CREATE TABLE, and any ALTER TABLE
                // that adds it.
                || preg_match('/^\s*embedding\s+(public\.)?vector\([0-9]+\),?\s*$/i', $line)
                || preg_match('/^\s*ALTER TABLE[^;]*ADD COLUMN\s+embedding\s+(public\.)?vector[^;]*;\s*$/i', $line)
                // ivfflat/hnsw indexes over the dropped column, plus the DROP INDEX
                // a --clean dump emits for them near the top of the file.
                || preg_match('/^\s*CREATE INDEX[^;]*USING\s+(ivfflat|hnsw)[^;]*;\s*$/i', $line)
                || preg_match('/^\s*DROP INDEX IF EXISTS[^;]*embedding_idx\s*;\s*$/i', $line);

            if ($drop) {
                continue;
            }

            // Repair a dangling comma if the dropped column was the last one.
            if ($prev !== null && preg_match('/^\s*\);\s*$/', $line)) {
                $prev = preg_replace('/,(\s*)$/', '$1', $prev);
            }

            $emit($prev);
            $prev = $line;
        }

        $emit($prev);

        fclose($in);
        fclose($out);

        if (! @rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Could not replace dump with its filtered copy: {$file}");
        }
    }

    private function importRemotePgsql(SshService $ssh, array $c, string $remoteFile): void
    {
        $host = escapeshellarg($c['host']);
        $port = escapeshellarg($c['port']);
        $user = escapeshellarg($c['user']);
        $db = escapeshellarg($c['db']);
        $pass = escapeshellarg($c['pass']);
        $file = escapeshellarg($remoteFile);

        $psqlBin = escapeshellarg(config('autopilot.pgsql.remote_client_binary', 'psql'));

        // Full schema+data dump (--clean --if-exists) already contains DROP/CREATE
        // statements in correct FK dependency order — no truncate or constraint
        // deferral needed.
        $cmd =
            "PGPASSWORD={$pass} {$psqlBin} ".
            "--host={$host} --port={$port} --username={$user} --dbname={$db} ".
            // -o /dev/null discards RESULT ROWS, not errors. Without it the tail of
            // a restore emits a setval table per sequence — dozens of them — which
            // buried the DBPUSH_EXIT marker below and made a successful import look
            // like a failure. Errors still arrive via stderr through 2>&1.
            "-v ON_ERROR_STOP=1 --no-psqlrc --quiet -o /dev/null -f {$file} 2>&1; ".
            "EXIT_CODE=\$?; rm -f {$file}; echo \"DBPUSH_EXIT:\$EXIT_CODE\"";

        $result = $ssh->exec($cmd);

        // A missing marker means the exit status never came back — treat it as a
        // failure, but say so plainly rather than reporting a psql error that may
        // not have happened.
        if (! preg_match('/DBPUSH_EXIT:(\d+)/', $result['output'] ?? '', $m)) {
            throw new RuntimeException(
                'Remote psql import returned no exit status; the database may or may '
                .'not have been written. Output tail: '
                .trim(substr($result['output'] ?? '', -500))
            );
        }
        $exit = (int) $m[1];

        if ($exit !== 0) {
            $body = preg_replace('/DBPUSH_EXIT:\d+\s*$/', '', $result['output']);
            throw new RuntimeException('Remote psql import failed: '.trim($body));
        }
    }

    /**
     * Run a callable with an env var set, restoring previous state afterwards.
     * If $value is empty, unsets the var (some clients treat "" as "password supplied").
     */
    private function withEnv(string $name, string $value, callable $fn): void
    {
        $prev = getenv($name);
        $hadPrev = $prev !== false;
        if ($value !== '') {
            putenv($name.'='.$value);
        } else {
            putenv($name);
        }
        try {
            $fn();
        } finally {
            if ($hadPrev) {
                putenv($name.'='.$prev);
            } else {
                putenv($name);
            }
        }
    }
}
