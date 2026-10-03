<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Models\ThemeStyle;
use Illuminate\Support\Facades\Log;

/**
 * Reads a shop's active theme CSS (via the read-only Asset API) and extracts a small
 * set of design tokens — button color, radius, typography — so the storefront filter
 * UI can be styled to match without any per-theme configuration.
 *
 * Runs once per theme (cached in the theme_styles table) rather than on every request;
 * re-runs only when the merchant switches or updates their published theme.
 */
class ThemeStyleAnalyzer
{
    /** Selectors checked in priority order: most likely to be the theme's real "buy" button first. */
    private const BUTTON_PATTERNS = [
        '/\bproduct-form__submit\b/',
        '/\bproduct-form__buttons\b/',
        '/\bbutton--primary\b/',
        '/\bbtn--primary\b/',
        '/\bbtn-primary\b/',
        '/button\[type=[\'"]?submit[\'"]?\]/',
        '/(^|[\s,>+~.])\.button(?![\w-])/',
        '/(^|[\s,>+~.])\.btn(?![\w-])/',
    ];

    private const MAX_CSS_BYTES = 1_500_000;

    public function __construct(private readonly Shop $shop) {}

    /**
     * @param  bool  $force  Re-analyze even if this theme was already analyzed.
     */
    public function analyze(bool $force = false): void
    {
        $api = new AdminApi($this->shop);
        $themeId = $api->activeThemeId();
        if (! $themeId) {
            return;
        }

        $existing = $this->shop->themeStyle;
        if (! $force && $existing && $existing->theme_id === $themeId) {
            return; // Already analyzed this exact theme; nothing to do.
        }

        $css = $this->fetchThemeCss($api, $themeId);
        $tokens = $css !== '' ? $this->extractTokens($css) : [];

        ThemeStyle::updateOrCreate(
            ['shop_id' => $this->shop->id],
            ['theme_id' => $themeId, 'tokens' => $tokens, 'analyzed_at' => now()],
        );
    }

    private function fetchThemeCss(AdminApi $api, string $themeId): string
    {
        $layout = $api->themeFiles($themeId, ['layout/theme.liquid']);
        $entry = $layout['layout/theme.liquid'] ?? '';
        if ($entry === '') {
            return '';
        }

        preg_match_all('/[\'"]([\w\-.\/]+\.css)[\'"]\s*\|\s*asset_url/i', $entry, $matches);
        $filenames = array_unique(array_map(
            fn ($name) => str_starts_with($name, 'assets/') ? $name : "assets/{$name}",
            $matches[1] ?? [],
        ));
        if (! $filenames) {
            return '';
        }

        $files = $api->themeFiles($themeId, array_slice($filenames, 0, 10));
        $css = implode("\n", $files);

        return mb_strlen($css) > self::MAX_CSS_BYTES ? mb_substr($css, 0, self::MAX_CSS_BYTES) : $css;
    }

    /** @return array<string, string> */
    private function extractTokens(string $css): array
    {
        try {
            $rules = $this->splitRules($css);
        } catch (\Throwable $e) {
            Log::warning('Theme style: CSS parse failed', ['shop' => $this->shop->domain, 'error' => $e->getMessage()]);

            return [];
        }

        $vars = $this->collectCustomProperties($rules);
        $button = $this->findButtonRule($rules);
        if (! $button) {
            return [];
        }

        $decl = $this->parseDeclarations($button['decls'], $vars);
        $tokens = [];

        $bg = $decl['background-color'] ?? $decl['background'] ?? null;
        if ($bg && ! str_contains($bg, 'gradient') && $this->looksLikeColor($bg)) {
            $tokens['accent'] = $bg;
        }
        if (! empty($tokens['accent']) && ! empty($decl['color']) && $this->looksLikeColor($decl['color'])) {
            $tokens['accent_contrast'] = $decl['color'];
        }
        if (! empty($decl['border-radius'])) {
            $tokens['radius'] = $decl['border-radius'];
        }
        if (! empty($decl['font-weight'])) {
            $tokens['font_weight'] = $decl['font-weight'];
        }
        if (! empty($decl['text-transform'])) {
            $tokens['text_transform'] = $decl['text-transform'];
        }
        if (! empty($decl['letter-spacing'])) {
            $tokens['letter_spacing'] = $decl['letter-spacing'];
        }
        $borderWidth = $decl['border-width'] ?? (isset($decl['border']) ? $this->firstLength($decl['border']) : null);
        if ($borderWidth) {
            $tokens['border_width'] = $borderWidth;
        }

        return $tokens;
    }

    /**
     * Flattens CSS into (selector, declarations) pairs, recursing into @media/@supports
     * blocks so nested rules are still found.
     *
     * @return array<int, array{selector: string, decls: string}>
     */
    private function splitRules(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $rules = [];
        $len = strlen($css);
        $i = 0;
        $buffer = '';

        while ($i < $len) {
            $ch = $css[$i];
            if ($ch === '{') {
                $selector = trim($buffer);
                $buffer = '';
                $depth = 1;
                $start = $i + 1;
                $j = $start;
                while ($j < $len && $depth > 0) {
                    if ($css[$j] === '{') {
                        $depth++;
                    } elseif ($css[$j] === '}') {
                        $depth--;
                    }
                    $j++;
                }
                $inner = substr($css, $start, max(0, $j - $start - 1));
                if ($selector !== '' && $selector[0] === '@') {
                    $rules = [...$rules, ...$this->splitRules($inner)];
                } elseif ($selector !== '') {
                    $rules[] = ['selector' => $selector, 'decls' => $inner];
                }
                $i = $j;

                continue;
            }
            $buffer .= $ch;
            $i++;
        }

        return $rules;
    }

    /** @param  array<int, array{selector: string, decls: string}>  $rules @return array<string, string> */
    private function collectCustomProperties(array $rules): array
    {
        $vars = [];
        foreach ($rules as $rule) {
            if (! preg_match('/(^|[\s,])(:root|html|body)(?![\w-])/', $rule['selector'])) {
                continue;
            }
            if (preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $rule['decls'], $m, PREG_SET_ORDER)) {
                foreach ($m as $pair) {
                    $vars[$pair[1]] = trim($pair[2]);
                }
            }
        }

        return $vars;
    }

    /** @param  array<int, array{selector: string, decls: string}>  $rules */
    private function findButtonRule(array $rules): ?array
    {
        foreach (self::BUTTON_PATTERNS as $pattern) {
            foreach ($rules as $rule) {
                if (preg_match($pattern, $rule['selector']) && trim($rule['decls']) !== '') {
                    return $rule;
                }
            }
        }

        return null;
    }

    /** @param  array<string, string>  $vars @return array<string, string> */
    private function parseDeclarations(string $decls, array $vars, int $depth = 0): array
    {
        $out = [];
        if (preg_match_all('/([\w-]+)\s*:\s*([^;]+);?/', $decls, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $out[strtolower(trim($m[1]))] = $this->resolveVars(trim($m[2]), $vars, $depth);
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $vars */
    private function resolveVars(string $value, array $vars, int $depth): string
    {
        if ($depth > 3 || ! str_contains($value, 'var(')) {
            return $value;
        }

        return preg_replace_callback(
            '/var\(\s*(--[\w-]+)\s*(?:,\s*([^)]+))?\)/',
            function ($m) use ($vars, $depth) {
                $name = $m[1];
                $fallback = $m[2] ?? null;
                if (isset($vars[$name])) {
                    return $this->resolveVars($vars[$name], $vars, $depth + 1);
                }

                return $fallback !== null ? $this->resolveVars(trim($fallback), $vars, $depth + 1) : '';
            },
            $value,
        ) ?? $value;
    }

    private function looksLikeColor(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && $value !== 'transparent' && $value !== 'none'
            && (bool) preg_match('/^(#|rgb|hsl|[a-z]+$)/i', $value)
            && ! preg_match('/^\d/', $value);
    }

    private function firstLength(string $value): ?string
    {
        return preg_match('/(\d*\.?\d+(px|em|rem))/', $value, $m) ? $m[1] : null;
    }
}
