<?php

declare(strict_types=1);

namespace Preorder;

defined('ABSPATH') || exit;

/**
 * Reads the per-product pre-order configuration stored as product meta.
 *
 * Meta keys:
 *  - _preorder_enabled "yes" | "" (checkbox)
 *
 * The accessor is defensive: a missing product or meta value yields a sane
 * default rather than an error.
 */
final class ProductMeta
{
    public const META_ENABLED      = '_preorder_enabled';
    public const META_RELEASE_DATE = '_preorder_release_date';

    /**
     * Whether the product (or variation) is flagged as a pre-order.
     *
     * A variation follows its parent product. Add-ons filter
     * `preorder/is_preorder` to extend or override the result.
     */
    public function isPreorder(\WC_Product $product): bool
    {
        $enabled = $this->resolveEnabled($product);

        /**
         * Filter whether a product or variation is treated as a pre-order.
         *
         * @param bool        $enabled Resolved pre-order flag before filtering.
         * @param \WC_Product $product The product or variation being checked.
         */
        return (bool) apply_filters('preorder/is_preorder', $enabled, $product);
    }

    /**
     * Whether this product or any of its variations is flagged as a pre-order.
     *
     * Used on variable parent products to decide whether storefront assets
     * should load before a variation is selected.
     */
    public function hasPreorderInTree(\WC_Product $product): bool
    {
        if ($this->isPreorder($product)) {
            return true;
        }

        if (! $product->is_type('variable')) {
            return false;
        }

        // A variation follows its parent, and the parent has just answered no,
        // so without an add-on filtering the answer there is nothing left to ask.
        if (! has_filter('preorder/is_preorder')) {
            return false;
        }

        // An add-on is filtering the answer, so it has to be asked about every
        // variation, which means every variation has to be loaded.
        foreach ($product->get_children() as $childId) {
            $child = wc_get_product($childId);
            if ($child instanceof \WC_Product && $this->isPreorder($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expected release date for a pre-order product or variation (Y-m-d), or empty.
     *
     * A variation follows its parent product. Add-ons filter
     * `preorder/release_date` to extend or override the result.
     */
    public function releaseDate(\WC_Product $product): string
    {
        $date = $this->resolveReleaseDate($product);

        /**
         * Filter the expected release date for a pre-order product or variation.
         *
         * @param string      $date    Release date in Y-m-d format, or empty.
         * @param \WC_Product $product The product or variation being checked.
         */
        return (string) apply_filters('preorder/release_date', $date, $product);
    }

    private function resolveReleaseDate(\WC_Product $product): string
    {
        $product = $this->owner($product);

        return null === $product ? '' : $this->sanitizeDate((string) $product->get_meta(self::META_RELEASE_DATE));
    }

    private function sanitizeDate(string $raw): string
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        return $date instanceof \DateTimeImmutable ? $date->format('Y-m-d') : '';
    }

    private function resolveEnabled(\WC_Product $product): bool
    {
        $product = $this->owner($product);

        return null !== $product && 'yes' === $product->get_meta(self::META_ENABLED);
    }

    /**
     * The product whose settings apply: a variation answers with its parent.
     */
    private function owner(\WC_Product $product): ?\WC_Product
    {
        if (! $product->is_type('variation')) {
            return $product;
        }

        $parent = wc_get_product($product->get_parent_id());

        return $parent instanceof \WC_Product ? $parent : null;
    }
}
