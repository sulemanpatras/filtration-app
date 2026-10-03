<?php

namespace App\Services\Shopify;

class ProductQueries
{
    private const PRODUCT_FIELDS = '
        id
        handle
        title
        vendor
        productType
        tags
        status
        publishedAt
        createdAt
        featuredMedia { preview { image { url altText } } }
    ';

    /** Bulk operation query: every product with variants and collection membership. */
    public static function bulk(): string
    {
        return '{
          products {
            edges {
              node {
                '.self::PRODUCT_FIELDS.'
                variants {
                  edges { node { id price compareAtPrice availableForSale selectedOptions { name value } } }
                }
                collections {
                  edges { node { id handle title } }
                }
              }
            }
          }
        }';
    }

    /** Single product query used when a product webhook arrives. */
    public static function single(): string
    {
        return 'query Product($id: ID!) {
          product(id: $id) {
            '.self::PRODUCT_FIELDS.'
            variants(first: 250) {
              nodes { id price compareAtPrice availableForSale selectedOptions { name value } }
            }
            collections(first: 250) {
              nodes { id handle title }
            }
          }
        }';
    }
}
