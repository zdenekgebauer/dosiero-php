<?php

declare(strict_types=1);

namespace Tests\Unit;

use Dosiero\Utils;
use Tests\Support\UnitTester;

class UtilsTest extends \Codeception\Test\Unit
{
    protected UnitTester $tester;

    /** @return array<string, array{string, string}> */
    public function providerNormalize(): array
    {
        return [
            'diacritics' => ['obyčejný soubor.jpg', 'obycejny-soubor.jpg'],
            'colon' => ['faktura:2026.pdf', 'faktura-2026.pdf'],
            'wildcards' => ['co*je*tohle?.png', 'co-je-tohle.png'],
            'angle brackets and pipe' => ['a<b>c|d".txt', 'a-b-c-d.txt'],
            'control characters' => ["tab\tand\nnewline.txt", 'tab-and-newline.txt'],
            'untransliterable' => ['файл.jpg', 'file.jpg'],
            'emoji' => ['emoji-🎉.png', 'emoji.png'],
            'trailing dots' => ['trailing dot...txt', 'trailing-dot.txt'],
            'reserved device name' => ['CON.txt', 'CON-file.txt'],
            'no extension' => ['plain name', 'plain-name'],
        ];
    }

    /** @return array<string, array{string, bool}> */
    public function providerValidNames(): array
    {
        return [
            'plain' => ['file.txt', true],
            'diacritics are fine' => ['žluťoučký.txt', true],
            'spaces are fine' => ['my file.txt', true],
            'colon' => ['a:b.txt', false],
            'asterisk' => ['a*b.txt', false],
            'question mark' => ['a?b.txt', false],
            'quote' => ['a"b.txt', false],
            'pipe' => ['a|b.txt', false],
            'angle bracket' => ['a<b.txt', false],
            'slash' => ['a/b.txt', false],
            'backslash' => ['a\\b.txt', false],
            'null byte' => ["a\0b.txt", false],
            'newline' => ["a\nb.txt", false],
            'trailing dot' => ['file.', false],
            'trailing space' => ['file ', false],
            'reserved CON' => ['CON', false],
            'reserved with extension' => ['con.txt', false],
            'reserved COM1' => ['COM1.log', false],
            'empty' => ['', false],
            'dot' => ['.', false],
            'double dot' => ['..', false],
        ];
    }

    /** @dataProvider providerValidNames */
    public function testIsValidFileName(string $name, bool $expected): void
    {
        $this->tester->assertSame($expected, Utils::isValidFileName($name));
    }

    public function testIsValidFolderNameRejectsTraversal(): void
    {
        $this->tester->assertFalse(Utils::isValidFolderName('..'));
        $this->tester->assertFalse(Utils::isValidFolderName('.'));
        $this->tester->assertFalse(Utils::isValidFolderName(''));
        $this->tester->assertTrue(Utils::isValidFolderName('normal'));
        $this->tester->assertTrue(Utils::isValidFolderName('s diakritikou'));
    }

    public function testNormalizedNameIsAlwaysValid(): void
    {
        foreach ($this->providerNormalize() as $case) {
            $normalized = Utils::normalizeFileName($case[0]);
            $this->tester->assertTrue(
                Utils::isValidFileName($normalized),
                'normalizing "' . $case[0] . '" produced invalid "' . $normalized . '"',
            );
        }
    }

    /** @dataProvider providerNormalize */
    public function testNormalizeFileName(string $input, string $expected): void
    {
        $this->tester->assertSame($expected, Utils::normalizeFileName($input));
    }
}
