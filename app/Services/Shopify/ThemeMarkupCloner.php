<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Models\ThemeMarkup;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gets a 100%-real copy of the active theme's filter sidebar by asking Shopify's own
 * Section Rendering API to render it — the same mechanism themes use for their own AJAX
 * filtering — for one of the shop's own collections that's still under the native-filter
 * threshold. The real HTML that comes back (real classes, real spacing, the theme's own
 * price-range markup) is then turned into small reusable templates: a "row" template (one
 * checkbox option), a "group" template (one collapsible section), an optional "price"
 * template, and a "shell" template (the outer wrapper). Cached per theme; re-run only via
 * AnalyzeThemeStyle's sibling job, CloneThemeMarkup, on install and on theme changes.
 *
 * Every extraction step is best-effort: if a piece can't be found with confidence, that
 * key is simply left out (or the whole analysis is skipped) rather than guessed at, so the
 * storefront always has a safe, already-working fallback to drop back to.
 */
class ThemeMarkupCloner
{
    private const HEADING_MATCH = "self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 ".
        "or self::summary or self::legend or ".
        "contains(concat(' ', normalize-space(@class), ' '), ' heading ') or ".
        "contains(concat(' ', normalize-space(@class), ' '), ' title ') or ".
        "contains(concat(' ', normalize-space(@class), ' '), ' summary ')";

    public function __construct(private readonly Shop $shop) {}

    public function clone(bool $force = false): void
    {
        $api = new AdminApi($this->shop);
        $themeId = $api->activeThemeId();
        if (! $themeId) {
            Log::info('Theme markup: could not determine the active theme id (check the read_themes scope)', ['shop' => $this->shop->domain]);

            return;
        }

        $existing = $this->shop->themeMarkup;
        if (! $force && $existing && $existing->theme_id === $themeId) {
            return; // Already analyzed this exact theme; nothing to do.
        }

        $donorHandle = $this->pickDonorCollection();
        if (! $donorHandle) {
            Log::info('Theme markup: no under-threshold collection with products found to clone from', ['shop' => $this->shop->domain]);

            return;
        }

        $sectionIds = $this->resolveCandidateSectionIds($api, $themeId, $donorHandle);
        if (! $sectionIds) {
            Log::info('Theme markup: no template file or section markup found for the donor collection', [
                'shop' => $this->shop->domain, 'theme_id' => $themeId, 'donor' => $donorHandle,
            ]);

            return;
        }

        $html = $this->fetchSectionsHtml($donorHandle, $sectionIds);
        if ($html === '') {
            Log::info('Theme markup: section fetch returned no HTML', [
                'shop' => $this->shop->domain, 'donor' => $donorHandle, 'section_ids' => $sectionIds,
            ]);

            return;
        }

        $templates = $this->extractTemplates($html);
        if (! $templates || (! $templates['row'] && ! $templates['price'] && ! $templates['card'])) {
            Log::info('Theme markup: extraction found nothing usable in the fetched HTML', [
                'shop' => $this->shop->domain, 'donor' => $donorHandle, 'section_ids' => $sectionIds, 'html_length' => strlen($html),
            ]);

            return;
        }

        Log::info('Theme markup: cloned successfully', [
            'shop' => $this->shop->domain, 'donor' => $donorHandle, 'section_ids' => $sectionIds,
            'found' => array_keys(array_filter($templates)),
        ]);

        ThemeMarkup::updateOrCreate(
            ['shop_id' => $this->shop->id],
            [
                'theme_id' => $themeId,
                'donor_handle' => $donorHandle,
                'section_id' => implode(',', $sectionIds),
                'templates' => $templates,
                'analyzed_at' => now(),
            ],
        );
    }

    /** An under-threshold collection with enough products for its filters to show. */
    private function pickDonorCollection(): ?string
    {
        $threshold = config('shopify.filter_threshold');

        $row = DB::table('collections')
            ->join('collection_product', 'collection_product.collection_id', '=', 'collections.id')
            ->join('products', 'products.id', '=', 'collection_product.product_id')
            ->where('collections.shop_id', $this->shop->id)
            ->where('products.published', true)
            ->groupBy('collections.id', 'collections.handle')
            ->havingRaw('count(*) between 1 and ?', [$threshold])
            ->orderByDesc(DB::raw('count(*)'))
            ->first(['collections.handle']);

        return $row?->handle;
    }

    /**
     * Finds every section in the collection's actual template file that plausibly renders
     * the filter sidebar and/or the product grid. The template filename isn't assumed to
     * be the default "collection.json" — the collection's real `templateSuffix` is looked
     * up first, since a merchant may have assigned a custom template, and a legacy
     * (non-JSON) `.liquid` template is also handled by scanning for `{% section %}` tags
     * instead of a JSON "order" array. Many themes (Dawn included) render the sidebar and
     * grid from one combined section, but some split them into two independent sections —
     * returning every plausible candidate, rather than a single "best guess", means we
     * still catch both pieces either way.
     *
     * @return string[]
     */
    private function resolveCandidateSectionIds(AdminApi $api, string $themeId, string $donorHandle): array
    {
        $suffix = $this->collectionTemplateSuffix($api, $donorHandle);

        $candidateFiles = [];
        if ($suffix) {
            $candidateFiles[] = "templates/collection.{$suffix}.json";
            $candidateFiles[] = "templates/collection.{$suffix}.liquid";
        }
        $candidateFiles[] = 'templates/collection.json';
        $candidateFiles[] = 'templates/collection.liquid';

        $files = $api->themeFiles($themeId, $candidateFiles);

        foreach ($candidateFiles as $filename) {
            $content = $files[$filename] ?? '';
            if ($content === '') {
                continue;
            }

            if (str_ends_with($filename, '.json')) {
                $ids = $this->sectionIdsFromJsonTemplate($content);
            } else {
                $ids = $this->sectionIdsFromLiquidTemplate($content);
            }
            if ($ids) {
                return $ids;
            }
        }

        return [];
    }

    private function collectionTemplateSuffix(AdminApi $api, string $handle): ?string
    {
        $data = $api->graphql(
            'query($handle: String!) { collectionByHandle(handle: $handle) { templateSuffix } }',
            ['handle' => $handle],
        );

        $collection = $data['collectionByHandle'] ?? null;

        return is_array($collection) ? ($collection['templateSuffix'] ?? null) : null;
    }

    /** @return string[] */
    private function sectionIdsFromJsonTemplate(string $json): array
    {
        // Shopify's theme editor prepends an auto-generated "/* ... */" comment header to
        // any JSON template a merchant has edited visually. Standard JSON disallows
        // comments, so json_decode() silently fails on the untouched string — trimming to
        // the first "{" discards the header without risking a naive strip-all-comments
        // regex corrupting a legitimate "/*"-containing string value elsewhere in the file.
        $start = strpos($json, '{');
        if ($start === false) {
            return [];
        }
        $template = json_decode(substr($json, $start), true);
        if (! is_array($template) || empty($template['sections']) || empty($template['order'])) {
            return [];
        }

        $strong = [];
        $weak = [];
        foreach ($template['order'] as $id) {
            $type = strtolower($template['sections'][$id]['type'] ?? '');
            if ($type === '') {
                continue;
            }
            if (preg_match('/collection.*grid|product.*grid|collection.*template|filter|facet/', $type)) {
                $strong[] = $id;
            } elseif (str_contains($type, 'collection') || str_contains($type, 'product')) {
                $weak[] = $id;
            }
        }

        $ids = array_slice(array_unique([...$strong, ...$weak]), 0, 4);

        return $ids ?: [$template['order'][0]]; // Last resort: the template's first section.
    }

    /** A legacy (non-JSON) template has no "order" array — scan for {% section 'x' %} tags instead. */
    private function sectionIdsFromLiquidTemplate(string $liquid): array
    {
        preg_match_all('/\{%-?\s*section\s+[\'"]([\w-]+)[\'"]\s*-?%\}/', $liquid, $m);

        return array_slice(array_unique($m[1] ?? []), 0, 4);
    }

    /** Fetches each candidate section's real rendered HTML and concatenates them. */
    private function fetchSectionsHtml(string $handle, array $sectionIds): string
    {
        $html = '';
        foreach ($sectionIds as $sectionId) {
            try {
                $response = Http::timeout(10)->get(
                    "https://{$this->shop->domain}/collections/{$handle}",
                    ['section_id' => $sectionId],
                );
                if ($response->successful()) {
                    $html .= "\n".$response->body();
                }
            } catch (\Throwable $e) {
                Log::warning('Theme markup: section fetch failed', [
                    'shop' => $this->shop->domain, 'section_id' => $sectionId, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $html;
    }

    /** @return array{shell: ?string, group: ?string, row: ?string, price: ?string}|null */
    private function extractTemplates(string $html): ?array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        try {
            $price = $this->extractPriceTemplate($dom, $xpath);
            $rowGroup = $this->extractRowAndGroup($dom, $xpath);
            $cardData = $this->extractCardTemplate($dom, $xpath);
        } catch (\Throwable $e) {
            Log::warning('Theme markup: extraction failed', ['shop' => $this->shop->domain, 'error' => $e->getMessage()]);

            return null;
        }

        $shell = null;
        if ($rowGroup && $rowGroup['group_node'] && $rowGroup['group_node']->parentNode instanceof DOMElement) {
            $shellNode = $rowGroup['group_node']->parentNode;
            while ($shellNode->firstChild) {
                $shellNode->removeChild($shellNode->firstChild);
            }
            $shellNode->appendChild(new DOMText('{{GROUPS}}'));
            $shell = $dom->saveHTML($shellNode) ?: null;
        }

        return [
            'shell' => $shell,
            'group' => $rowGroup['group_html'] ?? null,
            'row' => $rowGroup['row_html'] ?? null,
            'price' => $price,
            'card' => $cardData['card'] ?? null,
            'sold_out_badge' => $cardData['sold_out_badge'] ?? null,
            'grid_selector' => $cardData['grid_selector'] ?? null,
            'card_selector' => $cardData['card_selector'] ?? null,
        ];
    }

    private function extractPriceTemplate(DOMDocument $dom, DOMXPath $xpath): ?string
    {
        $ranges = $xpath->query('//input[@type="range"]');
        if ($ranges && $ranges->length > 0) {
            $node = $ranges->item(0);
            $container = $this->closestAncestor($node, fn () => true, 3) ?? $node->parentNode;
            if ($container instanceof DOMElement) {
                return $dom->saveHTML($container) ?: null;
            }
        }

        $inputs = $xpath->query('//input[@type="text" or @type="number"][
            contains(translate(concat(@name, " ", @id, " ", @class, " ", @placeholder), "PRICMINAXFROT", "pricminaxfrot"), "price")
            or contains(translate(concat(@name, " ", @id, " ", @class), "PRICMINAXFROT", "pricminaxfrot"), "min")
            or contains(translate(concat(@name, " ", @id, " ", @class), "PRICMINAXFROT", "pricminaxfrot"), "max")
        ]');
        if (! $inputs || $inputs->length < 2) {
            return null;
        }

        $first = $inputs->item(0);
        $second = null;
        for ($i = 1; $i < $inputs->length; $i++) {
            if ($this->closestCommonAncestor($first, $inputs->item($i), 4)) {
                $second = $inputs->item($i);
                break;
            }
        }
        if (! $second) {
            return null;
        }

        $container = $this->closestCommonAncestor($first, $second, 4);
        if (! $container instanceof DOMElement) {
            return null;
        }

        if ($first instanceof DOMElement) {
            $first->setAttribute('value', '{{MIN}}');
        }
        if ($second instanceof DOMElement) {
            $second->setAttribute('value', '{{MAX}}');
        }

        return $dom->saveHTML($container) ?: null;
    }

    /**
     * Finds a real product card (via its /products/ link), climbs to the level that
     * actually repeats (the real grid), and extracts a reusable card template plus CSS
     * selectors for the grid container and card wrapper — so the storefront can find the
     * theme's *own* grid on the live page and fill it with the theme's *own* card markup.
     *
     * @return array{card: ?string, sold_out_badge: ?string, grid_selector: ?string, card_selector: ?string}|null
     */
    private function extractCardTemplate(DOMDocument $dom, DOMXPath $xpath): ?array
    {
        $links = $xpath->query('//a[contains(@href, "/products/")]');
        if (! $links || $links->length === 0) {
            return null;
        }
        $anchor = $links->item(0);
        $candidate = $anchor->parentNode instanceof DOMElement ? $anchor->parentNode : null;
        if (! $candidate) {
            return null;
        }

        $found = $this->findRepeatingUnit($candidate, 6);
        if (! $found) {
            return null; // No confirmed repetition (e.g. donor collection has 1 product); skip.
        }
        $card = $found['unit'];
        $grid = $found['container'];

        // Title + link: the longest text among all /products/ links inside this card is
        // almost always the product title (as opposed to a bare image link).
        $titleLinks = $xpath->query('.//a[contains(@href, "/products/")]', $card);
        $titleNode = null;
        $titleLen = 0;
        foreach ($titleLinks as $a) {
            $t = $this->longestTextNode($xpath, $a);
            $len = $t ? mb_strlen(trim($t->nodeValue)) : 0;
            if ($len > $titleLen) {
                $titleNode = $t;
                $titleLen = $len;
            }
        }
        if ($titleNode) {
            $titleNode->nodeValue = '{{TITLE}}';
        }
        foreach ($titleLinks as $a) {
            if ($a instanceof DOMElement) {
                // href is a URI-typed attribute: DOMDocument percent-encodes "{{...}}"
                // when serializing it (unlike plain attributes such as "value"), so a
                // bracket-free token is used here specifically.
                $a->setAttribute('href', '__BF_URL__');
            }
        }

        $imgs = $xpath->query('.//img', $card);
        if ($imgs && $imgs->length > 0 && $imgs->item(0) instanceof DOMElement) {
            $img = $imgs->item(0);
            $img->setAttribute('src', '__BF_IMAGE_URL__');
            $img->setAttribute('alt', '{{TITLE}}');
            foreach (['srcset', 'data-src', 'data-srcset'] as $attr) {
                if ($img->hasAttribute($attr)) {
                    $img->setAttribute($attr, '__BF_IMAGE_URL__');
                }
            }
        }

        $priceEl = $this->findByClassSubstring($xpath, $card, 'price');
        if ($priceEl) {
            $priceText = $this->longestTextNode($xpath, $priceEl);
            if ($priceText) {
                $priceText->nodeValue = '{{PRICE}}';
            }
        }

        $vendorEl = $this->findByClassSubstring($xpath, $card, 'vendor');
        if ($vendorEl) {
            $vendorText = $this->longestTextNode($xpath, $vendorEl);
            if ($vendorText) {
                $vendorText->nodeValue = '{{VENDOR}}';
            }
        }

        $soldOutHtml = null;
        $badge = $this->findByClassSubstring($xpath, $card, 'sold')
            ?? $this->parentOfExactText($xpath, $card, 'sold out');
        if ($badge && $badge->parentNode) {
            $soldOutHtml = $dom->saveHTML($badge) ?: null;
            $badge->parentNode->replaceChild(new DOMText('{{SOLD_OUT}}'), $badge);
        }

        return [
            'card' => $dom->saveHTML($card) ?: null,
            'sold_out_badge' => $soldOutHtml,
            'grid_selector' => $this->cssSelectorFor($grid),
            'card_selector' => $this->cssSelectorFor($card),
        ];
    }

    private function findByClassSubstring(DOMXPath $xpath, DOMElement $scope, string $needle): ?DOMElement
    {
        $all = $xpath->query('.//*[@class]', $scope);
        if (! $all) {
            return null;
        }
        foreach ($all as $el) {
            if ($el instanceof DOMElement && stripos($el->getAttribute('class'), $needle) !== false) {
                return $el;
            }
        }

        return null;
    }

    private function parentOfExactText(DOMXPath $xpath, DOMElement $scope, string $text): ?DOMElement
    {
        $nodes = $xpath->query('.//text()[normalize-space(.) != ""]', $scope);
        if (! $nodes) {
            return null;
        }
        foreach ($nodes as $n) {
            if (strcasecmp(trim($n->nodeValue), $text) === 0 && $n->parentNode instanceof DOMElement) {
                return $n->parentNode;
            }
        }

        return null;
    }

    /** A CSS selector likely to match this same element on any other page of this theme. */
    private function cssSelectorFor(DOMElement $el): ?string
    {
        $classAttr = trim($el->getAttribute('class'));
        if ($classAttr !== '') {
            $classes = preg_split('/\s+/', $classAttr) ?: [];
            usort($classes, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($classes as $c) {
                if ($c !== '' && preg_match('/^[a-zA-Z][\w-]*$/', $c)) {
                    return '.'.$c;
                }
            }
        }
        $id = trim($el->getAttribute('id'));
        if ($id !== '' && ! preg_match('/\d{6,}/', $id)) {
            return '#'.$id;
        }

        return null;
    }

    /** @return array{row_html: ?string, group_html: ?string, group_node: ?DOMElement}|null */
    private function extractRowAndGroup(DOMDocument $dom, DOMXPath $xpath): ?array
    {
        $checkboxes = $xpath->query('//input[@type="checkbox"]');
        if (! $checkboxes || $checkboxes->length === 0) {
            return null;
        }
        $checkbox = $checkboxes->item(0);

        $rowCandidate = $checkbox->parentNode instanceof DOMElement ? $checkbox->parentNode : null;
        if (! $rowCandidate) {
            return null;
        }

        // Climb from the checkbox's immediate wrapper until we reach the level that
        // actually repeats (a theme often nests one more wrapper, e.g. <label> inside
        // <li>, before the real repeating sibling list appears).
        $found = $this->findRepeatingUnit($rowCandidate, 4);
        $rowNode = $found['unit'] ?? $rowCandidate;
        $rowsContainer = $found['container'] ?? ($rowCandidate->parentNode instanceof DOMElement ? $rowCandidate->parentNode : null);
        if (! $rowsContainer) {
            return null;
        }

        $textNodes = $xpath->query('.//text()[normalize-space(.) != ""]', $rowNode);
        $countNode = null;
        $labelNode = null;
        $labelLen = 0;
        if ($textNodes) {
            foreach ($textNodes as $t) {
                /** @var DOMText $t */
                $trimmed = trim($t->nodeValue);
                if (preg_match('/^\(?\d[\d,]*\)?$/', $trimmed)) {
                    $countNode ??= $t;
                } elseif (mb_strlen($trimmed) > $labelLen) {
                    $labelNode = $t;
                    $labelLen = mb_strlen($trimmed);
                }
            }
        }

        if ($countNode) {
            $countNode->nodeValue = preg_replace('/\d[\d,]*/', '{{COUNT}}', $countNode->nodeValue, 1) ?? $countNode->nodeValue;
        }
        if ($labelNode) {
            $labelNode->nodeValue = '{{LABEL}}';
        }
        if ($checkbox instanceof DOMElement) {
            $checkbox->setAttribute('value', '{{VALUE}}');
            $checkbox->removeAttribute('checked');
            $checkbox->removeAttribute('id');
        }

        $rowHtml = $dom->saveHTML($rowNode) ?: null;

        $groupNode = $this->closestAncestor($rowsContainer, function (DOMElement $candidate) use ($xpath, $rowsContainer) {
            $headings = $xpath->query('.//*['.self::HEADING_MATCH.']', $candidate);

            return $this->hasHeadingOutsideContainer($headings, $rowsContainer);
        }, 6) ?? ($rowsContainer->parentNode instanceof DOMElement ? $rowsContainer->parentNode : null);

        $groupHtml = null;
        $shellUnit = $groupNode;
        if ($groupNode) {
            $headings = $xpath->query('.//*['.self::HEADING_MATCH.']', $groupNode);
            $heading = $this->hasHeadingOutsideContainer($headings, $rowsContainer);
            if ($heading) {
                $headingText = $this->longestTextNode($xpath, $heading);
                if ($headingText) {
                    $headingText->nodeValue = '{{GROUP_TITLE}}';
                }
            }

            while ($rowsContainer->firstChild) {
                $rowsContainer->removeChild($rowsContainer->firstChild);
            }
            $rowsContainer->appendChild(new DOMText('{{ROWS}}'));

            // Same climb as above: the heading-bearing node might itself be nested one
            // level inside the thing that actually repeats across groups.
            $groupFound = $this->findRepeatingUnit($groupNode, 4);
            $shellUnit = $groupFound['unit'] ?? $groupNode;
            $groupHtml = $dom->saveHTML($shellUnit) ?: null;
        }

        return ['row_html' => $rowHtml, 'group_html' => $groupHtml, 'group_node' => $shellUnit];
    }

    /**
     * Climbs from $start until finding the level whose parent has 2+ children sharing
     * $start's (or the climbed candidate's) tag name — i.e. the level that actually
     * repeats. Returns null if no repetition is found within $maxDepth (a single-item
     * case, e.g. only one filter group on the donor collection).
     *
     * @return array{unit: DOMElement, container: DOMElement}|null
     */
    private function findRepeatingUnit(DOMElement $start, int $maxDepth): ?array
    {
        $unit = $start;
        $depth = 0;
        while ($unit->parentNode instanceof DOMElement && $depth < $maxDepth) {
            $container = $unit->parentNode;
            $siblingCount = 0;
            foreach ($container->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === strtolower($unit->tagName)) {
                    $siblingCount++;
                }
            }
            if ($siblingCount >= 2) {
                return ['unit' => $unit, 'container' => $container];
            }
            $unit = $container;
            $depth++;
        }

        return null;
    }

    private function hasHeadingOutsideContainer($headings, DOMElement $container): ?DOMElement
    {
        if (! $headings) {
            return null;
        }
        foreach ($headings as $h) {
            if (! $this->isDescendantOf($h, $container)) {
                return $h;
            }
        }

        return null;
    }

    private function longestTextNode(DOMXPath $xpath, DOMElement $el): ?DOMText
    {
        $nodes = $xpath->query('.//text()[normalize-space(.) != ""]', $el);
        $best = null;
        $bestLen = 0;
        if ($nodes) {
            foreach ($nodes as $n) {
                $len = mb_strlen(trim($n->nodeValue));
                if ($len > $bestLen) {
                    $best = $n;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }

    private function isDescendantOf(DOMNode $node, DOMNode $ancestor): bool
    {
        $cur = $node->parentNode;
        while ($cur) {
            if ($cur === $ancestor) {
                return true;
            }
            $cur = $cur->parentNode;
        }

        return false;
    }

    private function closestAncestor(DOMNode $node, callable $predicate, int $maxDepth): ?DOMElement
    {
        $cur = $node->parentNode;
        $depth = 0;
        while ($cur instanceof DOMElement && $depth < $maxDepth) {
            if ($predicate($cur)) {
                return $cur;
            }
            $cur = $cur->parentNode;
            $depth++;
        }

        return null;
    }

    private function closestCommonAncestor(DOMNode $a, DOMNode $b, int $maxDepth): ?DOMElement
    {
        $ancestorsOfA = [];
        $cur = $a->parentNode;
        $depth = 0;
        while ($cur instanceof DOMElement && $depth < $maxDepth) {
            $ancestorsOfA[] = $cur;
            $cur = $cur->parentNode;
            $depth++;
        }

        $cur = $b->parentNode;
        $depth = 0;
        while ($cur instanceof DOMElement && $depth < $maxDepth) {
            foreach ($ancestorsOfA as $candidate) {
                if ($candidate === $cur) {
                    return $cur;
                }
            }
            $cur = $cur->parentNode;
            $depth++;
        }

        return null;
    }
}
