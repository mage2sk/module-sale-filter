# Panth Sale Filter — "On Sale" Layered Navigation Filter for Magento 2

[![Magento 2.4.4 - 2.4.8](https://img.shields.io/badge/Magento-2.4.4%20--%202.4.8-orange?logo=magento&logoColor=white)](https://magento.com)
[![PHP 8.1 - 8.4](https://img.shields.io/badge/PHP-8.1%20--%208.4-blue?logo=php&logoColor=white)](https://php.net)
[![Hyva Compatible](https://img.shields.io/badge/Hyva-Compatible-14b8a6)](https://hyva.io)
[![Luma Compatible](https://img.shields.io/badge/Luma-Compatible-orange)]()

Adds an **"On Sale"** filter to Magento 2 layered navigation on category + search result pages. Backed by a custom indexer so the match check is a single indexed JOIN at request time — not an N+1 price recalculation that would kill performance on large catalogs and break pagination.

## What counts as "on sale"

A product is on sale for a given `(customer_group, website, date)` scope when **any** of:

1. An active **catalog price rule** is applied in `catalogrule_product_price` for that scope + today.
2. A **special price** is set where `special_price < price` and today falls in `special_from_date / special_to_date`.
3. It's a composite product (**configurable / grouped / bundle**) and at least one enabled child / associated product satisfies (1) or (2).

All product types work: simple, configurable, grouped, bundle, virtual, downloadable.

## Architecture

```
catalog_product, catalog_product_entity_decimal,          ┐
catalog_product_entity_datetime, catalogrule_product_price ├─► ProductIndexer ─► panth_salefilter_product_index
catalog_product_super_link, catalog_product_bundle_selection ┘       (SQL)              (indexed table)

FilterList::getFilters() ─── afterGetFilters plugin ─► appends SaleFilter

PLP request ─► SaleFilter::apply() ─► adds INNER JOIN on index table → respects pagination → cacheable
```

## Installation

```bash
composer require mage2kishan/module-sale-filter
bin/magento module:enable Panth_Core Panth_SaleFilter
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
bin/magento indexer:reindex panth_salefilter_product
```

For Hyvä stores, also:

```bash
composer require mage2kishan/module-sale-filter-hyva
bin/magento module:enable Panth_SaleFilterHyva
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

**Stores → Configuration → Catalog → Sale Filter**

| Setting | Default | Purpose |
|---|---|---|
| Enabled | Yes | Master switch — when No, the filter does not appear |
| Filter Label | `On Sale` | Translatable label shown in the sidebar |
| Show Product Count | Yes | Appends `(42)` next to the label |
| Include Special Prices | Yes | Consider `special_price < price` matches |
| Include Catalog Rules | Yes | Consider `catalogrule_product_price` matches |
| Filter Position | 100 | Sort order alongside other filters |

Every setting is store-view scoped.

## Currency

All price comparisons are done in the **base currency** of the website. The indexer compares raw decimal values from `catalog_product_entity_decimal` without currency conversion.

## CLI

```bash
# Full reindex
bin/magento panth:salefilter:reindex
bin/magento panth:salefilter:reindex --force   # invalidate first, then rebuild

# Status — rows per (website, customer group)
bin/magento panth:salefilter:status
```

## Cache Invalidation Cheatsheet

```bash
# After module code changes:
bin/magento cache:flush

# After catalog rule / special price admin edit:
bin/magento indexer:reindex panth_salefilter_product
bin/magento cache:clean block_html full_page

# After module config changes:
bin/magento cache:clean config block_html full_page
```

## How the Cache Layers Interact

- **Block cache** — the filter block's `getCacheKeyInfo()` varies by store, website, customer group, currency, category, and current filter state. Different shoppers + different carts = different cache entries.
- **Full Page Cache (Varnish / FPC)** — the URL query param `?sale_filter=1` naturally creates a separate FPC entry. The filter block contributes the `panth_salefilter` + product + catalog-rule tags via `getIdentities()` so Magento purges the right entries when upstream data changes.
- **Indexer cache tag** — after every reindex the module cleans the `panth_salefilter` tag, chaining through to block_html + full_page.
- **Upstream events** — catalog rule apply + product save/delete hooks invalidate the indexer view so the next cron / CLI run rebuilds.

## Manual QA Checklist

### Installation

- [ ] `composer require` resolves
- [ ] `bin/magento setup:upgrade` runs clean
- [ ] `bin/magento module:status Panth_SaleFilter` reports enabled
- [ ] `bin/magento indexer:info` lists `panth_salefilter_product`
- [ ] `bin/magento indexer:reindex panth_salefilter_product` runs with exit code 0
- [ ] `bin/magento setup:di:compile` passes with zero errors

### Frontend — Luma

- [ ] Filter appears on category pages in the layered nav sidebar
- [ ] Label matches admin config
- [ ] Clicking the filter reloads the PLP with only discounted products
- [ ] Product count matches the filtered result count
- [ ] Pagination works correctly with the filter applied (page 1, 2, 3)
- [ ] Sort (price asc/desc, name) works with the filter applied
- [ ] Combining with other filters (price, color, brand) works
- [ ] Filter hides when disabled in admin config
- [ ] Works for guest shoppers (NOT LOGGED IN)
- [ ] Works across different logged-in customer groups
- [ ] Works for simple, configurable, grouped, bundle, virtual, downloadable products
- [ ] "Clear filter" breadcrumb link removes the filter

### Frontend — Hyvä

- [ ] All Luma checks repeat on the Hyvä theme
- [ ] Alpine.js expand/collapse works if custom template is active
- [ ] No KnockoutJS / RequireJS errors in the browser console

### Cache verification

- [ ] Apply filter twice on the same page → second response is FPC HIT
- [ ] Log in as a different customer group → fresh page, not stale from the other group
- [ ] Admin updates a catalog rule → save → category page shows new data within one FPC cycle
- [ ] Admin changes `special_price` → run indexer → product appears/disappears correctly
- [ ] Switch stores via the store switcher → filter shows store-scoped data
- [ ] Multi-website: product on website A with a scoped rule does NOT appear on website B

### Admin

- [ ] `Stores → Configuration → Catalog → Sale Filter` exists
- [ ] All six fields save
- [ ] ACL — a restricted admin role without `Panth_SaleFilter::config` cannot edit

### Performance targets

- [ ] Full reindex of 10 000 products: < 30 s
- [ ] Filter query on a 5 000-product category: < 500 ms
- [ ] Filtered PLP backend render: < 2 s
- [ ] Indexer memory usage: < 256 MB

### Error handling

- [ ] `var/log/exception.log` clean after a filtered PLP request
- [ ] `var/log/system.log` clean
- [ ] Zero deprecation warnings
- [ ] Fresh install with an empty index table: filter renders gracefully with count 0 and doesn't crash the page

## Edge cases handled

| Scenario | Behaviour |
|---|---|
| Product has no price set | Skipped (no crash) |
| Catalog rule matches zero products | Indexer writes zero rows for that scope — filter item hides |
| Configurable with all children disabled | Parent not on sale |
| Configurable with out-of-stock discounted children | Parent still on sale (inventory is a separate concern) |
| Multi-currency | Compared in base currency (documented) |
| Tier pricing | Not in scope for v1.0.0 |
| Product deleted | `ON DELETE CASCADE` cleans up index rows automatically |
| Filter applied, no products match | Standard "No products found" page |
| Category with layered nav disabled | Filter safely no-ops |
| Search result page | Filter appears — same plugin covers `Magento\Catalog\Model\Layer\Search\FilterList` |
| Fresh install, no reindex yet | Filter skips the join (empty index) and the page still renders |
| Huge catalog (100k+ products) | Indexer writes in batches of 1 000 per scope |

## Support

[Kishan Savaliya on Upwork](https://www.upwork.com/freelancers/~016dd1767321100e21) | [Panth Infotech](https://www.upwork.com/agencies/1881421506131960778/) | [kishansavaliya.com](https://kishansavaliya.com)
