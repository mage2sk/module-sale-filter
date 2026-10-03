# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.4] - 2026-10-03

### Fixed
- `bin/magento panth:salefilter:reindex --force` no longer crashes. The option called a method that does not exist on the indexer; it now invalidates the indexer and then runs the full rebuild.
- Choosing "Not on sale" on a listing without a category scope (outside category and search pages) now lists the enabled, catalog-visible, in-stock products that are not on sale, matching the count shown in the filter, instead of returning no products.
