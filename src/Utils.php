<?php

declare(strict_types=1);

namespace Dosiero;

class Utils
{

    /**
     * The Windows set, a superset of the POSIX one, so a name accepted here works
     * on either system.
     */
    private const FORBIDDEN_CHARACTERS = '<>:"/\\|?*';

    /**
     * Windows device names, resolved before the file system with or without an
     * extension: "CON.txt" is still the console.
     *
     * @var array<string>
     */
    private const RESERVED_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM0', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT0', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    /**
     * Turns an arbitrary name into one that is safe on every supported system.
     */
    public static function normalizeFileName(string $fileName): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = $extension === ''
            ? $fileName
            : substr($fileName, 0, -(strlen($extension) + 1));

        $baseName = self::normalizePart($baseName);
        $extension = self::normalizePart($extension);

        if ($baseName === '') {
            $baseName = 'file';
        }
        if (self::isReservedName($baseName)) {
            $baseName .= '-file';
        }

        return $extension === '' ? $baseName : $baseName . '.' . $extension;
    }

    /**
     * True when the name can be stored as it is on every supported system.
     */
    public static function isValidFileName(string $fileName): bool
    {
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return false;
        }
        if (strpbrk($fileName, self::FORBIDDEN_CHARACTERS) !== false) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1) {
            return false;
        }
        // Windows silently strips these, so two different names end up as one file
        if (str_ends_with($fileName, '.') || str_ends_with($fileName, ' ')) {
            return false;
        }
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        return !self::isReservedName($baseName);
    }

    public static function isValidFolderName(string $folder): bool
    {
        if ($folder === '' || $folder === '.' || $folder === '..') {
            return false;
        }
        return self::isValidFileName($folder);
    }

    private static function normalizePart(string $part): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $part);
        if ($transliterated !== false) {
            $part = $transliterated;
        }

        // iconv leaves "?" behind for whatever it could not transliterate
        $part = str_replace(['?', "'", '`'], '', $part);
        // replaced by a space, not removed, so they cannot glue words together
        $part = (string)preg_replace('/[\x00-\x1F\x7F]/', ' ', $part);
        $part = str_replace(str_split(self::FORBIDDEN_CHARACTERS), '-', $part);
        $part = (string)preg_replace('/[\s-]+/', '-', $part);

        return trim($part, '-. ');
    }

    private static function isReservedName(string $baseName): bool
    {
        return in_array(strtoupper($baseName), self::RESERVED_NAMES, true);
    }
}