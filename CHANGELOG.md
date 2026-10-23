# Changelog — BlueFairy_CoreBugs

## Unreleased

### Fixed

- **Bug #3 — `Magento\Framework\App\PageCache\Kernel::process()` saved no-store headers to the
  full-page cache instead of the public ones a page was generated with.** The method calls
  `$response->setNoCacheHeaders()` and only afterwards snapshots `$response->getHeaders()` for
  the entry handed to `fullPageCache->save()` — both calls read/write the same response object, so
  every saved entry carried the no-store headers meant only for that one live, cache-miss
  response. A later cache HIT replayed that snapshot verbatim: `X-Magento-Cache-Debug: HIT` with
  `Cache-Control: no-store` regardless of theme or configuration. Confirmed stock Magento 2.4.9
  core behaviour — reproduced on a from-scratch install with zero BlueFairy modules, under both
  the Hyvä default theme and stock Luma. Filed upstream as
  [magento/magento2#41643](https://github.com/magento/magento2/issues/41643).

  `BlueFairy\CoreBugs\App\PageCache\Kernel` reimplements `process()`, moving
  `setNoCacheHeaders()` to run after the cache save instead of before it; every other side effect
  keeps its original order. See README.md — Bug #3.
