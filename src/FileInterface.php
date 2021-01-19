<?php

declare(strict_types=1);

namespace Dosiero;

interface FileInterface
{

    public function getName(): string;

    public function getType(): string;

    public function getSize(): int;

    /**
     * @return string|null ISO 8601 format
     */
    public function getModified(): ?string;

    public function getWidth(): ?int;

    public function getHeight(): ?int;

    /**
     * @return string|null base64 encoded image
     */
    public function getThumbnail(): ?string;

    public function setDirectoryUrl(string $directoryUrl): void;

    public function getUrl(): string;
}
