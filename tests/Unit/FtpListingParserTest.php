<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\File;
use Dosiero\Ftp\FtpConnection;
use Dosiero\Ftp\FtpEntry;
use Tests\Support\UnitTester;

class FtpListingParserTest extends Unit
{
    private const string NOW = '2026-11-17 16:50:00';

    protected UnitTester $tester;

    /** @return array<string, array{string, string|null, string|null, int|null, string|null}> */
    public function provideListLines(): array
    {
        return [
            'proftpd file' => [
                '-rw-r--r--   1 dosiero  www-data        4 Nov 17 16:42 a.txt',
                'a.txt', File::TYPE_FILE, 4, '2026-11-17T16:42:00+00:00',
            ],
            'proftpd dir has no size' => [
                'drwxr-xr-x   2 dosiero  www-data     4096 Nov 17 16:42 sub',
                'sub', File::TYPE_DIR, 0, '2026-11-17T16:42:00+00:00',
            ],
            'proftpd link drops its target' => [
                'lrwxrwxrwx   1 dosiero  www-data        5 Nov 17 16:42 flink -> a.txt',
                'flink', File::TYPE_LINK, 5, '2026-11-17T16:42:00+00:00',
            ],
            'proftpd old file carries its year' => [
                '-rw-r--r--   1 dosiero  www-data        0 Mar  1  2024 old.txt',
                'old.txt', File::TYPE_FILE, 0, '2024-03-01T00:00:00+00:00',
            ],
            'pure-ftpd numeric owner' => [
                '-rw-r--r--    1 1000       1000              4 Nov 17 16:42 a.txt',
                'a.txt', File::TYPE_FILE, 4, '2026-11-17T16:42:00+00:00',
            ],
            'vsftpd name with spaces' => [
                '-rw-r--r--    1 1000     1000            2 Nov 17 16:42 b  c.txt',
                'b  c.txt', File::TYPE_FILE, 2, '2026-11-17T16:42:00+00:00',
            ],
            'year-less date tomorrow is still this year' => [
                '-rw-r--r--   1 u g 1 Nov 18 10:00 clock-skew.txt',
                'clock-skew.txt', File::TYPE_FILE, 1, '2026-11-18T10:00:00+00:00',
            ],
            'year-less date further ahead belongs to last year' => [
                '-rw-r--r--   1 u g 1 Dec 20 10:00 december.txt',
                'december.txt', File::TYPE_FILE, 1, '2025-12-20T10:00:00+00:00',
            ],
            'unreadable date falls back to the epoch' => [
                '-rw-r--r--   1 u g 1 Foo 99 10:00 strange.txt',
                'strange.txt', File::TYPE_FILE, 1, '1970-01-01T00:00:00+00:00',
            ],
            'unreadable year falls back to the epoch' => [
                '-rw-r--r--   1 u g 1 Foo 99 2024 strange.txt',
                'strange.txt', File::TYPE_FILE, 1, '1970-01-01T00:00:00+00:00',
            ],
            'current dir' => ['drwxr-xr-x   4 u g 4096 Nov 17 16:42 .', null, null, null, null],
            'parent dir' => ['drwxr-xr-x   4 u g 4096 Nov 17 16:42 ..', null, null, null, null],
            'total line' => ['total 12', null, null, null, null],
            'device' => ['crw-rw-rw-   1 u g 1, 3 Nov 17 16:42 null', null, null, null, null],
            'path instead of a name' => ['-rw-r--r--   1 u g 4 Nov 17 16:42 /a.txt', null, null, null, null],
        ];
    }

    /** @return array<string, array{array<string, string>, string|null, string|null, int|null, string|null}> */
    public function provideMlsdFacts(): array
    {
        return [
            'proftpd file' => [
                ['name' => 'a.txt', 'modify' => '20261117164201', 'size' => '4', 'type' => 'file', 'perm' => 'r'],
                'a.txt', File::TYPE_FILE, 4, '2026-11-17T16:42:01+00:00',
            ],
            'proftpd dir has no size' => [
                ['name' => 'sub', 'modify' => '20261117164201', 'type' => 'dir'],
                'sub', File::TYPE_DIR, 0, '2026-11-17T16:42:01+00:00',
            ],
            'proftpd symlink' => [
                ['name' => 'link', 'modify' => '20261117164201', 'size' => '3', 'type' => 'OS.unix=symlink'],
                'link', File::TYPE_LINK, 3, '2026-11-17T16:42:01+00:00',
            ],
            'slink with target' => [
                ['name' => 'link', 'modify' => '20261117164201', 'type' => 'OS.unix=slink:/sub'],
                'link', File::TYPE_LINK, 0, '2026-11-17T16:42:01+00:00',
            ],
            'pure-ftpd fractional seconds and capitalised facts' => [
                ['name' => 'a.txt', 'Modify' => '20240301090000.123', 'Size' => '7', 'Type' => 'File'],
                'a.txt', File::TYPE_FILE, 7, '2024-03-01T09:00:00+00:00',
            ],
            'missing modify falls back to the epoch' => [
                ['name' => 'a.txt', 'type' => 'file'],
                'a.txt', File::TYPE_FILE, 0, '1970-01-01T00:00:00+00:00',
            ],
            'cdir' => [['name' => '.', 'type' => 'cdir'], null, null, null, null],
            'pdir' => [['name' => '..', 'type' => 'pdir'], null, null, null, null],
            'dot name with a plain type' => [['name' => '..', 'type' => 'dir'], null, null, null, null],
            'single dot name with a plain type' => [['name' => '.', 'type' => 'dir'], null, null, null, null],
            'unknown type' => [['name' => 'pipe', 'type' => 'OS.unix=fifo'], null, null, null, null],
            'missing name' => [['type' => 'file'], null, null, null, null],
            'missing type' => [['name' => 'a.txt'], null, null, null, null],
        ];
    }

    /**
     * @dataProvider provideMlsdFacts
     *
     * @param array<string, string> $facts
     */
    public function testEntryFromFacts(array $facts, ?string $name, ?string $type, ?int $size, ?string $modified): void
    {
        $this->assertEntry(FtpConnection::entryFromFacts($facts), $name, $type, $size, $modified);
    }

    /** @dataProvider provideListLines */
    public function testEntryFromListLine(
        string $line,
        ?string $name,
        ?string $type,
        ?int $size,
        ?string $modified,
    ): void {
        $entry = FtpConnection::entryFromListLine($line, new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        $this->assertEntry($entry, $name, $type, $size, $modified);
    }

    private function assertEntry(?FtpEntry $entry, ?string $name, ?string $type, ?int $size, ?string $modified): void
    {
        if ($name === null) {
            $this->tester->assertNull($entry);
            return;
        }
        $this->tester->assertNotNull($entry);
        $this->tester->assertSame($name, $entry->name);
        $this->tester->assertSame($type, $entry->type);
        $this->tester->assertSame($size, $entry->size);
        $this->tester->assertSame($modified, $entry->modified->format('c'));
        $this->tester->assertSame($type === File::TYPE_DIR, $entry->isDir());
        $this->tester->assertSame($type === File::TYPE_LINK, $entry->isLink());
    }
}
