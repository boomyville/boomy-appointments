<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function hexCases(): array
    {
        return [
            'short form expands'      => ['#ABC', '#aabbcc'],
            'upper case is lowered'   => ['#AABBCC', '#aabbcc'],
            'whitespace is trimmed'   => [' #123456 ', '#123456'],
            'colour name rejected'    => ['red', null],
            'five digits rejected'    => ['#12345', null],
            'non hex rejected'        => ['#gggggg', null],
            'missing hash rejected'   => ['123456', null],
            'empty rejected'          => ['', null],
            'css injection rejected'  => ['#fff;}</style><script>alert(1)</script>', null],
        ];
    }

    #[DataProvider('hexCases')]
    public function testNormalizeHex(string $input, ?string $expected): void
    {
        $this->assertSame($expected, theme_normalize_hex($input));
    }

    public function testNormalizeHexAcceptsNull(): void
    {
        $this->assertNull(theme_normalize_hex(null));
    }

    public function testHexToRgb(): void
    {
        $this->assertSame([255, 136, 0], theme_hex_to_rgb('#ff8800'));
        $this->assertSame([0, 0, 0], theme_hex_to_rgb('#000000'));
    }

    public function testMixHexMovesTowardTarget(): void
    {
        $this->assertSame('#808080', theme_mix_hex('#ffffff', '#000000', 0.5));
        $this->assertSame('#ffffff', theme_mix_hex('#ffffff', '#000000', 0.0));
        $this->assertSame('#000000', theme_mix_hex('#ffffff', '#000000', 1.0));
        $this->assertSame('#a60000', theme_mix_hex('#ff0000', '#000000', 0.35));
    }

    public function testDefaultsAreValidColoursAndAKnownFont(): void
    {
        $defaults = theme_defaults();
        $this->assertCount(7, $defaults);
        foreach ($defaults as $key => $value) {
            if ($key === 'theme_font') {
                $this->assertArrayHasKey($value, theme_font_options());
            } else {
                $this->assertSame($value, theme_normalize_hex($value), $key . ' should be a normalised hex colour');
            }
        }
    }

    public function testFontOptionsAreWellFormed(): void
    {
        $fonts = theme_font_options();
        foreach (['default', 'helvetica', 'trebuchet', 'fira_sans', 'kreon'] as $required) {
            $this->assertArrayHasKey($required, $fonts);
        }
        foreach ($fonts as $key => $font) {
            $this->assertNotSame('', $font['label'], $key);
            $this->assertNotSame('', $font['stack'], $key);
            $this->assertSame(0, preg_match('/[<>{};]/', $font['stack']), $key . ' font stack must not contain markup or CSS delimiters');
            if ($font['google'] !== null) {
                $this->assertSame(1, preg_match('/^[A-Za-z0-9+:;@.,=]+$/', $font['google']), $key . ' google spec');
            }
        }
    }

    public function testSanitizeKeepsValidCustomValuesAndBlanksDefaultsAndJunk(): void
    {
        $clean = sanitize_theme_post([
            'theme_primary'      => '#FF0000',
            'theme_secondary'    => '#10b981',   // equals the default
            'theme_tertiary'     => 'nope',
            'theme_primary_dark' => '#abc',
            'theme_font'         => 'lato',
        ]);

        $this->assertSame('#ff0000', $clean['theme_primary']);
        $this->assertSame('', $clean['theme_secondary']);
        $this->assertSame('', $clean['theme_tertiary']);
        $this->assertSame('#aabbcc', $clean['theme_primary_dark']);
        $this->assertSame('lato', $clean['theme_font']);
        $this->assertSame('', $clean['theme_secondary_dark'], 'missing fields become empty');
        $this->assertSame(array_keys(theme_defaults()), array_keys($clean));
    }

    public function testSanitizeRejectsDefaultAndUnknownFonts(): void
    {
        $this->assertSame('', sanitize_theme_post(['theme_font' => 'default'])['theme_font']);
        $this->assertSame('', sanitize_theme_post(['theme_font' => 'comic_sans<script>'])['theme_font']);
    }

    /** @return array{values: array<string,string>, custom: array<string,bool>, defaults: array<string,string>} */
    private function theme(array $overrides = [], string $font = 'default'): array
    {
        $defaults = theme_defaults();
        $values = $defaults;
        $custom = [];
        foreach ($overrides as $key => $value) {
            $values[$key] = $value;
            $custom[$key] = true;
        }
        if ($font !== 'default') {
            $values['theme_font'] = $font;
            $custom['theme_font'] = true;
        }
        return ['values' => $values, 'custom' => $custom, 'defaults' => $defaults];
    }

    public function testNothingCustomisedProducesNoCss(): void
    {
        $this->assertSame('', build_theme_css($this->theme()));
    }

    public function testLightPrimaryOnlyEmitsLightRulesWithDerivedColours(): void
    {
        $css = build_theme_css($this->theme(['theme_primary' => '#ff0000']));

        $this->assertStringContainsString('html:root[data-theme="light"]{--accent:#ff0000;', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: light){html:root:not([data-theme]){--accent:#ff0000;', $css);
        $this->assertStringContainsString('--accent-strong:#a60000;', $css);
        $this->assertStringContainsString('--accent-soft:rgba(255,0,0,0.12);', $css);
        $this->assertStringContainsString('--accent-rgb:255,0,0;', $css);
        $this->assertStringContainsString('--pico-primary:#ff0000;', $css);
        $this->assertStringNotContainsString('data-theme="dark"', $css, 'dark mode keeps the page defaults');
        $this->assertStringNotContainsString('--theme-font', $css);
    }

    public function testDarkPrimaryOnlyEmitsDarkRules(): void
    {
        $css = build_theme_css($this->theme(['theme_primary_dark' => '#00ff00']));

        $this->assertStringContainsString('html:root[data-theme="dark"]{--accent:#00ff00;', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
        $this->assertStringContainsString('--accent-soft:rgba(0,255,0,0.16);', $css);
        $this->assertStringNotContainsString('data-theme="light"', $css);
    }

    public function testSecondaryAndTertiaryMapToTheirVariables(): void
    {
        $css = build_theme_css($this->theme([
            'theme_secondary' => '#445566', 'theme_tertiary' => '#778899',
            'theme_secondary_dark' => '#ddeeff', 'theme_tertiary_dark' => '#123456',
        ]));

        $this->assertStringContainsString('--accent-2:#445566;', $css);
        $this->assertStringContainsString('--accent-3:#778899;--current:#778899;', $css);
        $this->assertStringContainsString('--accent-2:#ddeeff;', $css);
        $this->assertStringContainsString('--accent-3:#123456;--current:#123456;', $css);
    }

    public function testCustomFontSetsVariablesAndBodyRule(): void
    {
        $css = build_theme_css($this->theme([], 'helvetica'));

        $this->assertStringContainsString('--theme-font:"Helvetica Neue", Helvetica, Arial, sans-serif;', $css);
        $this->assertStringContainsString('html body,html button', $css);
    }
}
