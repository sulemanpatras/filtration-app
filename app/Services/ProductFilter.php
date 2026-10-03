<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Filters a shop's synced catalog and computes facet counts.
 *
 * Values within a facet are OR'ed, facets are AND'ed together, and each facet's
 * counts are computed with every other active filter except its own (disjunctive
 * faceting), matching how Shopify's native storefront filters behave.
 */
class ProductFilter
{
    public const SORTS = [
        'created-descending' => ['shopify_created_at', 'desc'],
        'created-ascending' => ['shopify_created_at', 'asc'],
        'price-ascending' => ['price_min', 'asc'],
        'price-descending' => ['price_min', 'desc'],
        'title-ascending' => ['title', 'asc'],
        'title-descending' => ['title', 'desc'],
    ];

    /**
     * @param  array{vendor: string[], type: string[], tag: string[], options: array<string, string[]>, available: ?bool, price_min: ?float, price_max: ?float}  $filters
     */
    public function __construct(
        private readonly Shop $shop,
        private readonly string $collectionHandle,
        private readonly array $filters,
    ) {}

    /**
     * Normalize the untrusted `f` JSON sent by the storefront.
     *
     * @return array{vendor: string[], type: string[], tag: string[], options: array<string, string[]>, available: ?bool, price_min: ?float, price_max: ?float}
     */
    public static function parseFilters(?string $json): array
    {
        $raw = json_decode((string) $json, true);
        $raw = is_array($raw) ? $raw : [];

        $strings = fn ($value) => array_values(array_slice(array_unique(array_filter(
            is_array($value) ? $value : [],
            fn ($v) => is_string($v) && $v !== '' && mb_strlen($v) <= 255,
        )), 0, 50));

        $options = [];
        foreach (array_slice(is_array($raw['options'] ?? null) ? $raw['options'] : [], 0, 20, true) as $name => $values) {
            if (is_string($name) && ($values = $strings($values))) {
                $options[$name] = $values;
            }
        }

        $number = fn ($v) => is_numeric($v) && $v >= 0 ? (float) $v : null;

        return [
            'vendor' => $strings($raw['vendor'] ?? []),
            'type' => $strings($raw['type'] ?? []),
            'tag' => $strings($raw['tag'] ?? []),
            'options' => $options,
            'available' => is_bool($raw['available'] ?? null) ? $raw['available'] : null,
            'price_min' => $number($raw['price']['min'] ?? null),
            'price_max' => $number($raw['price']['max'] ?? null),
        ];
    }

    /** Published products in the requested collection, with no filters applied. */
    public function baseQuery(): ?Builder
    {
        $query = Product::query()
            ->where('products.shop_id', $this->shop->id)
            ->where('products.published', true);

        if ($this->collectionHandle === 'all') {
            return $query;
        }

        $collectionId = $this->shop->collections()->where('handle', $this->collectionHandle)->value('id');
        if (! $collectionId) {
            return null;
        }

        return $query->whereExists(fn ($q) => $q->select(DB::raw(1))
            ->from('collection_product')
            ->whereColumn('collection_product.product_id', 'products.id')
            ->where('collection_product.collection_id', $collectionId));
    }

    /**
     * @param  string|null  $except  Facet to leave out ('vendor', 'type', 'tag', 'available', 'price' or 'option:<name>')
     */
    public function filteredQuery(?string $except = null): ?Builder
    {
        $query = $this->baseQuery();
        if (! $query) {
            return null;
        }

        $f = $this->filters;

        if ($f['vendor'] && $except !== 'vendor') {
            $query->whereIn('products.vendor', $f['vendor']);
        }
        if ($f['type'] && $except !== 'type') {
            $query->whereIn('products.product_type', $f['type']);
        }
        if ($f['tag'] && $except !== 'tag') {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('product_tags')
                ->whereColumn('product_tags.product_id', 'products.id')
                ->whereIn('product_tags.tag', $f['tag']));
        }
        foreach ($f['options'] as $name => $values) {
            if ($except === "option:$name" || $except === 'options') {
                continue;
            }
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('product_options')
                ->whereColumn('product_options.product_id', 'products.id')
                ->where('product_options.name', $name)
                ->whereIn('product_options.value', $values));
        }
        if ($f['available'] !== null && $except !== 'available') {
            $query->where('products.available', $f['available']);
        }
        if ($except !== 'price') {
            if ($f['price_min'] !== null) {
                $query->where('products.price_min', '>=', $f['price_min']);
            }
            if ($f['price_max'] !== null) {
                $query->where('products.price_min', '<=', $f['price_max']);
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function results(string $sort, int $page, int $perPage): array
    {
        $query = $this->filteredQuery();
        if (! $query) {
            return ['products' => [], 'total' => 0, 'collection_total' => 0, 'page' => 1, 'pages' => 0, 'facets' => $this->emptyFacets()];
        }

        [$column, $direction] = self::SORTS[$sort] ?? self::SORTS['created-descending'];
        $paginator = $query->orderBy("products.$column", $direction)
            ->orderBy('products.id')
            ->paginate($perPage, ['products.*'], 'page', $page);

        return [
            'products' => $paginator->getCollection()->map(fn (Product $p) => [
                'id' => $p->shopify_id,
                'handle' => $p->handle,
                'title' => $p->title,
                'vendor' => $p->vendor,
                'url' => "/products/{$p->handle}",
                'image' => $p->image_url,
                'image_alt' => $p->image_alt ?: $p->title,
                // Cents, so the storefront can format with the theme's money_format.
                'price_min' => (int) round($p->price_min * 100),
                'price_max' => (int) round($p->price_max * 100),
                'compare_at_price' => $p->compare_at_price !== null ? (int) round($p->compare_at_price * 100) : null,
                'available' => $p->available,
            ])->values(),
            'total' => $paginator->total(),
            // Unfiltered size of the collection; the storefront only takes over above the threshold.
            'collection_total' => $this->baseQuery()->count(),
            'page' => $paginator->currentPage(),
            'pages' => $paginator->lastPage(),
            'facets' => $this->facets(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function facets(): array
    {
        $limit = config('shopify.facet_limit');

        return [
            'vendor' => $this->columnFacet('vendor', 'vendor', $limit),
            'type' => $this->columnFacet('product_type', 'type', $limit),
            'tag' => $this->pivotFacet('product_tags', 'tag', $this->filteredQuery('tag'), $limit),
            'options' => $this->optionFacets($limit),
            'available' => $this->availability(),
            'price' => $this->priceRange(),
        ];
    }

    /** @return array<int, array{value: string, count: int}> */
    private function columnFacet(string $column, string $facet, int $limit): array
    {
        return $this->filteredQuery($facet)
            ->toBase()
            ->whereNotNull("products.$column")
            ->selectRaw("products.$column as value, count(*) as count")
            ->groupBy("products.$column")
            ->orderByDesc('count')
            ->orderBy('value')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])
            ->all();
    }

    /** @return array<int, array{value: string, count: int}> */
    private function pivotFacet(string $table, string $column, Builder $products, int $limit, ?string $optionName = null): array
    {
        return DB::table($table)
            ->whereIn("$table.product_id", $products->select('products.id'))
            ->when($optionName !== null, fn ($q) => $q->where("$table.name", $optionName))
            ->selectRaw("$table.$column as value, count(*) as count")
            ->groupBy("$table.$column")
            ->orderByDesc('count')
            ->orderBy('value')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])
            ->all();
    }

    /** @return array<int, array{name: string, values: array<int, array{value: string, count: int}>}> */
    private function optionFacets(int $limit): array
    {
        // One grouped query for all option names with option filters removed; names with an
        // active selection are then recounted so that only their own selection is ignored.
        $rows = DB::table('product_options')
            ->whereIn('product_id', $this->filteredQuery('options')->select('products.id'))
            ->selectRaw('name, value, count(*) as count')
            ->groupBy('name', 'value')
            ->orderByDesc('count')
            ->orderBy('value')
            ->get()
            ->groupBy('name');

        $facets = [];
        foreach ($rows as $name => $values) {
            $values = count($this->filters['options']) === 0 || (count($this->filters['options']) === 1 && isset($this->filters['options'][$name]))
                ? $values->take($limit)->map(fn ($r) => ['value' => $r->value, 'count' => (int) $r->count])->values()->all()
                : $this->pivotFacet('product_options', 'value', $this->filteredQuery("option:$name"), $limit, $name);

            $facets[] = ['name' => (string) $name, 'values' => $values];
        }

        return $facets;
    }

    /** @return array{in_stock: int, out_of_stock: int} */
    private function availability(): array
    {
        $counts = $this->filteredQuery('available')
            ->toBase()
            ->selectRaw('products.available as value, count(*) as count')
            ->groupBy('products.available')
            ->pluck('count', 'value')
            ->mapWithKeys(fn ($count, $value) => [(int) (bool) $value => (int) $count]);

        return ['in_stock' => $counts[1] ?? 0, 'out_of_stock' => $counts[0] ?? 0];
    }

    /** @return array{min: int, max: int} */
    private function priceRange(): array
    {
        $row = $this->filteredQuery('price')->toBase()
            ->selectRaw('min(products.price_min) as min, max(products.price_min) as max')
            ->first();

        return [
            'min' => (int) floor(($row->min ?? 0)),
            'max' => (int) ceil(($row->max ?? 0)),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyFacets(): array
    {
        return [
            'vendor' => [], 'type' => [], 'tag' => [], 'options' => [],
            'available' => ['in_stock' => 0, 'out_of_stock' => 0],
            'price' => ['min' => 0, 'max' => 0],
        ];
    }
}
