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
        $this->assertCount(14, $defaults);
        $limits = theme_radius_limits();
        foreach ($defaults as $key => $value) {
            if ($key === 'theme_font') {
                $this->assertArrayHasKey($value, theme_font_options());
            } elseif (isset($limits[$key])) {
                $this->assertTrue($value === '' || theme_normalize_int($value, $limits[$key][0], $limits[$key][1]) !== null, $key . ' default must be empty or a valid number');
            } else {
                $this->assertSame($value, theme_normalize_hex($value), $key . ' should be a normalised hex colour');
            }
        }
        $this->assertSame('', $defaults['theme_radius'], 'corners keep the app defaults unless the admin customises them');
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

    /** @return array<string, array{0: string, 1: int, 2: int, 3: ?int}> */
    public static function intCases(): array
    {
        return [
            'plain number'        => ['12', 0, 50, 12],
            'lower bound'         => ['0', 0, 50, 0],
            'upper bound'         => ['50', 0, 50, 50],
            'above range'         => ['51', 0, 50, null],
            'below range'         => ['3', 4, 80, null],
            'surrounding spaces'  => [' 7 ', 0, 50, 7],
            'decimal rejected'    => ['7.5', 0, 50, null],
            'negative rejected'   => ['-1', 0, 50, null],
            'text rejected'       => ['abc', 0, 50, null],
            'empty rejected'      => ['', 0, 50, null],
            'css rejected'        => ['10px;}', 0, 50, null],
        ];
    }

    #[DataProvider('intCases')]
    public function testNormalizeInt(string $input, int $min, int $max, ?int $expected): void
    {
        $this->assertSame($expected, theme_normalize_int($input, $min, $max));
    }

    public function testRadiusValueIsAPercentageBetweenAMinimumAndMaximum(): void
    {
        $this->assertSame('min(32px,max(4px,10%))', theme_radius_value(10, 4, 32, 1.0));
        $this->assertSame('min(16px,max(2px,5%))', theme_radius_value(10, 4, 32, 0.5), 'small controls use half');
        $this->assertSame('min(48px,max(6px,15%))', theme_radius_value(10, 4, 32, 1.5), 'large panels use one and a half');
        $this->assertSame('min(48px,max(6px,50%))', theme_radius_value(40, 4, 32, 1.5), 'the percentage never goes past 50%');
        $this->assertSame('min(20px,max(20px,10%))', theme_radius_value(10, 20, 8, 1.0), 'a maximum below the minimum cannot undercut it');
        $this->assertSame('min(32px,max(0px,0%))', theme_radius_value(0, 0, 32, 1.0), 'square corners');
    }

    public function testSanitizeValidatesTheCornerSettings(): void
    {
        $clean = sanitize_theme_post(['theme_radius' => '12', 'theme_radius_min' => '6', 'theme_radius_max' => '40']);
        $this->assertSame('12', $clean['theme_radius']);
        $this->assertSame('6', $clean['theme_radius_min']);
        $this->assertSame('40', $clean['theme_radius_max']);

        $defaultsOnly = sanitize_theme_post(['theme_radius' => '12', 'theme_radius_min' => '4', 'theme_radius_max' => '32']);
        $this->assertSame('', $defaultsOnly['theme_radius_min'], 'min/max equal to their defaults are not stored');
        $this->assertSame('', $defaultsOnly['theme_radius_max']);

        $invalid = sanitize_theme_post(['theme_radius' => '99', 'theme_radius_min' => '6', 'theme_radius_max' => '40']);
        $this->assertSame('', $invalid['theme_radius'], 'out of range falls back to the app default');
        $this->assertSame('', $invalid['theme_radius_min'], 'min/max are dropped when no radius is set');
        $this->assertSame('', $invalid['theme_radius_max']);

        $this->assertSame('', sanitize_theme_post(['theme_radius' => 'wide'])['theme_radius']);
        $this->assertSame('0', sanitize_theme_post(['theme_radius' => '0'])['theme_radius'], 'zero means square corners, not "off"');
    }

    public function testSanitizeValidatesBackgroundAndSurfaceColours(): void
    {
        $clean = sanitize_theme_post([
            'theme_background' => '#EEF2F7', 'theme_surface' => '#ffffff',
            'theme_background_dark' => 'black', 'theme_surface_dark' => '#101010',
        ]);

        $this->assertSame('#eef2f7', $clean['theme_background']);
        $this->assertSame('', $clean['theme_surface'], 'equal to the default');
        $this->assertSame('', $clean['theme_background_dark'], 'colour names are rejected');
        $this->assertSame('#101010', $clean['theme_surface_dark']);
    }

    public function testBackgroundAndSurfaceSetTheirVariablesPerMode(): void
    {
        $css = build_theme_css($this->theme(['theme_background' => '#eef2f7', 'theme_surface' => '#fafafa', 'theme_background_dark' => '#000000', 'theme_surface_dark' => '#202020']));

        $this->assertStringContainsString('--bg:#eef2f7;--theme-bg:#eef2f7;', $css);
        $this->assertStringContainsString('--panel:rgba(250,250,250,0.92);--panel-strong:#fafafa;--theme-surface:#fafafa;--input-bg:#fafafa;', $css);
        $this->assertStringContainsString('--bg:#000000;--theme-bg:#000000;', $css);
        $this->assertStringContainsString('--panel:rgba(32,32,32,0.92);--panel-strong:#202020;--theme-surface:#202020;', $css);
    }

    public function testOnlyTheChosenModeGetsBackgroundRules(): void
    {
        $css = build_theme_css($this->theme(['theme_background_dark' => '#000000']));

        $this->assertStringContainsString('html:root[data-theme="dark"]{--bg:#000000;', $css);
        $this->assertStringNotContainsString('data-theme="light"', $css, 'light mode keeps the page defaults');
    }

    public function testCornerVariablesAreEmittedForAllSizeTiers(): void
    {
        $css = build_theme_css($this->theme([
            'theme_radius' => '10', 'theme_radius_min' => '4', 'theme_radius_max' => '32',
        ]));

        $this->assertStringContainsString('html:root{--r-s:min(16px,max(2px,5%));--r-m:min(32px,max(4px,10%));--r-l:min(48px,max(6px,15%));--r-pill:min(32px,max(4px,10%));--pico-border-radius:var(--r-m);}', $css);
    }

    public function testNoCornerVariablesUnlessCustomised(): void
    {
        $this->assertStringNotContainsString('--r-m', build_theme_css($this->theme(['theme_primary' => '#ff0000'])));
        $this->assertStringNotContainsString('--r-m', build_theme_css($this->theme()));
    }

    public function testEmailRadiusKeepsTheOriginalUnlessCornersAreCustomised(): void
    {
        $this->assertSame('999px', theme_email_radius_for($this->theme(), 'pill', '999px'));
        $this->assertSame('14px', theme_email_radius_for($this->theme(), 'm', '14px'));
    }

    public function testEmailRadiusIsAPixelApproximationOfThePercentage(): void
    {
        $theme = $this->theme(['theme_radius' => '10', 'theme_radius_min' => '4', 'theme_radius_max' => '32']);
        $this->assertSame('4px', theme_email_radius_for($theme, 'pill', '999px'), '10% of a 44px button, never below the minimum');
        $this->assertSame('9px', theme_email_radius_for($theme, 'm', '14px'), '10% of a 90px panel');

        $round = $this->theme(['theme_radius' => '50', 'theme_radius_min' => '4', 'theme_radius_max' => '32']);
        $this->assertSame('22px', theme_email_radius_for($round, 'pill', '999px'));

        $capped = $this->theme(['theme_radius' => '50', 'theme_radius_min' => '4', 'theme_radius_max' => '10']);
        $this->assertSame('10px', theme_email_radius_for($capped, 'pill', '999px'), 'the maximum caps it');
    }
}
