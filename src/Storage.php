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

    /**
     * Comma separated list of extensions accepted by upload, e.g. 'jpg,png,pdf'.
     */
    public const OPTION_ALLOWED_EXTENSIONS = 'ALLOWED_EXTENSIONS';

    /**
     * Refused in any position of the name, so "invoice.php.jpg" is rejected too.
     *
     * @var array<string>
     */
    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'exe', 'dll',
        'htaccess', 'htpasswd', 'user', 'ini', 'conf',
    ];

    /**
     * SVG is left out on purpose - it can carry script and is served from the same
     * origin as the site.
     *
     * @var array<string>
     */
    private const DEFAULT_ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'txt', 'csv', 'rtf', 'md',
        'zip', '7z', 'rar', 'gz', 'tar',
        'mp3', 'ogg', 'wav', 'mp4', 'webm', 'avi', 'mov',
    ];

    /**
     * @var array<string>
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];

    protected string $name;

    protected string $baseUrl = '';

    protected int $modeDir = 0755;

    protected int $modeFile = 0644;

    protected int $thumbnailSize = 50;

    protected bool $readOnly = false;

    protected bool $normalizeNames = true;

    protected bool $overwriteFiles = true;

    /**
     * @var array<string>
     */
    protected array $allowedExtensions = self::DEFAULT_ALLOWED_EXTENSIONS;

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
            case self::OPTION_ALLOWED_EXTENSIONS:
                $this->allowedExtensions = array_values(
                    array_filter(
                        array_map('trim', explode(',', strtolower((string)$value))),
                        static fn(string $extension): bool => $extension !== ''
                    )
                );
                break;
            default:
                throw new InvalidArgumentException('invalid option "' . $name . '"');
        }
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Refuses anything that must not land in a publicly served directory.
     *
     * @param string $tmpName path to the uploaded temporary file, '' when unavailable
     */
    protected function assertAllowedUpload(string $fileName, string $tmpName = ''): void
    {
        if ($fileName === '' || str_starts_with($fileName, '.')) {
            throw new StorageException('invalid file name "' . $fileName . '"');
        }

        $segments = array_map('strtolower', array_slice(explode('.', $fileName), 1));
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXECUTABLE_EXTENSIONS, true)) {
                throw new StorageException('file "' . $fileName . '" has a forbidden extension');
            }
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $this->allowedExtensions, true)) {
            throw new StorageException('file type "' . $extension . '" is not allowed');
        }

        if ($tmpName !== '' && in_array($extension, self::IMAGE_EXTENSIONS, true) && is_file($tmpName)) {
            if (@getimagesize($tmpName) === false) {
                throw new StorageException('file "' . $fileName . '" is not a valid image');
            }
        }
    }
}
