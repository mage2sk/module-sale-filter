<!-- SEO Meta -->
<!--
  Title: Magento 2 Sale Filter Extension: "On Sale" Layered Navigation Filter | Hyva + Luma | Panth Infotech
  Description: Panth Sale Filter adds an indexer-driven "On Sale" layered navigation filter to Magento 2 category and search pages. Real-time counts, dated-discount aware, respects catalog rules, special prices, tier prices, and customer-group pricing. Parent aggregation for configurable, grouped, and bundle products. Compatible with Magento 2.4.4 to 2.4.8, PHP 8.1 to 8.4, Elasticsearch/OpenSearch, Hyva and Luma themes. Built by Top Rated Plus Magento developer Kishan Savaliya.
  Keywords: magento 2 on sale filter, magento 2 sale filter extension, magento 2 layered navigation sale filter, magento 2 shop by sale, magento 2 discount filter, magento 2 catalog rule filter, magento 2 special price filter, hyva sale filter, luma sale filter, magento 2 on sale layered navigation
  Author: Kishan Savaliya (Panth Infotech)
  Canonical: https://kishansavaliya.com/magento-2-sale-filter.html
-->

# Magento 2 Sale Filter Extension: "On Sale" Layered Navigation Filter (Hyva + Luma)

[![Magento 2.4.4 - 2.4.8](https://img.shields.io/badge/Magento-2.4.4%20--%202.4.8-orange?logo=magento&logoColor=white)](https://magento.com)
[![PHP 8.1 - 8.4](https://img.shields.io/badge/PHP-8.1%20--%208.4-blue?logo=php&logoColor=white)](https://php.net)
[![Hyva + Luma](https://img.shields.io/badge/Themes-Hyva%20%2B%20Luma-14b8a6)](https://www.hyva.io)
[![Live Demo & Details](https://img.shields.io/badge/Live%20Demo%20%26%20Details-magento--2--sale--filter-0D9488?style=flat)](https://kishansavaliya.com/magento-2-sale-filter.html)
[![Packagist](https://img.shields.io/badge/Packagist-mage2kishan%2Fmodule--sale--filter-orange?logo=packagist&logoColor=white)](https://packagist.org/packages/mage2kishan/module-sale-filter)
[![Upwork Top Rated Plus](https://img.shields.io/badge/Upwork-Top%20Rated%20Plus-14a800?logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/~016dd1767321100e21)
[![Website](https://img.shields.io/badge/Website-kishansavaliya.com-0D9488)](https://kishansavaliya.com)

> **Let shoppers narrow any category or search result to discounted products in one click.** Panth Sale Filter adds an "On Sale" option to Magento 2 layered navigation, backed by a dedicated indexer that reads catalog rules, special prices, tier prices, and customer-group pricing. Works on Luma out of the box; a companion module adds the Hyva Alpine.js template.

**Product page:** [kishansavaliya.com/magento-2-sale-filter.html](https://kishansavaliya.com/magento-2-sale-filter.html)

---

## Quick Answer

**What is Panth Sale Filter?** It is a Magento 2 layered navigation extension that adds a "Sale Status" filter to category and search pages, so shoppers can show only discounted products with one click.

**What does it add to my store?**

- A **"Shop By Sale Status"** option in layered navigation on category AND search-result pages.
- **Real-time counts** next to each option (`On Sale (12)`) that match the grid total after clicking.
- An **indexer-driven discount engine** that checks catalog price rules, special prices, tier prices, and customer-group pricing.
- **Parent aggregation** so a configurable, grouped, or bundle product shows as on sale as soon as any eligible child is discounted.
- An **admin index grid** at System → Panth Infotech → Sale Filter → Index Grid for debugging individual products.

**Which themes are supported?** **Luma** natively. For **Hyva**, install the free companion package `mage2kishan/module-sale-filter-hyva`.

**What does it need?** Magento 2.4.4 to 2.4.8, PHP 8.1 to 8.4, Elasticsearch or OpenSearch, and the free `mage2kishan/module-core` package.

---

## Need Custom Magento 2 Development?

> **Get a free quote for your project in 24 hours** for custom modules, Hyva themes, performance work, M1 to M2 migrations, and Adobe Commerce Cloud.

<p align="center">
  <a href="https://kishansavaliya.com/get-quote">
    <img src="https://img.shields.io/badge/Get%20a%20Free%20Quote%20%E2%86%92-Reply%20within%2024%20hours-DC2626?style=for-the-badge" alt="Get a Free Quote" />
  </a>
</p>

<table>
<tr>
<td width="50%" align="center">

### Kishan Savaliya
**Top Rated Plus on Upwork**

[![Hire on Upwork](https://img.shields.io/badge/Hire%20on%20Upwork-Top%20Rated%20Plus-14a800?style=for-the-badge&logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/~016dd1767321100e21)

100% Job Success - 10+ Years Magento Experience
Adobe Certified - Hyva Specialist

</td>
<td width="50%" align="center">

### Panth Infotech Agency
**Magento Development Team**

[![Visit Agency](https://img.shields.io/badge/Visit%20Agency-Panth%20Infotech-14a800?style=for-the-badge&logo=upwork&logoColor=white)](https://www.upwork.com/agencies/1881421506131960778/)

Custom Modules - Theme Design - Migrations
Performance - SEO - Adobe Commerce Cloud

</td>
</tr>
</table>

**Visit our website:** [kishansavaliya.com](https://kishansavaliya.com) &nbsp;|&nbsp; **Get a quote:** [kishansavaliya.com/get-quote](https://kishansavaliya.com/get-quote)

---

## Table of Contents

- [Who Is It For](#who-is-it-for)
- [Key Features](#key-features)
- [Screenshots](#screenshots)
- [Compatibility](#compatibility)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [Admin Index Grid](#admin-index-grid)
- [Indexing](#indexing)
- [Caching](#caching)
- [Dated Discounts](#dated-discounts)
- [URL Parameters](#url-parameters)
- [CLI Reference](#cli-reference)
- [FAQ](#faq)
- [Support](#support)
- [About Panth Infotech](#about-panth-infotech)
- [Quick Links](#quick-links)

---

## Who Is It For

- **Stores that run promotions** where a large share of the catalog is temporarily discounted and shoppers want to browse only the sale items.
- **Fashion, electronics, and home stores** where customers arrive specifically to shop the sale and expect a filter to find it quickly.
- **Multi-group B2B merchants** where Wholesale and Retailer groups see different prices, so "on sale" means something different per group.
- **Hyva and Luma storefronts** that want an accurate, indexed filter instead of a slow attribute query across a large catalog.
- **Merchants who need reliable counts** next to filter options, not approximate or stale totals.

---

## Key Features

### Storefront Filter
- **"Shop By Sale Status"** option in layered navigation on category pages and search-result pages.
- **"On Sale" and "Regular Price" options** with configurable labels per store view.
- **Real-time counts** (`On Sale (12)`) scoped to the current category and visibility, never a store-wide tally.
- **Cross-filter accurate** counts that shift when Brand, Color, Size, Price range, or other filters are already active.
- **Stock-aware counts** that respect the "Display Out of Stock Products" setting.
- **"Now Shopping by" chip** with a one-click clear, integrated with Magento's active-filter UI.
- **Pagination-safe** so the filter state carries across `?p=2`, `?product_list_limit=24`, and sort changes.

### Discount Detection
- **Catalog price rules** including all operators (by percent, by fixed, to percent, to fixed) and dated windows.
- **Per-product special prices** with `special_from_date` and `special_to_date` awareness.
- **Tier prices** that fall below the regular price.
- **Customer-group-specific pricing** covering NOT LOGGED IN, General, Wholesale, Retailer, and any custom group.
- **Parent aggregation** for configurable, grouped, and bundle products based on any eligible child.
- **All product types** including simple, configurable, grouped, bundle, virtual, and downloadable.

### Indexer
- **Dedicated indexer** `panth_salefilter_product` shown in System → Tools → Index Management.
- **MView subscriptions** on product price, special price date, catalog rule price, and product relation tables for automatic updates.
- **Update by Schedule** (default, cron-driven, recommended for production) and **Update on Save** (synchronous, good for staging).
- **Flat index table** `panth_salefilter_product_index` keyed by `(entity_id, customer_group_id, website_id)` for fast JOIN lookups.
- **Dated discounts flip automatically** at the minute they start or end via Magento's `catalogrule_apply_all` nightly cron.

### Admin and Operations
- **Admin index grid** at Panth Infotech → Sale Filter → Index Grid showing product id, SKU, type, website, customer group, regular price, special price, is-on-sale, rule price, discount %, active rules, and match source.
- **CLI helpers** `bin/magento panth_salefilter:reindex` and `panth_salefilter:status`.
- **Cache invalidation** across all configured cache frontends, including split Redis setups.
- **Per-customer-group FPC keying** so each shopper group sees its own correct cached count.

### Quality
- **MEQP-compliant** code with no third-party PHP dependencies.
- **Declarative schema** via `db_schema.xml`.
- **Constructor dependency injection only** throughout.
- **Translation ready** with every label using Magento's `__()` function.

---

## Screenshots

### Storefront (Luma)

| Sidebar filter | Filter applied - On Sale | Filter applied - Regular |
|:---:|:---:|:---:|
| ![Luma sidebar](docs/images/luma-sidebar.png) | ![On Sale active](docs/images/luma-active-on-sale.png) | ![Regular active](docs/images/luma-active-regular.png) |

### Storefront (Hyva)

![Hyva sidebar](docs/images/hyva-sidebar.png)

### Admin - live demo

![Admin configuration demo](docs/images/admin-config-demo.gif)

### Admin - configuration, grid, indexer

| Configuration | Sale Filter Index grid | Indexer registration |
|:---:|:---:|:---:|
| ![Admin configuration](docs/images/admin-configuration.png) | ![Sale Filter Index](docs/images/admin-index-grid.png) | ![Indexer list](docs/images/admin-index-management.png) |

---

## Compatibility

| Requirement | Versions Supported |
|---|---|
| Magento Open Source | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8 |
| Adobe Commerce | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8 |
| Adobe Commerce Cloud | 2.4.4 to 2.4.8 |
| PHP | 8.1.x, 8.2.x, 8.3.x, 8.4.x |
| MySQL | 8.0+ |
| MariaDB | 10.4+ |
| Search Engine | Elasticsearch 7/8, OpenSearch 1/2 |
| Hyva Theme | 1.3+ (via companion module `mage2kishan/module-sale-filter-hyva`) |
| Luma Theme | Native support |
| Required Dependency | `mage2kishan/module-core` (free) |

---

## Installation

### Composer Installation (Recommended)

```bash
composer require mage2kishan/module-sale-filter
bin/magento module:enable Panth_Core Panth_SaleFilter
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento indexer:reindex panth_salefilter_product
bin/magento cache:flush
```

### Hyva Storefronts

Also install the companion module, which ships the Alpine.js template and Tailwind styles:

```bash
composer require mage2kishan/module-sale-filter-hyva
bin/magento module:enable Panth_SaleFilterHyva
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual Installation via ZIP

1. Download the latest release from [Packagist](https://packagist.org/packages/mage2kishan/module-sale-filter) or from the [product page](https://kishansavaliya.com/magento-2-sale-filter.html).
2. Extract it to `app/code/Panth/SaleFilter/` in your Magento install.
3. Make sure `Panth_Core` is installed too (required dependency).
4. Run the commands above starting from `bin/magento module:enable`.

### Verify Installation

```bash
bin/magento module:status Panth_SaleFilter
# Expected: Module is enabled

bin/magento indexer:status panth_salefilter_product
# Expected: Ready / Update by Schedule / idle
```

After install, open:
```
Admin → Stores → Configuration → Panth Extensions → Sale Filter
```

---

## Configuration

Go to **Stores → Configuration → Panth Extensions → Sale Filter**.

All fields are store-scoped, so you can set different labels per store view for multi-locale installs.

| Setting | Group | Default | Description |
|---|---|---|---|
| Enabled | General | Yes | Master switch. Off means the filter disappears from layered navigation and `?sale_filter=` URL params become no-ops. |
| Filter Title | General | Sale Status | Heading shown above the filter options in the sidebar. |
| Option Label - On Sale | General | On Sale | Label for the discounted option. |
| Show "Not On Sale" Option | General | No | When on, a second option appears so shoppers can also filter to regular-price products. |
| Option Label - Not On Sale | General | Regular Price | Label for the regular-price option. Shown only when the above is enabled. |
| Show Product Count | General | Yes | Toggles the `(12)` counter next to each option. |
| Include Special Prices | General | Yes | When off, the indexer ignores per-product special prices. |
| Include Catalog Rules | General | Yes | When off, the indexer ignores catalog price rules. |
| Filter Position | General | 100 | Sort order in layered navigation. Lower values appear higher in the sidebar. |

---

## How It Works

1. The indexer `Panth\SaleFilter\Model\Indexer\ProductIndexer` walks every product × customer group × website combination, resolves the effective on-sale flag from catalog rules, special prices, and tier prices, and writes a row to `panth_salefilter_product_index`.
2. MView subscriptions on price, date, rule-price, and product-relation tables keep the index current without a full reindex on every change.
3. A layered-navigation plugin runs `afterGetProductCollection` on both `Catalog\Model\Layer\Category` and `Catalog\Model\Layer\Search`. It intersects the index with the current category and visibility, then replaces Magento's `SearchResultApplier` with a filter-aware version so paging slices the filtered list rather than intersecting the full ES result after the fact.
4. A `getSize()` plugin returns the pre-computed post-filter count so the toolbar pager shows the correct total.

---

## Admin Index Grid

Open **Panth Infotech → Sale Filter → Index Grid** in the admin.

![Sale Filter Index grid](docs/images/admin-index-grid.png)

The grid is a UI component over `panth_salefilter_product_index` with filters, column chooser, and CSV export. It is useful for checking the computed sale state of a specific product or verifying that a rule change propagated correctly.

| Column | Description |
|---|---|
| Product ID | Magento entity id |
| SKU | Product SKU |
| Type | simple, configurable, grouped, bundle, virtual, or downloadable |
| Website | Website code and id |
| Customer Group | Group name and id |
| Regular Price | Pre-discount price |
| Special Price | Configured special price if any |
| On Sale | Yes or No, the effective sale state |
| Updated At | Last indexer write timestamp |
| Rule Price | Final price after the best applicable catalog rule |
| Discount % | Relative discount versus regular price |
| Active Catalog Rules | Comma-separated list of matching rule names |
| Match Source | Catalog Rule, Special Price, or Both |

---

## Indexing

`panth_salefilter_product` appears in **System → Tools → Index Management**.

![Index Management](docs/images/admin-index-management.png)

Two modes are available:

- **Update by Schedule** (default) - MView changelog captures changed product ids, and Magento's `indexer_update_all_views` cron processes them approximately every minute. Recommended for production.
- **Update on Save** - observers fire `reindexRow` inline on every relevant save. More DB writes during bulk imports, but the storefront reflects changes immediately. Good for staging.

Switch modes via the Index Management grid (Actions → Update Mode) or via CLI:

```bash
bin/magento indexer:set-mode schedule panth_salefilter_product
bin/magento indexer:set-mode realtime panth_salefilter_product
```

---

## Caching

The "On Sale" counts differ per customer group. If the cache ignores the group, one visitor's render is served to everyone and the counts are wrong. The module solves this with two Magento hooks.

`Block/LayeredNavigation/FilterRenderer.php` implements `IdentityInterface` and overrides two methods:

- **`getCacheKeyInfo()`** mixes in store id, website id, customer group, currency, current category id, and the active `sale_filter` URL parameter. Each combination gets its own cached HTML fragment.
- **`getIdentities()`** returns `cat_p` and `panth_salefilter` cache tags. When a product or catalog rule changes, only entries with these tags are evicted.

Customer group is read from `HttpContext`, not `CustomerSession`. The session is wiped before cacheable blocks render, so `HttpContext` is the only reliable source.

On Magento 2.4.7 and later, a plugin restores cookie-aware FPC keying (`X-Magento-Vary`) for both `Identifier` and `IdentifierForSave`, so each customer group gets its own FPC entry at both load and save time.

---

## Dated Discounts

Dated discounts flip at the minute they start or end. Three triggers keep the index fresh:

1. **Immediate** - when you save a product or catalog rule, the observer calls `reindexRow` (Update on Save mode) or writes to the MView changelog (Update by Schedule mode).
2. **MView + Magento cron** - the `indexer_update_all_views` cron (every 1 minute) processes any pending changelog entries.
3. **Daily catalog rule refresh** - Magento's built-in `catalogrule_apply_all` job runs at midnight every day and rebuilds `catalogrule_product_price` for the current date. Rules whose window starts today come online; rules whose window ended yesterday drop out. The MView subscription detects these writes and queues the affected products. Within about one minute after midnight, the index reflects the new state.

If `bin/magento cron:run` is not scheduled in your OS crontab, timed discounts will not flip automatically. This is a baseline Magento requirement.

To force an immediate refresh:

```bash
bin/magento catalog:rule:apply-all
bin/magento indexer:reindex panth_salefilter_product
bin/magento cache:flush
```

---

## URL Parameters

- `?sale_filter=1` shows on-sale products only.
- `?sale_filter=0` shows regular-price products only (respected only when "Show Not On Sale Option" is enabled).

Both parameters are preserved across pagination (`&p=2`), per-page override (`&product_list_limit=24`), and sort changes.

---

## CLI Reference

```bash
# Full reindex
bin/magento indexer:reindex panth_salefilter_product
# or the dedicated command with friendlier output
bin/magento panth_salefilter:reindex

# Health check
bin/magento panth_salefilter:status
bin/magento indexer:status panth_salefilter_product

# Switch modes
bin/magento indexer:set-mode schedule panth_salefilter_product
bin/magento indexer:set-mode realtime panth_salefilter_product

# Refresh catalog rules as if it is midnight
bin/magento catalog:rule:apply-all

# Clear caches tagged by this module
bin/magento cache:clean panth_salefilter
```

---

## FAQ

### Does this work with Hyva themes?
Yes, with the companion module. Install `mage2kishan/module-sale-filter-hyva` alongside the base module and it ships the Alpine.js plus Tailwind template for Hyva. Luma works out of the box without the companion.

### Does it respect dated catalog rules?
Yes. Dated rules flip automatically at the minute they start or end via Magento's built-in `catalogrule_apply_all` nightly cron plus the `indexer_update_all_views` cron that runs every minute. See the [Dated Discounts](#dated-discounts) section for the full timeline.

### Will a configurable product show as On Sale if only one child is discounted?
Yes. Parent aggregation is on by default. A configurable, grouped, or bundle product is marked on sale as soon as any eligible child is discounted.

### Does it work with Elasticsearch and OpenSearch?
Yes. Tested on Elasticsearch 7/8 and OpenSearch 1/2. The module ships a custom `SearchResultApplier` that intersects the ES result set with the on-sale id list before paging, so page 1 shows the first N filtered products rather than the first N ES results that happen to be on sale.

### Does it work on search-result pages, not just category pages?
Yes. The filter is wired to both `Catalog\Model\Layer\Category` and `Catalog\Model\Layer\Search`.

### What is the difference between "Include Special Prices" and "Include Catalog Rules"?
Turning one off tells the indexer to ignore that discount source. Useful when a merchant only wants the filter to react to explicit special prices and not the dynamic catalog rules that affect the whole catalog.

### Does it support custom customer groups?
Yes. The indexer walks every group in the `customer_group` table, so stores with custom groups all get accurate per-group discount state.

### Does it support multi-store and multi-website setups?
Yes. The index table is keyed by `(entity_id, customer_group_id, website_id)` so you get per-website discount states, and all labels are store-scoped.

### Can I uninstall it cleanly?
Yes. Run `composer remove mage2kishan/module-sale-filter` then `bin/magento setup:upgrade`. The index table and MView changelog are dropped automatically via declarative schema.

---

## Support

| Channel | Contact |
|---|---|
| Product Page | [kishansavaliya.com/magento-2-sale-filter.html](https://kishansavaliya.com/magento-2-sale-filter.html) |
| Email | kishansavaliyakb@gmail.com |
| Website | [kishansavaliya.com](https://kishansavaliya.com) |
| WhatsApp | +91 84012 70422 |
| GitHub Issues | [github.com/mage2sk/module-sale-filter/issues](https://github.com/mage2sk/module-sale-filter/issues) |
| Upwork (Top Rated Plus) | [Hire Kishan Savaliya](https://www.upwork.com/freelancers/~016dd1767321100e21) |
| Upwork Agency | [Panth Infotech](https://www.upwork.com/agencies/1881421506131960778/) |

Response time: 1-2 business days.

### Need Custom Magento Development?

Looking for **custom Magento module development**, **Hyva theme work**, **store migrations**, or **performance tuning**? Get a free quote in 24 hours:

<p align="center">
  <a href="https://kishansavaliya.com/get-quote">
    <img src="https://img.shields.io/badge/%F0%9F%92%AC%20Get%20a%20Free%20Quote-kishansavaliya.com%2Fget--quote-DC2626?style=for-the-badge" alt="Get a Free Quote" />
  </a>
</p>

<p align="center">
  <a href="https://www.upwork.com/freelancers/~016dd1767321100e21">
    <img src="https://img.shields.io/badge/Hire%20Kishan-Top%20Rated%20Plus-14a800?style=for-the-badge&logo=upwork&logoColor=white" alt="Hire on Upwork" />
  </a>
  &nbsp;&nbsp;
  <a href="https://www.upwork.com/agencies/1881421506131960778/">
    <img src="https://img.shields.io/badge/Visit-Panth%20Infotech%20Agency-14a800?style=for-the-badge&logo=upwork&logoColor=white" alt="Visit Agency" />
  </a>
  &nbsp;&nbsp;
  <a href="https://kishansavaliya.com/magento-2-sale-filter.html">
    <img src="https://img.shields.io/badge/View%20Product%20Page-magento--2--sale--filter-0D9488?style=for-the-badge" alt="View Product Page" />
  </a>
</p>

---

## About Panth Infotech

Built and maintained by **Kishan Savaliya** ([kishansavaliya.com](https://kishansavaliya.com)), a **Top Rated Plus** Magento developer on Upwork with 10+ years of eCommerce experience.

**Panth Infotech** is a Magento 2 development agency that builds high-quality, security-focused extensions and themes for both Hyva and Luma storefronts. The extension suite covers SEO, performance, checkout, product presentation, customer engagement, and store management, with each module built to MEQP standards and tested across Magento 2.4.4 to 2.4.8.

Browse the full extension catalog on our [Magento extensions page](https://kishansavaliya.com/magento-extensions.html) or on [Packagist](https://packagist.org/packages/mage2kishan/).

---

## Quick Links

| Resource | Link |
|---|---|
| **Product Page** | [magento-2-sale-filter.html](https://kishansavaliya.com/magento-2-sale-filter.html) |
| **Packagist** | [mage2kishan/module-sale-filter](https://packagist.org/packages/mage2kishan/module-sale-filter) |
| **GitHub** | [mage2sk/module-sale-filter](https://github.com/mage2sk/module-sale-filter) |
| **Website** | [kishansavaliya.com](https://kishansavaliya.com) |
| **Free Quote** | [kishansavaliya.com/get-quote](https://kishansavaliya.com/get-quote) |
| **Upwork (Top Rated Plus)** | [Hire Kishan Savaliya](https://www.upwork.com/freelancers/~016dd1767321100e21) |
| **Upwork Agency** | [Panth Infotech](https://www.upwork.com/agencies/1881421506131960778/) |
| **Email** | kishansavaliyakb@gmail.com |
| **WhatsApp** | +91 84012 70422 |

---

<p align="center">
  <strong>Ready to help shoppers find your sale items faster?</strong><br/>
  <a href="https://kishansavaliya.com/magento-2-sale-filter.html">
    <img src="https://img.shields.io/badge/%F0%9F%9A%80%20See%20Sale%20Filter%20%E2%86%92-Product%20Page%20%26%20Demo-DC2626?style=for-the-badge" alt="See Sale Filter" />
  </a>
</p>

---

**SEO Keywords:** magento 2 on sale filter, magento 2 sale filter extension, magento 2 layered navigation sale filter, magento 2 shop by sale, magento 2 discount filter, magento 2 catalog rule filter, magento 2 special price filter, magento 2 sale status layered navigation, magento 2 on sale layered navigation, hyva sale filter, hyva layered navigation filter, luma sale filter, magento 2 elasticsearch sale filter, magento 2 opensearch sale filter, magento 2 configurable on sale, magento 2 parent child sale aggregation, magento 2 dated discount indexer, magento 2 sale filter indexer, magento 2 customer group pricing filter, magento 2 category filter extension, panth sale filter, panth infotech, mage2kishan, mage2sk, kishan savaliya magento, top rated plus upwork magento, hire magento developer, magento 2.4.8 module, php 8.4 magento module, meqp compliant magento module, custom magento development
