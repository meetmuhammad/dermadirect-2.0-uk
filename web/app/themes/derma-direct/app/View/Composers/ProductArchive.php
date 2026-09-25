<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;
use App\Helper\Helper;

class ProductArchive extends Composer
{
    protected static $views = [
        'woocommerce.archive-product',
    ];

    /**
     * Category slugs to exclude from the sidebar category filter entirely.
     */
    private const EXCLUDED_CATEGORY_SLUGS = [
        'courses',
        'gift-vouchers',
        'sale',
        'bundles',
        'prodermis%ef%b8%8f',
    ];

    /**
     * Custom display order (by slug) for the sidebar category filter.
     * Categories not listed here fall back to the end, in their default order.
     */
    private const CATEGORY_ORDER = [
        'dermal-fillers',
        'skin-boosters',
        'polynucleotides',
        'plla',
        'fat-dissolve',
        'microneedling',
        'pdo-threads',
        'skincare',
        'needles-cannulas',
        'consumables',
        'ppe',
        'accessories',
        'jalupro-range',
        'restylane-range',
    ];

    /**
     * Custom display order (by slug) for the sidebar brand filter.
     * Brands not listed here fall back to the end, in their default order.
     */
    private const BRAND_ORDER = [
        'nexfill',
        'dermaren',
        'lumi-eyes',
        'lumifil',
        'prodermis',
        'aurora',
        'caragen',
        'croma',
        'dermastir',
        'illuma',
        'infini',
        'jalupro',
        'juvederm',
        'juvelook',
        'medisco',
        'plinest',
        'profhilo',
        'promoitalia',
        'rejuran',
        'remed',
        'restylane',
        'revitrane',
        'teoxane',
        'vivacy',
    ];

    public function override(): array
    {
        $this->enqueueAssets();

        $products_data = $this->getProducts();
        $price_range = $this->getPriceRange();
        $filters_to_show = $this->getFiltersToShow();
        $filter_configs = $this->getFilterConfigs();
        

        return [
            'categories_data' => $this->getCategoriesWithCount(),
            'brands_data' => $products_data['brands'],
            'treatment_areas_data' => (array) $products_data['treatment_areas'],
            'ingredients_data' => (array) $products_data['ingredients'],
            'product_gauge_data' => (array) $products_data['product_gauge'],
            'product_length_data' => (array) $products_data['product_length'],
            'product_type_data' => (array) $products_data['product_type'],
            'product_protocols_data' => (array) $products_data['product_protocols'],
            'products' => $products_data['products'],
            'per_page' => $products_data['per_page'],
            'products_found' => $products_data['products_found'],

            'price_min' => $price_range['min'],
            'price_max' => $price_range['max'],
            'filters_to_show' => $filters_to_show,
            'filter_configs' => $filter_configs,

            'active_brands' => $this->getActiveBrandSlugs(),
        ];
    }

    private function getActiveBrandSlugs(): array
    {
        if (is_tax('product_brand')) {
            $brand_term = get_queried_object();

            if ($brand_term && !is_wp_error($brand_term)) {
                return [$brand_term->slug];
            }
        }

        return [];
    }

    /**
     * Get all WooCommerce product categories with contextual product counts
     *
     * @param int $parent_category_id If > 0, counts will be contextual to this category
     * @return array
     */
    private function getCategoriesWithCount(): array
    {
        // Get category ID if on category page for contextual category counts
        $category_id = 0;
        if (is_product_category()) {
            $category_term = get_queried_object();
            if ($category_term && !is_wp_error($category_term)) {
                $category_id = $category_term->term_id;
            }
        }

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || empty($terms)) return [];

        // Remove categories excluded from this filter
        $terms = array_filter($terms, function ($term) {
            return !in_array($term->slug, self::EXCLUDED_CATEGORY_SLUGS, true);
        });

        // Apply custom display order, with the active category (if any) pinned first;
        // unlisted categories fall back to the end. Done server-side so the active
        // category is already at the top on first render, with no client-side reorder.
        $order_lookup = array_flip(self::CATEGORY_ORDER);
        usort($terms, function ($a, $b) use ($order_lookup, $category_id) {
            $a_active = $category_id > 0 && $a->term_id === $category_id;
            $b_active = $category_id > 0 && $b->term_id === $category_id;
            if ($a_active !== $b_active) {
                return $a_active ? -1 : 1;
            }

            $pos_a = $order_lookup[$a->slug] ?? PHP_INT_MAX;
            $pos_b = $order_lookup[$b->slug] ?? PHP_INT_MAX;
            return $pos_a <=> $pos_b;
        });

        $categories = [];

        foreach ($terms as $term) {
            // Shop page: count only published products in this category
            $count = $this->getProductCountInCategories([$term->term_id]);

            $categories[] = [
                'id'            => $term->term_id,
                'name'          => $term->name,
                'slug'          => $term->slug,
                'count'         => $count,
                'description'   => $term->description,
                'permalink'     => get_term_link($term),
                'thumbnail_id'  => get_term_meta($term->term_id, 'thumbnail_id', true),
                'thumbnail_url' => wp_get_attachment_url(get_term_meta($term->term_id, 'thumbnail_id', true)),
                'is_active'     => $category_id > 0 && $term->term_id === $category_id,
            ];
        }

        return $categories;
    }

    /**
     * Get product count for multiple categories (AND relationship)
     * Only counts PUBLISHED products
     *
     * @param array $category_ids
     * @return int
     */
    private function getProductCountInCategories(array $category_ids): int
    {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
        ];

        if (count($category_ids) === 1) {
            // Single category
            $args['tax_query'] = [
                'relation' => 'AND',
                [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => [$category_ids[0]],
                    'include_children' => true,
                ],
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-catalog',
                    'operator' => 'NOT IN',
                ],
            ];
        } else {
            // Multiple categories (AND relationship)
            $args['tax_query'] = [
                'relation' => 'AND',
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-catalog',
                    'operator' => 'NOT IN',
                ],
            ];

            foreach ($category_ids as $cat_id) {
                $args['tax_query'][] = [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => [$cat_id],
                    'include_children' => false,
                ];
            }
        }

        $query = new \WP_Query($args);
        return $query->found_posts;
    }

    /**
     * Get products with filtering logic
     *
     * @param array $params Query parameters
     * @return array
     */
    public static function queryProducts(array $params = []): array
    {
        $defaults = [
            'paged'            => 1,
            'per_page'         => 12,
            'sort'             => '',
            'categories'       => [],
            'brands'           => [],
            'treatment_areas'  => [],
            'ingredients'      => [],
            'product_gauge'    => [],
            'product_length'   => [],
            'product_type'     => [],
            'product_protocols' => [],
            'category_id'      => 0,
            'price_min'        => 0,
            'price_max'        => 0,
        ];

        $params = wp_parse_args($params, $defaults);

        $paged    = absint($params['paged']);
        $per_page = absint($params['per_page']);

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'post_status'    => 'publish',
        ];

        // Apply sorting
        $sort_tax_query = [];
        $args = self::applySorting($args, $params['sort'], $sort_tax_query);

        // Build tax query
        $tax_query = [];

        foreach ($sort_tax_query as $clause) {
            $tax_query[] = $clause;
        }

        // Exclude hidden products (WooCommerce product visibility)
        $tax_query[] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => 'exclude-from-catalog',
            'operator' => 'NOT IN',
        ];

        // Category context (for category pages)
        if (!empty($params['category_id'])) {
            $tax_query[] = [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => [$params['category_id']],
                'include_children' => true,
            ];
        }

        // Category filter (from filters sidebar)
        if (!empty($params['categories']) && is_array($params['categories'])) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => array_map('sanitize_text_field', $params['categories']),
                'operator' => 'IN',
            ];
        }

        // Brand filter
        if (!empty($params['brands']) && is_array($params['brands'])) {
            $tax_query[] = [
                'taxonomy' => 'product_brand',
                'field'    => 'slug',
                'terms'    => array_map('sanitize_text_field', $params['brands']),
                'operator' => 'IN',
            ];
        }

        // Apply tax query if exists
        if (!empty($tax_query)) {
            $tax_query['relation'] = 'AND';
            $args['tax_query'] = $tax_query;
        }

        // Price filter
        $price_min = absint($params['price_min']);
        $price_max = absint($params['price_max']);

        if ($price_min > 0 || $price_max > 0) {
            $price_meta_query = [];

            if ($price_min > 0) {
                $price_meta_query[] = [
                    'key'     => '_price',
                    'value'   => $price_min,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ];
            }

            if ($price_max > 0) {
                $price_meta_query[] = [
                    'key'     => '_price',
                    'value'   => $price_max,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ];
            }

            if (!empty($price_meta_query)) {
                if (!empty($args['meta_query'])) {
                    // best_selling already set a meta_query — wrap both in an AND
                    $args['meta_query'] = [
                        'relation' => 'AND',
                        $args['meta_query'],        // best_selling EXISTS/NOT EXISTS
                        array_merge(
                            ['relation' => 'AND'],
                            $price_meta_query       // price range clauses
                        ),
                    ];
                } else {
                    $price_meta_query['relation'] = 'AND';
                    $args['meta_query'] = $price_meta_query;
                }
            }
        }

        // Treatment areas filter
        if (!empty($params['treatment_areas']) && is_array($params['treatment_areas'])) {
            $product_ids = self::filterProductsByACF('treatment_areas', $params['treatment_areas'], $args);
            if (!empty($product_ids)) {
                $args['post__in'] = !empty($args['post__in'])
                    ? array_intersect($args['post__in'], $product_ids)
                    : $product_ids;
            } else {
                $args['post__in'] = [0];
            }
        }

        // Ingredients filter
        if (!empty($params['ingredients']) && is_array($params['ingredients'])) {
            $product_ids = self::filterProductsByACF('ingredients', $params['ingredients'], $args);
            if (!empty($product_ids)) {
                $args['post__in'] = !empty($args['post__in'])
                    ? array_intersect($args['post__in'], $product_ids)
                    : $product_ids;
            } else {
                $args['post__in'] = [0];
            }
        }

        // Product gauge filter
        if (!empty($params['product_gauge']) && is_array($params['product_gauge'])) {
            $product_ids = self::filterProductsByACF('product_gauge', $params['product_gauge'], $args);
            if (!empty($product_ids)) {
                $args['post__in'] = !empty($args['post__in'])
                    ? array_intersect($args['post__in'], $product_ids)
                    : $product_ids;
            } else {
                $args['post__in'] = [0];
            }
        }

        // Product length filter
        if (!empty($params['product_length']) && is_array($params['product_length'])) {
            $product_ids = self::filterProductsByACF('product_length', $params['product_length'], $args);
            if (!empty($product_ids)) {
                $args['post__in'] = !empty($args['post__in'])
                    ? array_intersect($args['post__in'], $product_ids)
                    : $product_ids;
            } else {
                $args['post__in'] = [0];
            }
        }

        // Product type filter
        if (!empty($params['product_type']) && is_array($params['product_type'])) {
            $product_ids = self::filterProductsByACF('product_type', $params['product_type'], $args);
            if (!empty($product_ids)) {
                $args['post__in'] = !empty($args['post__in'])
                    ? array_intersect($args['post__in'], $product_ids)
                    : $product_ids;
            } else {
                $args['post__in'] = [0];
            }
        }

        // Product protocols filter
        if (!empty($params['product_protocols']) && is_array($params['product_protocols'])) {
            $product_ids = self::filterProductsByACF('product_protocols', $params['product_protocols'], $args);
            if (!empty($product_ids)) {
                $args['post__in'] = !empty($args['post__in'])
                    ? array_intersect($args['post__in'], $product_ids)
                    : $product_ids;
            } else {
                $args['post__in'] = [0];
            }
        }

        $products = new \WP_Query($args);

        return [
            'products'       => $products,
            'per_page'       => $per_page,
            'products_found' => $products->found_posts,
            'max_pages'      => $products->max_num_pages,
        ];
    }

    /**
     * Apply sorting to query args
     *
     * @param array $args WP_Query arguments
     * @param string $sort Sort type
     * @return array
     */
    private static function applySorting(array $args, string $sort, array &$sort_tax_query = []): array
    {
        switch ($sort) {
            case 'best_selling':
                $args['meta_key'] = 'best_selling_count';
                $args['orderby'] = [
                    'meta_value_num' => 'DESC',
                    'date' => 'DESC',
                ];
                break;

            case 'featured':
                $sort_tax_query[] = [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'featured',
                ];
                break;

            case 'newest':
                $args['orderby'] = 'date';
                $args['order']   = 'DESC';
                break;

            case 'price_asc':
                $args['meta_key'] = '_price';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'ASC';
                break;

            case 'price_desc':
                $args['meta_key'] = '_price';
                $args['orderby']  = 'meta_value_num';
                $args['order']    = 'DESC';
                break;

            case 'name_asc':
                $args['orderby'] = 'title';
                $args['order']   = 'ASC';
                break;

            // Default: keep original safe behavior for page load
            default:
                $args['orderby'] = 'date';
                $args['order']   = 'DESC';
                break;
        }

        return $args;
    }

    /**
     * Filter products by ACF field values (for multi-select fields)
     *
     * @param string $field_name ACF field name
     * @param array $values Values to filter by (slugs)
     * @param array $base_args Base WP_Query args for initial filtering
     * @return array Product IDs
     */
    private static function filterProductsByACF(string $field_name, array $values, array $base_args = []): array
    {
        $query_args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
        ];

        // Inherit tax_query from base args if exists
        if (!empty($base_args['tax_query'])) {
            $query_args['tax_query'] = $base_args['tax_query'];
        }

        // Inherit post__in constraint from base args if exists (for chaining filters)
        if (!empty($base_args['post__in'])) {
            $query_args['post__in'] = $base_args['post__in'];
        }

        $all_product_ids = get_posts($query_args);
        $matched_ids = [];

        foreach ($all_product_ids as $product_id) {
            $field_values = get_field($field_name, $product_id);
            if (!empty($field_values) && is_array($field_values)) {
                // Convert field values to slugs for comparison
                $field_slugs = array_map('sanitize_title', $field_values);

                // Check if any selected value matches
                if (array_intersect($values, $field_slugs)) {
                    $matched_ids[] = $product_id;
                }
            }
        }

        return $matched_ids;
    }

    private function getProducts(): array
    {
        $paged    = get_query_var('paged') ? absint(get_query_var('paged')) : 1;
        $per_page = 12;

        $params = [
            'paged'    => $paged,
            'per_page' => $per_page,
        ];

        // Category context
        if (is_product_category()) {
            $category_term = get_queried_object();
            if ($category_term && !is_wp_error($category_term)) {
                $params['category_id'] = $category_term->term_id;
            }
        }

        // Brand context
        if (is_tax('product_brand')) {
            $brand_term = get_queried_object();
            if ($brand_term && !is_wp_error($brand_term)) {
                $params['brands'] = [$brand_term->slug];
            }
        }

        $params['brands'] = $this->getActiveBrandSlugs();

        $result      = self::queryProducts($params);
        $category_id = $params['category_id'] ?? 0;

        return [
            'products'         => $result['products'],
            'per_page'         => $per_page,
            'products_found'   => $result['products_found'],
            'brands'           => self::sortBrandsByCustomOrder(
                $category_id > 0 ? self::getBrandsForCategory($category_id) : get_terms(['taxonomy' => 'product_brand', 'hide_empty' => true]),
                $this->getActiveBrandSlugs()
            ),
            'treatment_areas'  => self::getACFFieldCounts('treatment_areas', $category_id),
            'ingredients'      => self::getACFFieldCounts('ingredients', $category_id),
            'product_gauge'    => self::getACFFieldCounts('product_gauge', $category_id),
            'product_length'   => self::getACFFieldCounts('product_length', $category_id),
            'product_type'     => self::getACFFieldCounts('product_type', $category_id),
            'product_protocols' => self::getACFFieldCounts('product_protocols', $category_id),
        ];
    }

    /**
     * Sort brand terms into the custom display order, with any active brand
     * (e.g. on a brand archive page) pinned first; unlisted brands fall back to the end.
     *
     * @param mixed $brands Array of WP_Term (or WP_Error)
     * @param array $active_slugs Brand slugs to pin to the top
     * @return array
     */
    private static function sortBrandsByCustomOrder($brands, array $active_slugs = []): array
    {
        if (is_wp_error($brands) || empty($brands)) return [];

        $order_lookup = array_flip(self::BRAND_ORDER);

        usort($brands, function ($a, $b) use ($order_lookup, $active_slugs) {
            $a_active = in_array($a->slug, $active_slugs, true);
            $b_active = in_array($b->slug, $active_slugs, true);
            if ($a_active !== $b_active) {
                return $a_active ? -1 : 1;
            }

            $pos_a = $order_lookup[$a->slug] ?? PHP_INT_MAX;
            $pos_b = $order_lookup[$b->slug] ?? PHP_INT_MAX;
            return $pos_a <=> $pos_b;
        });

        return $brands;
    }

    /**
     * Get brands for a specific category with contextual counts
     *
     * @param int $category_id
     * @return array
     */
    private static function getBrandsForCategory(int $category_id): array
    {
        // Get ALL product IDs in this category
        $product_ids = get_posts([
            'post_type'      => 'product',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'tax_query'      => [
                [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => [$category_id],
                    'include_children' => true,
                ],
            ],
        ]);

        // Get only brands attached to these products
        $brands = get_terms([
            'taxonomy'   => 'product_brand',
            'hide_empty' => true,
            'object_ids' => $product_ids,
        ]);

        // Recalculate counts PER brand PER category
        foreach ($brands as &$brand) {
            $brand->count = count(get_posts([
                'post_type'      => 'product',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'tax_query'      => [
                    'relation' => 'AND',
                    [
                        'taxonomy' => 'product_brand',
                        'field'    => 'term_id',
                        'terms'    => [$brand->term_id],
                    ],
                    [
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => [$category_id],
                        'include_children' => true,
                    ],
                ],
            ]));
        }

        return $brands;
    }

    /**
     * Generic method to get ACF field counts with contextual filtering
     *
     * @param string $field_name ACF field name to count
     * @param int $category_id Optional category ID for contextual filtering
     * @return array
     */
    private static function getACFFieldCounts(string $field_name, int $category_id = 0): array
    {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-catalog',
                    'operator' => 'NOT IN',
                ],
            ],
        ];

        if ($category_id > 0) {
            $args['tax_query'][] = [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => [$category_id],
                'include_children' => true,
            ];
        }

        $product_ids = get_posts($args);
        $field_count = [];

        foreach ($product_ids as $product_id) {
            $values = get_field($field_name, $product_id);
            if (!empty($values) && is_array($values)) {
                foreach ($values as $value) {
                    $field_count[$value] = ($field_count[$value] ?? 0) + 1;
                }
            }
        }

        $result = [];
        foreach ($field_count as $name => $count) {
            $result[] = [
                'name'  => $name,
                'slug'  => sanitize_title($name),
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * Get dynamic price range from all products
     * Min rounded down to nearest 10, Max rounded up to nearest 10
     *
     * @return array
     */
    private function getPriceRange(): array
    {
        // Get category ID if on category page for contextual pricing
        $category_id = 0;
        if (is_product_category()) {
            $category_term = get_queried_object();
            if ($category_term && !is_wp_error($category_term)) {
                $category_id = $category_term->term_id;
            }
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-catalog',
                    'operator' => 'NOT IN',
                ],
            ],
        ];

        // Add category filter if on category page
        if ($category_id > 0) {
            $args['tax_query'][] = [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => [$category_id],
                'include_children' => true,
            ];
        }

        $product_ids = get_posts($args);

        if (empty($product_ids)) {
            return ['min' => 0, 'max' => 1000];
        }

        $prices = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $price = $product->get_price();
                if ($price && is_numeric($price)) {
                    $prices[] = (float) $price;
                }
            }
        }

        if (empty($prices)) {
            return ['min' => 0, 'max' => 1000];
        }

        $min_price = min($prices);
        $max_price = max($prices);

        // Round down to nearest 10
        $min_rounded = floor($min_price / 10) * 10;

        // Round up to nearest 10
        $max_rounded = ceil($max_price / 10) * 10;

        return [
            'min' => (int) $min_rounded,
            'max' => (int) $max_rounded,
        ];
    }

    /**
     * Get filter configurations for dynamic filter rendering
     *
     * @return array
     */
    private function getFilterConfigs(): array
    {
        return [
            [
                'key' => 'brands',
                'data_var' => 'brands_data',
                'title' => 'Brands',
                'css_class' => 'brand',
                'is_object' => true,
            ],
            [
                'key' => 'treatment_areas',
                'data_var' => 'treatment_areas_data',
                'title' => 'Treatment Areas',
                'css_class' => 'treatment-area',
                'is_object' => false,
            ],
            [
                'key' => 'ingredients',
                'data_var' => 'ingredients_data',
                'title' => 'Ingredients',
                'css_class' => 'ingredient',
                'is_object' => false,
            ],
            [
                'key' => 'product_gauge',
                'data_var' => 'product_gauge_data',
                'title' => 'Gauge',
                'css_class' => 'product-gauge',
                'is_object' => false,
            ],
            [
                'key' => 'product_length',
                'data_var' => 'product_length_data',
                'title' => 'Length',
                'css_class' => 'product-length',
                'is_object' => false,
            ],
            [
                'key' => 'product_type',
                'data_var' => 'product_type_data',
                'title' => 'Product Type',
                'css_class' => 'product-type',
                'is_object' => false,
            ],
            [
                'key' => 'product_protocols',
                'data_var' => 'product_protocols_data',
                'title' => 'Protocols',
                'css_class' => 'product-protocols',
                'is_object' => false,
            ],
        ];
    }

    /**
     * Returns all filters for shop page, or category-specific filters for category pages
     *
     * @return array
     */

     private function getFiltersToShow(): array
    {
        // Default: all filters visible (for shop page)
        $all_filters = [
            'brands',
            'treatment_areas',
            'ingredients',
            'product_gauge',
            'product_length',
            'product_type',
            'product_protocols',
        ];

        // If on brand taxonomy page, only show brands filter
        if (is_tax('product_brand')) {
            return ['brands'];
        }

        // If on category page, get ACF field value
        if (is_product_category()) {
            $category_term = get_queried_object();
            if ($category_term && !is_wp_error($category_term)) {
                $filters = get_field('filters_to_show_on_cat_page', 'product_cat_' . $category_term->term_id);

                // If ACF field is set and not empty, use it
                if (!empty($filters) && is_array($filters)) {
                    return $filters;
                }
            }
        }

        // Return all filters for shop page or if ACF field is empty
        return $all_filters;
    }

    private function enqueueAssets(): void
    {
        $script_uri = \Vite::asset('resources/js/product-archive.js');
        $quick_view_script_uri = \Vite::asset('resources/js/quick-view-popup.js');
        wp_enqueue_script('product-archive-page-js', $script_uri, ['woocommerce-common-js'], null, true);
        wp_enqueue_script('quick-view-popup-js', $quick_view_script_uri, ['jquery'], null, true);

        // Get category ID if on category page
        $category_id = 0;
        if (is_product_category()) {
            $category_term = get_queried_object();
            if ($category_term && !is_wp_error($category_term)) {
                $category_id = $category_term->term_id;
            }
        }

        // Get brand slug if on brand taxonomy page
        $brand_slug = '';
        if (is_tax('product_brand')) {
            $brand_term = get_queried_object();
            if ($brand_term && !is_wp_error($brand_term)) {
                $brand_slug = $brand_term->slug;
            }
        }

        wp_localize_script('product-archive-page-js', 'ajax_obj', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('ajax-nonce'),
            'category_id' => $category_id,
            'brand_slug'  => $brand_slug,
        ]);

        wp_localize_script('quick-view-popup-js', 'quick_view_popup_vars', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('ajax-nonce'),
            'close_svg' => file_get_contents(get_theme_file_path('resources/images/quick-view-close.svg')),
        ]);

        $common_script_uri = \Vite::asset('resources/js/woocommerce-common.js');
        wp_enqueue_script('woocommerce-common-js', $common_script_uri, [], null, true);

        $common_style_uri = \Vite::asset('resources/css/woocommerce-common.css');
        wp_enqueue_style('woocommerce-common-style', $common_style_uri, [], null, 'all');
    }
}
