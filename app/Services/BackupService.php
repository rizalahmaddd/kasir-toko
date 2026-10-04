<?php

namespace App\Services;

use FilesystemIterator;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Backup & restore tanpa mysqldump atau package tambahan. Database ditulis sebagai file SQL
 * ter-gzip dan dijalankan ulang per statement saat restore (MySQL/MariaDB di produksi, SQLite di
 * test). File aplikasi dikemas ke zip; backup "lengkap" berisi keduanya dalam satu zip.
 */
class BackupService
{
    public const DIRECTORY = 'backups';

    public const LOCK = 'backup-process';

    /** @var array<string, array{label: string, icon: string, description: string}> */
    public const SCOPES = [
        'database' => [
            'label' => 'Database',
            'icon' => 'database',
            'description' => 'Semua tabel dan transaksi. Bisa dipulihkan langsung dari halaman ini.',
        ],
        'files' => [
            'label' => 'File aplikasi',
            'icon' => 'folder-code',
            'description' => 'Kode, konfigurasi, dan file unggahan (logo, foto gerbang). Dipulihkan manual di server.',
        ],
        'full' => [
            'label' => 'Lengkap',
            'icon' => 'archive',
            'description' => 'Database dan file aplikasi dalam satu arsip, untuk pindah atau membangun ulang server.',
        ],
    ];

    /** Bisa dibangun ulang (composer/npm install, npm run build) atau isinya sementara. */
    public const EXCLUDED_PATHS = [
        '.git', 'vendor', 'node_modules', 'public/build', 'public/hot', 'public/storage',
        'storage/framework', 'storage/logs', 'storage/pail', 'storage/app/private/livewire-tmp', 'bootstrap/cache',
        '.phpunit.cache', '.idea', '.vscode', '.zed', '.cursor', '.codex', '.claude',
    ];

    public const SECRET_FILES = ['.env', '.env.backup', '.env.production'];

    private const FORMAT_MARKER = 'app-backup/1';

    private const MANIFEST_ENTRY = 'backup-manifest.json';

    private const DATABASE_ENTRY = 'database.sql.gz';

    private const FILES_ENTRY_PREFIX = 'files/';

    private const NAME_PATTERN = '/^(manual|pre-restore|upload|auto-s\d+)-(database|files|full)-/';

    /**
     * Tabel sesi & cache hanya diambil strukturnya. Saat restore tabel ini tidak di-drop, jadi
     * superadmin yang sedang login tidak ikut ter-logout.
     */
    private const VOLATILE_TABLES = ['sessions', 'cache', 'cache_locks'];

    private const ROWS_PER_INSERT = 500;

    private const BYTES_PER_INSERT = 1_000_000;

    public function __construct(private ?string $connectionName = null, private ?string $filesRoot = null) {}

    /**
     * @return Collection<int, array{name: string, size: int, created_at: Carbon, scope: string, origin: string, restorable: bool}>
     */
    public function list(): Collection
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIRECTORY))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => self::isValidName($name))
            ->map(function (string $name) use ($disk) {
                [$origin, $scope] = self::parseName($name);

                return [
                    'name' => $name,
                    'size' => $disk->size(self::DIRECTORY.'/'.$name),
                    'created_at' => Carbon::createFromTimestamp($disk->lastModified(self::DIRECTORY.'/'.$name)),
                    'scope' => $scope,
                    'origin' => $origin,
                    'restorable' => $scope !== 'files',
                ];
            })
            ->sortBy([['created_at', 'desc'], ['name', 'desc']])
            ->values();
    }

    public function create(string $scope = 'database', string $origin = 'manual', bool $includeSecrets = false): string
    {
        if (! array_key_exists($scope, self::SCOPES)) {
            throw new RuntimeException("Jenis backup {$scope} tidak dikenal.");
        }

        $this->extendTimeLimit();
        Storage::disk('local')->makeDirectory(self::DIRECTORY);

        $name = $this->uniqueName("{$origin}-{$scope}-".now()->format('Y-m-d-His'), $scope === 'database' ? '.sql.gz' : '.zip');
        $path = $this->absolutePath($name);

        try {
            $scope === 'database'
                ? $this->writeDatabaseFile($path)
                : $this->writeArchive($path, $scope, $includeSecrets);
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $name;
    }

    /**
     * Seluruh tabel (kecuali sesi & cache) diganti isi backup database atau bagian database dari
     * backup lengkap. Sebelumnya kondisi saat ini dibackup dulu; kalau restore gagal di tengah
     * jalan, backup itu dipulihkan otomatis karena DDL MySQL tidak bisa di-rollback.
     *
     * @return string nama file backup pengaman yang dibuat sebelum restore
     */
    public function restore(string $name): string
    {
        $this->extendTimeLimit();
        $this->assertRestorable($name);

        $safetyBackup = $this->create('database', 'pre-restore');

        try {
            $this->withDatabaseFile($name, $this->runRestore(...));
        } catch (Throwable $e) {
            try {
                $this->withDatabaseFile($safetyBackup, $this->runRestore(...));
            } catch (Throwable) {
                throw new RuntimeException("Restore gagal dan kondisi awal tidak bisa dipulihkan otomatis. Pulihkan manual dari file {$safetyBackup}. Penyebab: {$e->getMessage()}", previous: $e);
            }

            throw new RuntimeException("Restore gagal, database dikembalikan ke kondisi sebelumnya. Penyebab: {$e->getMessage()}", previous: $e);
        }

        return $safetyBackup;
    }

    public function store(UploadedFile $file): string
    {
        $original = strtolower($file->getClientOriginalName());
        $extension = match (true) {
            str_ends_with($original, '.sql.gz') => '.sql.gz',
            str_ends_with($original, '.sql') => '.sql',
            str_ends_with($original, '.zip') => '.zip',
            default => throw new RuntimeException('File harus berekstensi .sql.gz, .sql, atau .zip.'),
        };

        $scope = $extension === '.zip' ? $this->archiveManifest($file->getRealPath())['scope'] : 'database';
        $slug = Str::limit(Str::slug(Str::before($file->getClientOriginalName(), '.')) ?: 'backup', 60, '');
        $name = $this->uniqueName("upload-{$scope}-".now()->format('Y-m-d-His')."-{$slug}", $extension);

        Storage::disk('local')->putFileAs(self::DIRECTORY, $file, $name);

        try {
            if ($scope !== 'files') {
                $this->assertRestorable($name);
            }
        } catch (RuntimeException $e) {
            $this->delete($name);

            throw $e;
        }

        return $name;
    }

    /**
     * @return list<string> nama file yang dihapus
     */
    public function prune(string $origin, int $keep): array
    {
        $stale = $this->list()
            ->where('origin', $origin)
            ->skip($keep)
            ->pluck('name')
            ->values()
            ->all();

        foreach ($stale as $name) {
            $this->delete($name);
        }

        return $stale;
    }

    public function delete(string $name): void
    {
        $this->assertExists($name);

        Storage::disk('local')->delete(self::DIRECTORY.'/'.$name);
    }

    public function path(string $name): string
    {
        $this->assertExists($name);

        return self::DIRECTORY.'/'.$name;
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(sql|sql\.gz|zip)$/', $name) && ! str_contains($name, '..');
    }

    /**
     * @return array{0: string, 1: string} [asal, jenis]
     */
    public static function parseName(string $name): array
    {
        if (preg_match(self::NAME_PATTERN, $name, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return ['manual', str_ends_with($name, '.zip') ? 'files' : 'database'];
    }

    private function writeDatabaseFile(string $path): void
    {
        $handle = gzopen($path, 'wb6');

        if ($handle === false) {
            throw new RuntimeException('File backup tidak bisa dibuat di storage.');
        }

        try {
            $this->writeDump($handle);
        } finally {
            gzclose($handle);
        }
    }

    private function writeArchive(string $path, string $scope, bool $includeSecrets): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Arsip backup tidak bisa dibuat di storage.');
        }

        $databaseFile = null;

        try {
            if ($scope === 'full') {
                $databaseFile = $this->temporaryPath('.sql.gz');
                $this->writeDatabaseFile($databaseFile);
                $zip->addFile($databaseFile, self::DATABASE_ENTRY);
                $zip->setCompressionName(self::DATABASE_ENTRY, ZipArchive::CM_STORE);
            }

            $fileCount = $this->addApplicationFiles($zip, $includeSecrets);

            $zip->addFromString(self::MANIFEST_ENTRY, (string) json_encode([
                'format' => self::FORMAT_MARKER,
                'scope' => $scope,
                'app' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'database_driver' => $scope === 'full' ? $this->driver() : null,
                'file_count' => $fileCount,
                'include_secrets' => $includeSecrets,
                'excluded' => self::EXCLUDED_PATHS,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException('Arsip backup gagal ditulis: '.$zip->getStatusString());
            }
        } finally {
            if ($databaseFile !== null) {
                @unlink($databaseFile);
            }
        }
    }

    private function addApplicationFiles(ZipArchive $zip, bool $includeSecrets): int
    {
        $root = rtrim($this->filesRoot ?? base_path(), DIRECTORY_SEPARATOR);
        $backupDirectory = (string) realpath(Storage::disk('local')->path(self::DIRECTORY));

        $filter = function (SplFileInfo $file) use ($root, $backupDirectory, $includeSecrets): bool {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($file->isLink() || $file->getFilename() === '.DS_Store' || $file->getRealPath() === $backupDirectory) {
                return false;
            }

            foreach (self::EXCLUDED_PATHS as $excluded) {
                if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                    return false;
                }
            }

            return $file->isDir() || $includeSecrets || ! $this->isSecret($relative);
        };

        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            $filter,
        ));

        $count = 0;

        foreach ($files as $file) {
            if ($file->isFile() && $file->isReadable()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $zip->addFile($file->getPathname(), self::FILES_ENTRY_PREFIX.$relative);
                $count++;
            }
        }

        return $count;
    }

    private function isSecret(string $relative): bool
    {
        return in_array($relative, self::SECRET_FILES, true) || (bool) preg_match('#^storage/[^/]+\.key$#', $relative);
    }

    /**
     * @return array{scope: string, database_driver: ?string}
     */
    private function archiveManifest(string $path): array
    {
        $zip = new ZipArchive;
        $manifest = $zip->open($path) === true ? json_decode((string) $zip->getFromName(self::MANIFEST_ENTRY), true) : null;
        $zip->close();

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT_MARKER || ! in_array($manifest['scope'] ?? null, ['files', 'full'], true)) {
            throw new RuntimeException('Arsip ini bukan backup yang dibuat dari aplikasi ini.');
        }

        return ['scope' => $manifest['scope'], 'database_driver' => $manifest['database_driver'] ?? null];
    }

    /**
     * Backup lengkap menyimpan database sebagai entri di dalam zip; entri itu diekstrak dulu ke
     * file sementara supaya bisa dibaca per baris seperti backup database biasa.
     *
     * @param  callable(string): void  $callback
     */
    private function withDatabaseFile(string $name, callable $callback): void
    {
        $path = $this->absolutePath($name);

        if (! str_ends_with($name, '.zip')) {
            $callback($path);

            return;
        }

        $zip = new ZipArchive;
        $stream = $zip->open($path) === true ? $zip->getStream(self::DATABASE_ENTRY) : false;

        if ($stream === false) {
            throw new RuntimeException('Arsip ini tidak berisi backup database.');
        }

        $temporary = $this->temporaryPath('.sql.gz');

        try {
            $target = fopen($temporary, 'wb');
            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);
            $zip->close();

            $callback($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function writeDump($handle): void
    {
        $connection = $this->connection();
        $driver = $this->driver();

        gzwrite($handle, implode("\n", [
            '-- '.self::FORMAT_MARKER.'; driver: '.$driver,
            '-- Database: '.$connection->getDatabaseName(),
            '-- Dibuat: '.now()->toDateTimeString(),
            '',
            '',
        ]));

        $indexes = [];
        $tables = $driver === 'sqlite' ? array_reverse($this->sqliteDropOrder($this->tables())) : $this->tables();

        foreach ($tables as $table) {
            $volatile = in_array($table, self::VOLATILE_TABLES, true);
            $create = $this->createStatement($table);

            if ($volatile) {
                $create = (string) preg_replace('/^CREATE TABLE\s+(?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $create);
            } else {
                gzwrite($handle, 'DROP TABLE IF EXISTS '.$this->quoteIdentifier($table).";\n");
            }

            gzwrite($handle, $create.";\n");

            if ($driver === 'sqlite') {
                foreach ($this->sqliteIndexes($table) as $index) {
                    $indexes[] = $volatile
                        ? (string) preg_replace('/^CREATE (UNIQUE )?INDEX\s+(?!IF NOT EXISTS)/i', 'CREATE $1INDEX IF NOT EXISTS ', $index)
                        : $index;
                }
            }

            if (! $volatile) {
                $this->writeRows($handle, $table);
            }

            gzwrite($handle, "\n");
        }

        foreach ($indexes as $index) {
            gzwrite($handle, $index.";\n");
        }
    }

    /**
     * @param  resource  $handle
     */
    private function writeRows($handle, string $table): void
    {
        $pdo = $this->connection()->getPdo();
        $isMysql = $this->driver() !== 'sqlite';
        $columns = implode(', ', array_map($this->quoteIdentifier(...), $this->insertableColumns($table)));

        // Unbuffered supaya tabel besar tidak dimuat sekaligus ke memori PHP.
        if ($isMysql) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $statement = $pdo->query("SELECT {$columns} FROM ".$this->quoteIdentifier($table));
            $prefix = 'INSERT INTO '.$this->quoteIdentifier($table)." ({$columns}) VALUES ";
            $values = [];
            $bytes = 0;

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $tuple = '('.implode(', ', array_map($this->quoteValue(...), $row)).')';
                $values[] = $tuple;
                $bytes += strlen($tuple);

                if (count($values) >= self::ROWS_PER_INSERT || $bytes >= self::BYTES_PER_INSERT) {
                    gzwrite($handle, $prefix.implode(",\n", $values).";\n");
                    $values = [];
                    $bytes = 0;
                }
            }

            $statement->closeCursor();

            if ($values !== []) {
                gzwrite($handle, $prefix.implode(",\n", $values).";\n");
            }
        } finally {
            if ($isMysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }

    private function runRestore(string $path): void
    {
        $connection = $this->connection();
        $isSqlite = $this->driver() === 'sqlite';

        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('File backup tidak bisa dibaca.');
        }

        try {
            if ($isSqlite) {
                $connection->unprepared('PRAGMA foreign_keys = OFF');
                $connection->unprepared('PRAGMA defer_foreign_keys = ON');
            } else {
                $connection->unprepared('SET NAMES utf8mb4');
                $connection->unprepared('SET FOREIGN_KEY_CHECKS = 0');
            }

            $tables = array_values(array_diff($this->tables(), self::VOLATILE_TABLES));

            foreach ($isSqlite ? $this->sqliteDropOrder($tables) : $tables as $table) {
                $connection->unprepared('DROP TABLE IF EXISTS '.$this->quoteIdentifier($table));
            }

            foreach ($this->statements($handle, backslashEscapes: ! $isSqlite) as $sql) {
                $connection->unprepared($sql);
            }
        } finally {
            gzclose($handle);
            $connection->unprepared($isSqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Memecah isi file per statement di titik koma yang berada di luar tanda kutip, karena
     * string data boleh berisi ";" maupun baris baru.
     *
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private function statements($handle, bool $backslashEscapes): Generator
    {
        $buffer = '';
        $quote = null;

        while (($line = gzgets($handle)) !== false) {
            if ($quote === null && $buffer === '' && (trim($line) === '' || str_starts_with($line, '--'))) {
                continue;
            }

            $buffer .= $line;
            $length = strlen($line);
            $i = 0;

            while ($i < $length) {
                if ($quote === null) {
                    $i += strcspn($line, "'\"`", $i);

                    if ($i < $length) {
                        $quote = $line[$i];
                    }
                } else {
                    $i += strcspn($line, $backslashEscapes ? $quote.'\\' : $quote, $i);

                    if ($i < $length) {
                        if ($line[$i] === '\\') {
                            $i++;
                        } else {
                            $quote = null;
                        }
                    }
                }

                $i++;
            }

            if ($quote === null && str_ends_with(rtrim($buffer), ';')) {
                yield substr(rtrim($buffer), 0, -1);
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            throw new RuntimeException('File backup terpotong atau rusak: statement terakhir tidak lengkap.');
        }
    }

    private function assertRestorable(string $name): void
    {
        $this->assertExists($name);

        if (self::parseName($name)[1] === 'files') {
            throw new RuntimeException('Backup file aplikasi dipulihkan manual di server, bukan dari halaman ini.');
        }

        if (str_ends_with($name, '.zip') && $this->archiveManifest($this->absolutePath($name))['scope'] !== 'full') {
            throw new RuntimeException('Arsip ini tidak berisi backup database.');
        }

        $this->withDatabaseFile($name, function (string $path): void {
            $handle = gzopen($path, 'rb');
            $header = $handle === false ? '' : (string) gzgets($handle, 512);

            if ($handle !== false) {
                gzclose($handle);
            }

            if (! preg_match('/^-- '.preg_quote(self::FORMAT_MARKER, '/').'; driver: (\w+)/', $header, $matches)) {
                throw new RuntimeException('File ini bukan backup yang dibuat dari aplikasi ini.');
            }

            $current = $this->driver();

            if ($matches[1] !== $current) {
                throw new RuntimeException("Backup ini dibuat dari database {$matches[1]}, tidak bisa dipulihkan ke database {$current}.");
            }
        });
    }

    private function assertExists(string $name): void
    {
        if (! self::isValidName($name) || ! Storage::disk('local')->exists(self::DIRECTORY.'/'.$name)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $connection = $this->connection();

        $rows = $this->driver() === 'sqlite'
            ? $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            : $connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

        return array_map(fn (object $row) => (string) array_values((array) $row)[0], $rows);
    }

    private function createStatement(string $table): string
    {
        $connection = $this->connection();

        if ($this->driver() === 'sqlite') {
            return (string) $connection->scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        }

        $row = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->quoteIdentifier($table));

        return (string) $row['Create Table'];
    }

    /**
     * Kolom generated (mis. item_stocks.grade_key) dihitung ulang oleh database dan ditolak kalau
     * ikut di-INSERT.
     *
     * @return list<string>
     */
    private function insertableColumns(string $table): array
    {
        $rows = $this->driver() === 'sqlite'
            ? $this->connection()->select('SELECT name FROM pragma_table_xinfo(?) WHERE hidden = 0 ORDER BY cid', [$table])
            : $this->connection()->select("SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%VIRTUAL GENERATED%' AND EXTRA NOT LIKE '%STORED GENERATED%' ORDER BY ORDINAL_POSITION", [$table]);

        return array_map(fn (object $row) => (string) $row->name, $rows);
    }

    /**
     * @return list<string>
     */
    private function sqliteIndexes(string $table): array
    {
        return array_map(
            fn (object $row) => (string) $row->sql,
            $this->connection()->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]),
        );
    }

    /**
     * Di dalam transaksi SQLite tetap memeriksa foreign key (PRAGMA foreign_keys diabaikan), jadi
     * tabel anak di-drop sebelum induknya, dan di file backup induk ditulis lebih dulu.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function sqliteDropOrder(array $tables): array
    {
        $references = [];

        foreach ($tables as $table) {
            $references[$table] = array_values(array_diff(array_map(
                fn (object $row) => (string) $row->table,
                $this->connection()->select('SELECT DISTINCT "table" FROM pragma_foreign_key_list(?)', [$table]),
            ), [$table]));
        }

        $ordered = [];

        while ($references !== []) {
            $referenced = array_merge(...array_values($references));
            $leaves = array_keys(array_filter($references, fn ($refs, string $table) => ! in_array($table, $referenced, true), ARRAY_FILTER_USE_BOTH));
            $leaves = $leaves === [] ? array_keys($references) : $leaves;

            foreach ($leaves as $table) {
                $ordered[] = $table;
                unset($references[$table]);
            }
        }

        return $ordered;
    }

    private function quoteIdentifier(string $name): string
    {
        return $this->driver() === 'sqlite'
            ? '"'.str_replace('"', '""', $name).'"'
            : '`'.str_replace('`', '``', $name).'`';
    }

    private function quoteValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") => $this->driver() === 'sqlite'
                ? "X'".bin2hex($value)."'"
                : '0x'.bin2hex($value),
            default => $this->connection()->getPdo()->quote($value),
        };
    }

    private function connection(): Connection
    {
        return DB::connection($this->connectionName);
    }

    private function driver(): string
    {
        $driver = $this->connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Backup untuk database {$driver} belum didukung.");
        }

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    private function uniqueName(string $base, string $extension): string
    {
        $name = $base.$extension;

        for ($i = 2; Storage::disk('local')->exists(self::DIRECTORY.'/'.$name); $i++) {
            $name = "{$base}-{$i}{$extension}";
        }

        return $name;
    }

    private function temporaryPath(string $extension): string
    {
        return $this->absolutePath('.tmp-'.Str::random(16).$extension);
    }

    private function absolutePath(string $name): string
    {
        return Storage::disk('local')->path(self::DIRECTORY.'/'.$name);
    }

    private function extendTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }
}
