<?php

/**
 * Inherited Methods
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 *
 * @SuppressWarnings(PHPMD)
 */
class IntegrationTester extends \Codeception\Actor
{
    use _generated\IntegrationTesterActions;

    /**
     * Define custom actions here
     */

    public function emptyDirRecursive(string $dir, bool $selfDelete = false): void
    {
        $dir = rtrim($dir, '/');
        if (is_dir($dir)) {
            $dirHandler = opendir($dir);
            if ($dirHandler !== false) {
                while (($item = readdir($dirHandler)) !== false) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    $fullpath = $dir . '/' . $item;
                    if (is_file($fullpath)) {
                        unlink($fullpath);
                    }
                    if (is_dir($fullpath)) {
                        self::emptyDirRecursive($fullpath, true);
                    }
                }
                closedir($dirHandler);
            }
            if ($selfDelete) {
                rmdir($dir);
            }
        }
    }
}
