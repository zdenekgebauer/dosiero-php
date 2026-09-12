<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Dosiero\Utils;
use Tests\Support\UnitTester;

class UtilsTest extends Unit
{
    protected UnitTester $tester;

    /** @return array<string, array{string, string, bool}> */
    public function provideIpRanges(): array
    {
        return [
            'exact match' => ['127.0.0.1', '127.0.0.1', true],
            'different address' => ['127.0.0.2', '127.0.0.1', false],
            'inside a whole-byte range' => ['10.1.2.3', '10.0.0.0/8', true],
            'outside a whole-byte range' => ['11.1.2.3', '10.0.0.0/8', false],
            'inside a partial-byte range' => ['192.168.1.10', '192.168.1.0/28', true],
            'outside a partial-byte range' => ['192.168.1.30', '192.168.1.0/28', false],
            'zero prefix takes everything' => ['8.8.8.8', '0.0.0.0/0', true],
            'ipv6 inside' => ['2001:db8::1', '2001:db8::/32', true],
            'ipv6 outside' => ['2001:dbf::1', '2001:db8::/32', false],
            'ipv6 shorthand equals long form' => ['::1', '0:0:0:0:0:0:0:1', true],
            'ipv4 never matches an ipv6 range' => ['127.0.0.1', '::1/128', false],
            'nonsense address never matches' => ['not-an-address', '10.0.0.0/8', false],
        ];
    }

    /** @return array<string, array{string, int}> */
    public function providerBytes(): array
    {
        return [
            'plain number' => ['1024', 1024],
            'kilobytes' => ['512K', 512 * 1024],
            'megabytes' => ['5M', 5 * 1024 * 1024],
            'gigabytes' => ['2G', 2 * 1024 * 1024 * 1024],
            'lowercase suffix' => ['5m', 5 * 1024 * 1024],
            'surrounding space' => [' 5M ', 5 * 1024 * 1024],
            'empty' => ['', 0],
            'unlimited' => ['-1', 0],
            /* php.ini reads a two-letter suffix as a plain number, so "5MB" is five bytes; the
               parser must not quietly turn it into five megabytes */
            'two letter suffix' => ['5MB', 0],
            'nonsense' => ['many', 0],
        ];
    }

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

    /** @dataProvider provideIpRanges */
    public function testIpMatchesRange(string $address, string $range, bool $expected): void
    {
        $this->tester->assertSame($expected, Utils::ipMatchesRange($address, Utils::normalizeIpRange($range)));
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

    public function testNormalizeIpRangeRefusesNonsense(): void
    {
        $this->tester->expectThrowable(
            new \InvalidArgumentException('invalid IP range "10.0.0.0/x"'),
            static function (): void {
                Utils::normalizeIpRange('10.0.0.0/x');
            },
        );
    }

    /** @dataProvider providerBytes */
    public function testParseBytes(string $input, int $expected): void
    {
        $this->tester->assertSame($expected, Utils::parseBytes($input));
    }
}
