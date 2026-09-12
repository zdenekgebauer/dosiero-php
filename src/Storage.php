<?php

declare(strict_types=1);

namespace Dosiero;

use function in_array;

abstract class Storage
{
    /** Comma separated list of extensions accepted by upload, e.g. 'jpg,png,pdf'. */
    public const string OPTION_ALLOWED_EXTENSIONS = 'ALLOWED_EXTENSIONS';

    public const string OPTION_BASE_URL = 'BASE_URL';

    /** Directory for the listing cache; must not be publicly served. Empty keeps it next to the data. */
    public const string OPTION_CACHE_DIRECTORY = 'CACHE_DIRECTORY';

    public const string OPTION_CACHE_ENABLED = 'CACHE_ENABLED';

    // size in bytes; 0 leaves only the php.ini limits
    public const string OPTION_MAX_FILE_SIZE = 'MAX_FILE_SIZE';

    public const string OPTION_MODE_DIRECTORY = 'MODE_DIRECTORY';

    public const string OPTION_MODE_FILE = 'MODE_FILE';

    public const string OPTION_NORMALIZE_NAMES = 'NORMALIZE_NAMES';

    public const string OPTION_OVERWRITE_FILES = 'OVERWRITE_FILES';

    public const string OPTION_READ_ONLY = 'READ_ONLY';

    public const string OPTION_THUMBNAIL_SIZE = 'THUMBNAIL_SIZE';

    // svg is omitted: can carry script

    /** @var array<string> */
    private const array DEFAULT_ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'avif',
        'bmp',
        'ico',
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'odt',
        'ods',
        'odp',
        'txt',
        'csv',
        'rtf',
        'md',
        'zip',
        '7z',
        'rar',
        'gz',
        'tar',
        'mp3',
        'ogg',
        'wav',
        'mp4',
        'webm',
        'avi',
        'mov',
    ];

    private const int DEFAULT_MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** @var array<string> */
    private const array EXECUTABLE_EXTENSIONS = [
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phps',
        'pht',
        'phtml',
        'phar',
        'cgi',
        'pl',
        'py',
        'rb',
        'sh',
        'bash',
        'asp',
        'aspx',
        'jsp',
        'jspx',
        'exe',
        'dll',
        'htaccess',
        'htpasswd',
        'user',
        'ini',
        'conf',
    ];

    // families, not exact types: libmagic reports the same file differently across versions

    /** @var array<string, array<string>> */
    private const array EXTENSION_MIME_PREFIXES = [
        'avi' => ['video/'],
        'mov' => ['video/'],
        'mp4' => ['video/'],
        'webm' => ['video/'],
        'mp3' => ['audio/', 'application/octet-stream'],
        'ogg' => ['audio/', 'video/'],
        'wav' => ['audio/'],
        'csv' => ['text/'],
        'md' => ['text/'],
        'txt' => ['text/'],
        'rtf' => ['text/rtf', 'application/rtf'],
        'pdf' => ['application/pdf'],
        'gz' => ['application/gzip', 'application/x-gzip'],
        'tar' => ['application/x-tar'],
        'zip' => ['application/zip'],
        '7z' => ['application/x-7z-compressed'],
        'rar' => ['application/x-rar'],
    ];

    // refused whatever the name claims: these are what an upload must never turn out to contain

    /** @var array<string> */
    private const array FORBIDDEN_MIME_TYPES = [
        'application/x-dosexec',
        'application/x-executable',
        'application/x-mach-binary',
        'application/x-msdownload',
        'application/x-sharedlib',
        'text/x-perl',
        'text/x-php',
        'text/x-python',
        'text/x-ruby',
        'text/x-shellscript',
    ];

    /** @var array<string> */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];

    /** @var array<string> */
    protected array $allowedExtensions = self::DEFAULT_ALLOWED_EXTENSIONS;

    protected string $baseUrl = '';

    protected string $cacheDirectory = '';

    protected bool $cacheEnabled = true;

    protected int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE;

    protected int $modeDir = 0o755;

    protected int $modeFile = 0o644;

    protected bool $normalizeNames = true;

    protected bool $overwriteFiles = true;

    protected bool $readOnly = false;

    protected int $thumbnailSize = 50;

    public function __construct(protected string $name)
    {
    }

    /** @return array<string> */
    public function getAllowedExtensions(): array
    {
        return $this->allowedExtensions;
    }

    /** @return int bytes, 0 = by upload_max_filesize/post_max_size */
    public function getMaxFileSize(): int
    {
        $limits = array_filter(
            [$this->maxFileSize, Utils::iniBytes('upload_max_filesize'), Utils::iniBytes('post_max_size')],
            static fn(int $limit): bool => $limit > 0,
        );
        return $limits === [] ? 0 : min($limits);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function setOption(string $name, bool|int|string $value): void
    {
        switch ($name) {
            case self::OPTION_BASE_URL:
                $this->baseUrl = rtrim((string)$value, '/') . '/';
                break;
            case self::OPTION_CACHE_DIRECTORY:
                $value = (string)$value;
                if ($value !== '' && !is_dir($value)) {
                    throw new \InvalidArgumentException('directory "' . $value . '" not found');
                }
                $this->cacheDirectory = $value;
                break;
            case self::OPTION_CACHE_ENABLED:
                $this->cacheEnabled = (bool)$value;
                break;
            case self::OPTION_THUMBNAIL_SIZE:
                $this->thumbnailSize = max([10, (int)$value]);
                break;
            case self::OPTION_MAX_FILE_SIZE:
                $this->maxFileSize = max(0, (int)$value);
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
                        array_map(trim(...), explode(',', strtolower((string)$value))),
                        static fn(string $extension): bool => $extension !== '',
                    ),
                );
                break;
            default:
                throw new \InvalidArgumentException('invalid option "' . $name . '"');
        }
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

        $this->assertNotExecutable($fileName);

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $this->allowedExtensions, true)) {
            throw new StorageException('file type "' . $extension . '" is not allowed');
        }

        if ($tmpName !== '' && is_file($tmpName)) {
            // the size reported by the browser is not trusted, the file on disk is
            $maxFileSize = $this->getMaxFileSize();
            $size = (int)filesize($tmpName);
            if ($maxFileSize > 0 && $size > $maxFileSize) {
                throw new StorageException(
                    'file "' . $fileName . '" is larger than the allowed ' . $maxFileSize . ' bytes',
                );
            }

            if (in_array($extension, self::IMAGE_EXTENSIONS, true) && @getimagesize($tmpName) === false) {
                throw new StorageException('file "' . $fileName . '" is not a valid image');
            }

            self::assertContentMatchesExtension($fileName, $extension, $tmpName);
        }
    }

    /**
     * The one rule that holds for every name this connector creates, not only for uploads: the
     * allowed-extension list is a rule for what comes in, but files also arrive by ftp or by hand,
     * and refusing to rename those would block the repairs a file manager exists for. What renaming
     * must never do is produce something the web server will execute.
     *
     * Checked in every position of the name, so invoice.php.jpg does not get through either.
     */
    protected function assertNotExecutable(string $fileName): void
    {
        $segments = array_map(strtolower(...), array_slice(explode('.', $fileName), 1));
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXECUTABLE_EXTENSIONS, true)) {
                throw new StorageException('file "' . $fileName . '" has a forbidden extension');
            }
        }
    }

    protected function createCache(): Cache
    {
        return new Cache($this->cacheEnabled, $this->cacheDirectory, $this->name);
    }

    private static function assertContentMatchesExtension(string $fileName, string $extension, string $tmpName): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return;
        }
        // no finfo_close(): deprecated as of PHP 8.5, the object is freed on its own
        $mimeType = finfo_file($finfo, $tmpName);
        if ($mimeType === false) {
            return;
        }

        if (in_array($mimeType, self::FORBIDDEN_MIME_TYPES, true)) {
            throw new StorageException('file "' . $fileName . '" contains ' . $mimeType);
        }

        $expected = self::EXTENSION_MIME_PREFIXES[$extension] ?? [];
        foreach ($expected as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return;
            }
        }
        if ($expected !== []) {
            throw new StorageException('file "' . $fileName . '" is not a valid ' . $extension . ' file');
        }
    }
}
