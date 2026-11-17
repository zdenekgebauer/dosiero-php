<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dosiero\FolderInterface;
use Dosiero\Ftp\FtpStorage;

class FtpStorageFoldersTest extends FtpStorageBase
{
    public function testHiddenFoldersAndLinksAreOmitted(): void
    {
        mkdir($this->ftpDirectory . '/.git');
        mkdir($this->ftpDirectory . '/visible');
        symlink('visible', $this->ftpDirectory . '/link');

        $this->tester->assertSame(['visible'], self::names($this->createStorage()->getFolders()));
    }

    public function testNestedFolders(): void
    {
        $this->writeFixture('a/b/deep.txt');
        $this->writeFixture('c.txt');

        $tree = [...$this->createStorage()->getFolders()];

        $this->tester->assertCount(1, $tree);
        $this->assertFolder($tree[0], 'a', 'a');
        $children = [...$tree[0]->getFolders()];
        $this->tester->assertCount(1, $children);
        $this->assertFolder($children[0], 'b', 'a/b');
        $this->tester->assertSame([], [...$children[0]->getFolders()]);
    }

    public function testPathsAreRelativeToTheBaseDir(): void
    {
        mkdir($this->ftpDirectory . '/base/a', 0o777, true);

        $tree = [...$this->createStorage([FtpStorage::OPTION_BASE_DIR => 'base'])->getFolders()];

        $this->tester->assertCount(1, $tree);
        $this->assertFolder($tree[0], 'a', 'a');
    }

    private function assertFolder(FolderInterface $folder, string $name, string $path): void
    {
        $this->tester->assertSame($name, $folder->getName());
        $this->tester->assertSame($path, $folder->getPath());
    }
}
