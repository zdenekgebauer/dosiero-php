<?php

declare(strict_types=1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Dosiero\Storage;
use Dosiero\StorageException;
use Tests\Support\IntegrationTester;

/**
 * Each check of assertAllowedUpload() on its own, with a file only that check refuses: a fixture
 * several checks refuse proves nothing about any one of them.
 */
class StorageUploadCheckTest extends Unit
{
    protected IntegrationTester $tester;

    private string $directory;

    /** @return array<string, array{0: string, 1: string, 2: string, 3?: int}> */
    public function provideRefusals(): array
    {
        return [
            'empty name' => ['', 'hello', 'invalid file name ""'],
            'dot file' => ['.notes.txt', 'hello', 'invalid file name ".notes.txt"'],
            'extension not allowed' => ['macro.docm', 'hello', 'file type "docm" is not allowed'],
            'no extension' => ['README', 'hello', 'file type "" is not allowed'],
            'text claiming to be an image' => ['photo.jpg', 'hello', 'file "photo.jpg" is not a valid image'],
            'script claiming to be text' => ['notes.txt', "<?php\necho 1;\n", 'file "notes.txt" contains text/x-php'],
            'text claiming to be a pdf' => ['doc.pdf', 'hello', 'file "doc.pdf" is not a valid pdf file'],
            'larger than the limit' => [
                'big.txt',
                str_repeat('a', 11),
                'file "big.txt" is larger than the allowed 10 bytes',
                10,
            ],
        ];
    }

    public function testFileWithinEveryRuleIsAccepted(): void
    {
        $this->check('notes.txt', 'hello');
        $this->check('song.mp3', "\xFF\xFB\x90\x00" . str_repeat("\x00", 64));
        // the extension is compared case-insensitively
        $this->check('NOTES.TXT', 'hello');
        // only the parts after the first dot are extensions
        $this->check('php.txt', 'hello');
        // the limit itself is still allowed
        $this->check('exact.txt', str_repeat('a', 10), 10);
        $this->tester->assertTrue(true);
    }

    public function testMissingTemporaryFileLeavesOnlyTheNameChecks(): void
    {
        $this->createStorage(10)->check('photo.jpg', $this->directory . '/no-such-file');
        $this->tester->assertTrue(true);
    }

    public function testNoLimitAtAll(): void
    {
        $tmpName = $this->directory . '/upload.tmp';
        file_put_contents($tmpName, str_repeat('a', 100));
        $storage = new class('check') extends Storage {
            public function check(string $name, string $tmpName): void
            {
                $this->assertAllowedUpload($name, $tmpName);
            }

            public function getMaxFileSize(): int
            {
                return 0;
            }
        };

        $storage->check('big.txt', $tmpName);
        $this->tester->assertTrue(true);
    }

    /** @dataProvider provideRefusals */
    public function testRefusal(string $name, string $content, string $message, int $maxFileSize = 0): void
    {
        $this->tester->expectThrowable(
            new StorageException($message),
            fn() => $this->check($name, $content, $maxFileSize),
        );
    }

    protected function _after()
    {
        $this->tester->emptyDirRecursive($this->directory, true);
    }

    protected function _before()
    {
        $this->directory = codecept_data_dir('upload-check');
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o777, true);
        }
    }

    private function check(string $name, string $content, int $maxFileSize = 0): void
    {
        $tmpName = $this->directory . '/upload.tmp';
        file_put_contents($tmpName, $content);
        $this->createStorage($maxFileSize)->check($name, $tmpName);
    }

    private function createStorage(int $maxFileSize): object
    {
        $storage = new class('check') extends Storage {
            public function check(string $name, string $tmpName): void
            {
                $this->assertAllowedUpload($name, $tmpName);
            }
        };
        $storage->setOption(Storage::OPTION_MAX_FILE_SIZE, $maxFileSize);
        return $storage;
    }
}
