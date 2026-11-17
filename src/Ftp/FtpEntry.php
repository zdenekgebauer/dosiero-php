<?php

declare(strict_types=1);

namespace Dosiero\Ftp;

use Dosiero\File;

final readonly class FtpEntry
{
    /** @param string $type one of File::TYPE_DIR, File::TYPE_FILE, File::TYPE_LINK */
    public function __construct(
        public string $name,
        public string $type,
        public int $size,
        public \DateTimeImmutable $modified,
    ) {
    }

    public function isDir(): bool
    {
        return $this->type === File::TYPE_DIR;
    }

    public function isLink(): bool
    {
        return $this->type === File::TYPE_LINK;
    }
}
