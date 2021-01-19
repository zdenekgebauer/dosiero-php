<?php

declare(strict_types=1);

namespace Dosiero;

class Utils
{

    public static function normalizeFileName(string $fileName): string
    {
        $fileName = (string)iconv('UTF-8', 'ASCII//TRANSLIT', $fileName);
        return str_replace(' ', '-', $fileName);
    }

    public static function isValidFolderName(string $folder): bool
    {
        return preg_match('/^[a-z0-9-_.]+$/i', $folder) === 1;
    }
}
