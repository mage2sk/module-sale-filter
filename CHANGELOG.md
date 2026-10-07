# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.6] - 2026-10-07

### Fixed
- Admin Sale Filter Index grid: filtering by Grid ID or by the Discount % range no longer fails with "UI component could not be rendered". Both computed columns are now filtered in the WHERE clause, so the record count query works with these filters.
