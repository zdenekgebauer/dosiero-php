<?php

declare(strict_types=1);

namespace Dosiero;

use InvalidArgumentException;

abstract class Storage
{
    public const OPTION_BASE_URL = 'BASE_URL';
    public const OPTION_THUMBNAIL_SIZE = 'THUMBNAIL_SIZE';
    public const OPTION_MODE_FILE = 'MODE_FILE';
    public const OPTION_MODE_DIRECTORY = 'MODE_DIRECTORY';
    public const OPTION_READ_ONLY = 'READ_ONLY';
    public const OPTION_NORMALIZE_NAMES = 'NORMALIZE_NAMES';
    public const OPTION_OVERWRITE_FILES = 'OVERWRITE_FILES';

    protected string $name;

    protected string $baseUrl = '';

    protected int $modeDir = 0755;

    protected int $modeFile = 0644;

    protected int $thumbnailSize = 50;

    protected bool $readOnly = false;

    protected bool $normalizeNames = true;

    protected bool $overwriteFiles = true;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setOption(string $name, bool | int | string $value): void
    {
        switch ($name) {
            case self::OPTION_BASE_URL:
                $this->baseUrl = rtrim((string)$value, '/') . '/';
                break;
            case self::OPTION_THUMBNAIL_SIZE:
                $this->thumbnailSize = max([10, (int)$value]);
                break;
            case self::OPTION_MODE_FILE:
                $this->modeFile = (int)$value;
                break;
            case self::OPTION_MODE_DIRECTORY:
                $this->modeDir = (int)$value;
                break;
            case self::OPTION_READ_ONLY:
                $this->readOnly = (bool)$value;
                break;
            case self::OPTION_NORMALIZE_NAMES:
                $this->normalizeNames = (bool)$value;
                break;
            case self::OPTION_OVERWRITE_FILES:
                $this->overwriteFiles = (bool)$value;
                break;
            default:
                throw new InvalidArgumentException('invalid option "' . $name . '"');
        }
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
