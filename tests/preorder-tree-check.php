<?php

/**
 * A variation follows its parent product, and asking "does anything in this
 * product tree pre-order" costs no product object per variation.
 *
 * - A variation of a pre-order parent is a pre-order with the parent's release
 *   date. Pre-order meta stored on the variation itself is ignored.
 * - With no add-on filtering `preorder/is_preorder`, the parent's answer is the
 *   tree's answer: no variation is loaded and no variation meta is read.
 * - An add-on filtering `preorder/is_preorder` is still shown every variation.
 *
 * Run: php tests/preorder-tree-check.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);

/** @var array<int, WC_Product> $preorder_test_products Stubs by post id. */
$preorder_test_products = [];

/** @var array<int, array<string, string>> $preorder_test_meta Post meta by post id. */
$preorder_test_meta = [];

/** @var int $preorder_test_hydrations wc_get_product() calls. */
$preorder_test_hydrations = 0;

/** @var int $preorder_test_primes update_meta_cache() calls. */
$preorder_test_primes = 0;

/** @var callable|null $preorder_test_filter Stands in for an add-on. */
$preorder_test_filter = null;

// phpcs:disable
function get_post_meta(int $postId, string $key, bool $single = false)
{
    global $preorder_test_meta;
    return $preorder_test_meta[$postId][$key] ?? '';
}

function update_meta_cache(string $type, array $ids)
{
    global $preorder_test_primes;
    ++$preorder_test_primes;
    return [];
}

function has_filter(string $hook, $callback = false): bool
{
    global $preorder_test_filter;
    return 'preorder/is_preorder' === $hook && null !== $preorder_test_filter;
}

function apply_filters(string $hook, $value, ...$args)
{
    global $preorder_test_filter;

    if ('preorder/is_preorder' === $hook && null !== $preorder_test_filter) {
        return ($preorder_test_filter)($value, ...$args);
    }

    return $value;
}

function wc_get_product($id = null)
{
    global $preorder_test_hydrations, $preorder_test_products;
    ++$preorder_test_hydrations;

    return $preorder_test_products[(int) $id] ?? false;
}

class WC_Product
{
    /**
     * @param array<int, int>       $children
     * @param array<string, string> $meta
     */
    public function __construct(
        private int $id,
        private string $type,
        private array $children = [],
        private array $meta = [],
        private int $parentId = 0,
    ) {
    }

    public function get_id(): int { return $this->id; }
    public function is_type($type): bool { return $this->type === $type; }
    public function meta_exists(string $key): bool { return array_key_exists($key, $this->meta); }
    public function get_meta(string $key, bool $single = true) { return $this->meta[$key] ?? ''; }
    public function get_parent_id(): int { return $this->parentId; }

    /** @return array<int, int> */
    public function get_children(): array { return $this->children; }
}
// phpcs:enable

require __DIR__ . '/../src/ProductMeta.php';

$meta = new \Preorder\ProductMeta();

/**
 * Register a variable parent and its variations, as both post meta (what the
 * cheap path reads) and product stubs (what the filtered path loads).
 *
 * @param array<int, array<string, string>> $variations Meta by variation id.
 * @param array<string, string>             $parentMeta
 */
function preorder_test_tree_of(int $parentId, array $variations, array $parentMeta = []): WC_Product
{
    global $preorder_test_products, $preorder_test_meta;

    $parent = new WC_Product($parentId, 'variable', array_keys($variations), $parentMeta);

    $preorder_test_products[$parentId] = $parent;
    $preorder_test_meta[$parentId]     = $parentMeta;

    foreach ($variations as $variationId => $variationMeta) {
        $preorder_test_products[$variationId] = new WC_Product(
            $variationId,
            'variation',
            [],
            $variationMeta,
            $parentId,
        );
        $preorder_test_meta[$variationId] = $variationMeta;
    }

    return $parent;
}

/**
 * @return array{0: bool, 1: int, 2: int} answer, hydrations, meta-cache primes
 */
function preorder_test_tree(\Preorder\ProductMeta $meta, WC_Product $product): array
{
    global $preorder_test_hydrations, $preorder_test_primes;
    $preorder_test_hydrations = 0;
    $preorder_test_primes     = 0;

    $answer = $meta->hasPreorderInTree($product);

    return [$answer, $preorder_test_hydrations, $preorder_test_primes];
}

$failures = [];

// Pre-order meta on a variation is ignored: the parent said no, so the tree
// says no, and nothing is loaded or primed to find that out.
$flagged = preorder_test_tree_of(10, [
    11 => [],
    12 => ['_preorder_enabled' => 'yes', '_preorder_release_date' => '2030-01-01'],
    13 => ['_preorder_enabled' => ''],
]);
[$answer, $hydrations, $primes] = preorder_test_tree($meta, $flagged);
if (false !== $answer) {
    $failures[] = 'variation meta made the tree a pre-order';
}
if (0 !== $hydrations || 0 !== $primes) {
    $failures[] = sprintf('a tree with no pre-order cost %d load(s) and %d prime(s), expected 0', $hydrations, $primes);
}
if ($meta->isPreorder($preorder_test_products[12])) {
    $failures[] = 'a variation flagged on its own became a pre-order';
}

// The parent's flag makes the tree, and every variation, a pre-order.
$parentFlagged = preorder_test_tree_of(30, [
    31 => [],
    32 => ['_preorder_enabled' => '', '_preorder_release_date' => '2031-02-02'],
], ['_preorder_enabled' => 'yes', '_preorder_release_date' => '2030-06-15']);
[$answer, $hydrations, $primes] = preorder_test_tree($meta, $parentFlagged);
if (true !== $answer || 0 !== $hydrations || 0 !== $primes) {
    $failures[] = 'a flagged parent no longer answers on its own';
}
foreach ([31, 32] as $variationId) {
    $variation = $preorder_test_products[$variationId];
    if (! $meta->isPreorder($variation)) {
        $failures[] = sprintf('variation %d of a pre-order parent is not a pre-order', $variationId);
    }
    if ('2030-06-15' !== $meta->releaseDate($variation)) {
        $failures[] = sprintf('variation %d does not carry the parent release date', $variationId);
    }
}

// A variable product with no variations yet.
$empty = preorder_test_tree_of(40, []);
[$answer, $hydrations, $primes] = preorder_test_tree($meta, $empty);
if (false !== $answer || 0 !== $hydrations) {
    $failures[] = 'a variable product with no variations was not answered for free';
}

// An add-on filtering the answer must still be asked about every variation,
// which means the variations have to be loaded for it.
$preorder_test_filter = static function (bool $enabled, $product): bool {
    return $enabled || 12 === $product->get_id();
};
[$answer, $hydrations, $primes] = preorder_test_tree($meta, $flagged);
if (true !== $answer) {
    $failures[] = 'an add-on flagging a variation was ignored';
}
if (0 === $hydrations) {
    $failures[] = 'an add-on was never shown a variation';
}
$preorder_test_filter = null;

if ([] !== $failures) {
    fwrite(STDERR, "preorder-tree-check: FAIL\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  ' . $failure . "\n");
    }
    exit(1);
}

echo "preorder-tree-check: OK (4 cases, variations follow the parent)\n";
