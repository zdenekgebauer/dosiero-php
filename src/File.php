<?php

declare(strict_types=1);

namespace Dosiero;

class File implements FileInterface
{
    public const TYPE_DIR = 'dir';

    public const TYPE_FILE = 'file';

    public const TYPE_LINK = 'link';

    protected string $directoryUrl = '';

    protected ?int $height;

    protected string $modified = '';

    protected string $name;

    protected ?string $thumbnail;

    /** @var string dir|file|link */
    protected string $type;

    protected ?int $width;

    private int $size = 0;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getModified(): ?string
    {
        return $this->modified;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getUrl(): string
    {
        return $this->directoryUrl . $this->name;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setDirectoryUrl(string $directoryUrl): void
    {
        $this->directoryUrl = $directoryUrl;
    }

    public function setHeight(?int $height): void
    {
        $this->height = $height;
    }

    public function setModified(string $modified): void
    {
        $this->modified = $modified;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function setThumbnail(?string $thumbnail): void
    {
        $this->thumbnail = $thumbnail;
    }

    public function setWidth(?int $width): void
    {
        $this->width = $width;
    }
}
