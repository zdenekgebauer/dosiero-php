<?php

declare(strict_types=1);

namespace Dosiero;

use function in_array;

class Utils
{
    // the Windows set, a superset of the POSIX one, so a name accepted here works on either system
    private const string FORBIDDEN_CHARACTERS = '<>:"/\\|?*';

    // resolved before the file system, with or without an extension: "CON.txt" is still the console

    /** @var array<string> */
    private const array RESERVED_NAMES = [
        'CON',
        'PRN',
        'AUX',
        'NUL',
        'COM0',
        'COM1',
        'COM2',
        'COM3',
        'COM4',
        'COM5',
        'COM6',
        'COM7',
        'COM8',
        'COM9',
        'LPT0',
        'LPT1',
        'LPT2',
        'LPT3',
        'LPT4',
        'LPT5',
        'LPT6',
        'LPT7',
        'LPT8',
        'LPT9',
    ];

    /** @return int bytes, 0 when the directive is empty or unparseable */
    public static function iniBytes(string $directive): int
    {
        return self::parseBytes((string)ini_get($directive));
    }

    public static function ipMatchesRange(string $address, string $range): bool
    {
        $binaryAddress = @inet_pton($address);
        [$network, $bits] = explode('/', $range, 2);
        $binaryNetwork = @inet_pton($network);
        if ($binaryAddress === false || $binaryNetwork === false) {
            return false;
        }
        // an IPv4 address never matches an IPv6 range, and the packed forms differ in length
        if (strlen($binaryAddress) !== strlen($binaryNetwork)) {
            return false;
        }

        $prefix = (int)$bits;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($wholeBytes > 0 && strncmp($binaryAddress, $binaryNetwork, $wholeBytes) !== 0) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;
        return (ord($binaryAddress[$wholeBytes]) & $mask) === (ord($binaryNetwork[$wholeBytes]) & $mask);
    }

    public static function isHiddenName(string $name): bool
    {
        return str_starts_with($name, '.');
    }

    /** True when the name can be stored as it is on every supported system. */
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
     * A single address becomes a full-width prefix, so comparison has one shape. inet_pton()
     * normalizes the notation, which is why "::1" and its long form end up identical.
     *
     * @param string $entry a single address or CIDR notation
     */
    public static function normalizeIpRange(string $entry): string
    {
        $address = $entry;
        $bits = null;
        if (str_contains($entry, '/')) {
            [$address, $rawBits] = explode('/', $entry, 2);
            if (!is_numeric($rawBits)) {
                throw new \InvalidArgumentException('invalid IP range "' . $entry . '"');
            }
            $bits = (int)$rawBits;
        }

        $binary = @inet_pton($address);
        if ($binary === false) {
            throw new \InvalidArgumentException('invalid IP address "' . $entry . '"');
        }

        $width = strlen($binary) * 8;
        $bits ??= $width;
        if ($bits < 0 || $bits > $width) {
            throw new \InvalidArgumentException('invalid IP range "' . $entry . '"');
        }
        return inet_ntop($binary) . '/' . $bits;
    }

    /** @return int bytes, 0 when the value is empty or unparseable */
    public static function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(\d+)\s*([kmg]?)$/i', $value, $matches) !== 1) {
            return 0;
        }
        $bytes = (int)$matches[1];
        return match (strtolower($matches[2])) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private static function isReservedName(string $baseName): bool
    {
        return in_array(strtoupper($baseName), self::RESERVED_NAMES, true);
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
}
