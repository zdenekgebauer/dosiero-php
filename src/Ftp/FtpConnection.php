<?php

declare(strict_types=1);

namespace Dosiero\Ftp;

use Dosiero\File;
use Dosiero\StorageException;
use FTP\Connection;

/**
 * The only place that talks to the ftp extension. Opens on first use; mutations report success as
 * bool so the storage words the error the same way the local storage does.
 */
class FtpConnection
{
    private ?Connection $connection = null;

    private ?bool $mlsd = null;

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $login,
        #[\SensitiveParameter]
        private readonly string $password,
        public readonly bool $ssl,
        public readonly bool $passive,
        public readonly int $timeout,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function chmod(string $path, int $mode): bool
    {
        return @ftp_chmod($this->connection(), $mode, $path) !== false;
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
            $this->mlsd = null;
        }
    }

    public function copyFile(string $source, string $target): bool
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            return false;
        }
        $copied = @ftp_fget($this->connection(), $stream, $source, FTP_BINARY)
            && rewind($stream)
            && @ftp_fput($this->connection(), $target, $stream, FTP_BINARY);
        fclose($stream);
        return $copied;
    }

    public function delete(string $path): bool
    {
        return @ftp_delete($this->connection(), $path);
    }

    public function download(string $remote, string $local): bool
    {
        return @ftp_get($this->connection(), $local, $remote, FTP_BINARY);
    }

    /**
     * Reads one MLSD entry as ftp_mlsd() returns it; null for anything that is not a listed item.
     *
     * @param array<mixed> $facts
     */
    public static function entryFromFacts(array $facts): ?FtpEntry
    {
        $facts = array_change_key_case($facts);
        $name = self::fact($facts, 'name');
        $type = self::mlsdType(strtolower(self::fact($facts, 'type')));
        if ($name === '' || $name === '.' || $name === '..' || $type === null) {
            return null;
        }

        $modified = \DateTimeImmutable::createFromFormat(
            'YmdHis',
            substr(self::fact($facts, 'modify'), 0, 14),
            new \DateTimeZone('UTC'),
        );
        $size = $type === File::TYPE_DIR ? 0 : (int)self::fact($facts, 'size');
        return new FtpEntry($name, $type, $size, $modified === false ? self::epoch() : $modified);
    }

    /**
     * Reads one line of a unix `ls -l` listing. A date without a year lies within the last six
     * months, so it is the current year unless that would put it in the future.
     */
    public static function entryFromListLine(string $line, \DateTimeImmutable $now): ?FtpEntry
    {
        $parts = preg_split('/\s+/', trim($line), 9);
        if ($parts === false || count($parts) < 9) {
            return null;
        }
        [$permissions, , , , $size, $month, $day, $timeOrYear, $name] = $parts;

        $type = match ($permissions[0]) {
            '-' => File::TYPE_FILE,
            'd' => File::TYPE_DIR,
            'l' => File::TYPE_LINK,
            default => null,
        };
        if ($type === File::TYPE_LINK) {
            $name = explode(' -> ', $name, 2)[0];
        }
        if ($type === null || $name === '.' || $name === '..' || str_contains($name, '/')) {
            return null;
        }

        return new FtpEntry(
            $name,
            $type,
            $type === File::TYPE_DIR ? 0 : (int)$size,
            self::listDate($month, $day, $timeOrYear, $now),
        );
    }

    /** @return array<string, FtpEntry>|null null when the directory does not exist */
    public function list(string $directory): ?array
    {
        if ($this->supportsMlsd()) {
            $items = @ftp_mlsd($this->connection(), $directory);
            if ($items === false) {
                return null;
            }
            $entries = array_map(self::entryFromFacts(...), $items);
        } else {
            $lines = @ftp_rawlist($this->connection(), $directory);
            if ($lines === false || self::isListingOfAFile($lines)) {
                return null;
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $entries = array_map(static fn(string $line): ?FtpEntry => self::entryFromListLine($line, $now), $lines);
        }

        $result = [];
        foreach ($entries as $entry) {
            if ($entry !== null) {
                $result[$entry->name] = $entry;
            }
        }
        ksort($result);
        return $result;
    }

    public function mkdir(string $path): bool
    {
        return @ftp_mkdir($this->connection(), $path) !== false;
    }

    public function put(string $remote, string $local): bool
    {
        return @ftp_put($this->connection(), $remote, $local, FTP_BINARY);
    }

    public function rename(string $from, string $to): bool
    {
        return @ftp_rename($this->connection(), $from, $to);
    }

    public function rmdir(string $path): bool
    {
        return @ftp_rmdir($this->connection(), $path);
    }

    protected function isExtensionLoaded(): bool
    {
        return extension_loaded('ftp');
    }

    // FEAT rather than trying MLSD: a failed MLSD is also what a missing directory looks like
    protected function supportsMlsd(): bool
    {
        if ($this->mlsd === null) {
            $features = @ftp_raw($this->connection(), 'FEAT') ?? [];
            $this->mlsd = preg_grep('/^\s*MLST\b/i', $features) !== [];
        }
        return $this->mlsd;
    }

    private function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if ($this->host === '') {
            throw new StorageException('invalid configuration: missing ftp host');
        }
        if (!$this->isExtensionLoaded()) {
            throw new StorageException('the ftp extension is not loaded');
        }

        $connection = $this->ssl
            ? @ftp_ssl_connect($this->host, $this->port, $this->timeout)
            : @ftp_connect($this->host, $this->port, $this->timeout);
        if ($connection === false) {
            throw new StorageException('cannot connect to ftp server "' . $this->host . ':' . $this->port . '"');
        }
        if (!@ftp_login($connection, $this->login, $this->password)) {
            @ftp_close($connection);
            throw new StorageException('ftp login as "' . $this->login . '" failed');
        }
        ftp_pasv($connection, $this->passive);
        $this->connection = $connection;
        return $connection;
    }

    private static function epoch(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@0');
    }

    /** @param array<mixed> $facts */
    private static function fact(array $facts, string $name): string
    {
        $value = $facts[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * LIST of a file answers with that one file, named by the path it was asked for - a directory
     * listing names its entries without a slash.
     *
     * @param array<string> $lines
     */
    private static function isListingOfAFile(array $lines): bool
    {
        if (count($lines) !== 1) {
            return false;
        }
        $parts = preg_split('/\s+/', trim($lines[0]), 9);
        return $parts !== false && str_contains($parts[8] ?? '', '/');
    }

    private static function listDate(
        string $month,
        string $day,
        string $timeOrYear,
        \DateTimeImmutable $now,
    ): \DateTimeImmutable {
        $utc = new \DateTimeZone('UTC');
        if (!str_contains($timeOrYear, ':')) {
            $date = \DateTimeImmutable::createFromFormat('!M j Y', $month . ' ' . $day . ' ' . $timeOrYear, $utc);
            return $date === false ? self::epoch() : $date;
        }

        $year = (int)$now->format('Y');
        $date = \DateTimeImmutable::createFromFormat(
            '!M j Y H:i',
            $month . ' ' . $day . ' ' . $year . ' ' . $timeOrYear,
            $utc,
        );
        if ($date === false) {
            return self::epoch();
        }
        // a day of slack: the server clock and ours need not agree to the minute
        return $date > $now->modify('+1 day') ? $date->modify('-1 year') : $date;
    }

    private static function mlsdType(string $type): ?string
    {
        return match (true) {
            $type === 'file' => File::TYPE_FILE,
            $type === 'dir' => File::TYPE_DIR,
            str_starts_with($type, 'os.unix=symlink'), str_starts_with($type, 'os.unix=slink') => File::TYPE_LINK,
            default => null,
        };
    }
}
