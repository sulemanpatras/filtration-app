<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FilterEngineService
{
    /**
     * Compute dynamic filter facets with counts for a collection.
     */
    public function getFacets(Shop $shop, ?string $collectionHandle = null, array $appliedFilters = []): array
    {
        $settings = $shop->getOrCreateFilterSetting();

        // Base query for products in this shop/collection
        $baseQuery = $this->buildBaseQuery($shop, $collectionHandle);

        // Calculate Price Bounds
        $priceStats = (clone $baseQuery)->selectRaw('MIN(min_price) as min_val, MAX(max_price) as max_val')->first();
        $minPrice = (float)($priceStats->min_val ?? 0);
        $maxPrice = (float)($priceStats->max_val ?? 1000);

        // Vendor Facets with Counts
        $vendors = [];
        if ($settings->enable_vendor) {
            $vendors = (clone $baseQuery)
                ->whereNotNull('vendor')
                ->where('vendor', '!=', '')
                ->select('vendor', DB::raw('count(*) as count'))
                ->groupBy('vendor')
                ->orderBy('count', 'desc')
                ->limit(50)
                ->get()
                ->map(fn($v) => [
                    'label' => $v->vendor,
                    'value' => $v->vendor,
                    'count' => (int)$v->count,
                ])
                ->values()
                ->toArray();
        }

        // Product Type Facets with Counts
        $types = [];
        if ($settings->enable_type) {
            $types = (clone $baseQuery)
                ->whereNotNull('product_type')
                ->where('product_type', '!=', '')
                ->select('product_type', DB::raw('count(*) as count'))
                ->groupBy('product_type')
                ->orderBy('count', 'desc')
                ->limit(50)
                ->get()
                ->map(fn($t) => [
                    'label' => $t->product_type,
                    'value' => $t->product_type,
                    'count' => (int)$t->count,
                ])
                ->values()
                ->toArray();
        }

        // Availability Counts
        $availability = [];
        if ($settings->enable_availability) {
            $inStockCount = (clone $baseQuery)->where('is_available', true)->count();
            $outStockCount = (clone $baseQuery)->where('is_available', false)->count();

            $availability = [
                ['label' => 'In Stock', 'value' => 'in_stock', 'count' => $inStockCount],
                ['label' => 'Out of Stock', 'value' => 'out_of_stock', 'count' => $outStockCount],
            ];
        }

        // Tag Facets with Counts
        $tags = [];
        if ($settings->enable_tags) {
            $tags = $this->aggregateTags($baseQuery);
        }

        $totalProducts = (clone $baseQuery)->count();

        return [
            'shop' => $shop->shop_domain,
            'collection_handle' => $collectionHandle ?? 'all',
            'total_products' => $totalProducts,
            'currency_symbol' => $shop->currency_symbol ?: '$',
            'facets' => [
                'price' => [
                    'enabled' => $settings->enable_price,
                    'min' => floor($minPrice),
                    'max' => ceil($maxPrice),
                ],
                'vendors' => [
                    'enabled' => $settings->enable_vendor,
                    'items' => $vendors,
                ],
                'types' => [
                    'enabled' => $settings->enable_type,
                    'items' => $types,
                ],
                'availability' => [
                    'enabled' => $settings->enable_availability,
                    'items' => $availability,
                ],
                'tags' => [
                    'enabled' => $settings->enable_tags,
                    'items' => $tags,
                ],
            ],
            'settings' => [
                'layout' => $settings->filter_layout,
                'accent_color' => $settings->theme_accent_color,
                'per_page' => $settings->per_page,
            ],
        ];
    }

    /**
     * Get filtered, sorted and paginated products.
     */
    public function getFilteredProducts(
        Shop $shop,
        ?string $collectionHandle = null,
        array $filters = [],
        string $sortBy = 'featured',
        int $page = 1,
        int $perPage = 24
    ): array {
        $query = $this->buildBaseQuery($shop, $collectionHandle);

        // Apply Price Filter
        if (isset($filters['price_min']) && is_numeric($filters['price_min'])) {
            $query->where('max_price', '>=', (float)$filters['price_min']);
        }
        if (isset($filters['price_max']) && is_numeric($filters['price_max'])) {
            $query->where('min_price', '<=', (float)$filters['price_max']);
        }

        // Apply Vendor Filter
        if (!empty($filters['vendors']) && is_array($filters['vendors'])) {
            $query->whereIn('vendor', $filters['vendors']);
        }

        // Apply Product Type Filter
        if (!empty($filters['types']) && is_array($filters['types'])) {
            $query->whereIn('product_type', $filters['types']);
        }

        // Apply Availability Filter
        if (!empty($filters['availability'])) {
            if ($filters['availability'] === 'in_stock') {
                $query->where('is_available', true);
            } elseif ($filters['availability'] === 'out_of_stock') {
                $query->where('is_available', false);
            }
        }

        // Apply Tag Filter
        if (!empty($filters['tags']) && is_array($filters['tags'])) {
            $query->where(function (Builder $q) use ($filters) {
                foreach ($filters['tags'] as $tag) {
                    $q->where(function ($sub) use ($tag) {
                        $sub->where('tags', 'LIKE', '%"' . addcslashes($tag, '%_') . '"%')
                            ->orWhere('tags', 'LIKE', '%' . addcslashes($tag, '%_') . '%');
                    });
                }
            });
        }

        // Apply Sorting
        switch ($sortBy) {
            case 'price-ascending':
            case 'price-asc':
                $query->orderBy('min_price', 'asc');
                break;
            case 'price-descending':
            case 'price-desc':
                $query->orderBy('min_price', 'desc');
                break;
            case 'title-ascending':
            case 'title-asc':
                $query->orderBy('title', 'asc');
                break;
            case 'title-descending':
            case 'title-desc':
                $query->orderBy('title', 'desc');
                break;
            case 'created-descending':
            case 'created-desc':
                $query->orderBy('published_at', 'desc');
                break;
            default:
                $query->orderBy('id', 'desc');
                break;
        }

        $paginator = $query->with('variants')->paginate($perPage, ['*'], 'page', $page);

        $currencySymbol = $shop->currency_symbol ?: '$';

        $items = collect($paginator->items())->map(function (Product $product) use ($currencySymbol) {
            $isOnSale = $product->compare_at_price && $product->compare_at_price > $product->min_price;

            return [
                'id' => $product->shopify_id,
                'title' => $product->title,
                'handle' => $product->handle,
                'url' => "/products/{$product->handle}",
                'vendor' => $product->vendor,
                'product_type' => $product->product_type,
                'featured_image' => $product->featured_image ?: 'https://placehold.co/400x400?text=No+Image',
                'min_price' => (float)$product->min_price,
                'max_price' => (float)$product->max_price,
                'compare_at_price' => $product->compare_at_price ? (float)$product->compare_at_price : null,
                'price_formatted' => $this->formatMoney($product->min_price, $currencySymbol),
                'compare_at_price_formatted' => $product->compare_at_price ? $this->formatMoney($product->compare_at_price, $currencySymbol) : null,
                'is_on_sale' => $isOnSale,
                'is_available' => (bool)$product->is_available,
                'tags' => $product->tags ?: [],
                'variants_count' => $product->variants->count(),
            ];
        });

        return [
            'products' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'applied_filters' => $filters,
            'sort_by' => $sortBy,
        ];
    }

    /**
     * Build base query scoped to shop and collection handle.
     */
    protected function buildBaseQuery(Shop $shop, ?string $collectionHandle): Builder
    {
        $query = Product::query()
            ->where('shop_id', $shop->id)
            ->where('status', 'active');

        if ($collectionHandle && $collectionHandle !== 'all') {
            $collection = Collection::where('shop_id', $shop->id)
                ->where('handle', $collectionHandle)
                ->first();

            if ($collection) {
                $query->whereHas('collections', function (Builder $q) use ($collection) {
                    $q->where('collections.id', $collection->id);
                });
            }
        }

        return $query;
    }

    /**
     * Aggregate tags from products.
     */
    protected function aggregateTags(Builder $query): array
    {
        // Sample recent/active products to extract top tags
        $productTags = (clone $query)
            ->whereNotNull('tags')
            ->select('tags')
            ->limit(2000)
            ->get();

        $tagCounts = [];
        foreach ($productTags as $item) {
            $tags = $item->tags;
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') {
                        $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($tagCounts);

        $results = [];
        $i = 0;
        foreach ($tagCounts as $tag => $count) {
            if ($i++ >= 40) {
                break;
            }
            $results[] = [
                'label' => $tag,
                'value' => $tag,
                'count' => $count,
            ];
        }

        return $results;
    }

    /**
     * Format money string.
     */
    protected function formatMoney(float|string $amount, string $symbol = '$'): string
    {
        return $symbol . number_format((float)$amount, 2);
    }
}
