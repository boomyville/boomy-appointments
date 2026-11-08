<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Wiring checks for the page templates (no database needed). */
final class PageAssetsTest extends TestCase
{
    /** @return list<string> */
    private function pages(): array
    {
        $pages = glob(dirname(__DIR__, 2) . '/*.php') ?: [];
        return array_values(array_filter($pages, static fn(string $f): bool => str_contains((string) file_get_contents($f), '<!doctype html>')));
    }

    public function testPagesThatLoadTheThemeScriptAlsoLoadTheThemeStylesheet(): void
    {
        // global.js creates the floating light/dark button; theme.css is what positions and styles it.
        // Without the stylesheet the button renders as a plain full-width button at the bottom of the page.
        $checked = 0;
        foreach ($this->pages() as $page) {
            $html = (string) file_get_contents($page);
            if (!str_contains($html, 'assets/js/global.js')) {
                continue;
            }
            $checked++;
            $this->assertStringContainsString('assets/css/theme.css', $html, basename($page) . ' loads global.js but not theme.css');
        }
        $this->assertGreaterThan(5, $checked, 'expected to find the pages that use global.js');
    }

    public function testEveryPageOutputsTheThemeOverrides(): void
    {
        foreach ($this->pages() as $page) {
            $this->assertStringContainsString('render_theme_head()', (string) file_get_contents($page), basename($page));
        }
    }

    public function testToggleButtonStylesExistInTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/theme.css');
        $this->assertMatchesRegularExpression('/\.theme-toggle-button\s*\{[^}]*position:\s*fixed/', $css);
    }

    public function testSettingsPageHasNoCardBackground(): void
    {
        $settings = (string) file_get_contents(dirname(__DIR__, 2) . '/settings.php');
        $this->assertMatchesRegularExpression('/html\[data-theme="dark"\] main[^{]*\{\s*background:\s*transparent;/', $settings);
    }
}
