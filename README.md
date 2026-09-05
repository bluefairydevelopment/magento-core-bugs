# BlueFairy/CoreBugs

Houses preferences that fix confirmed bugs in Magento core modules. Each fix is isolated to a single preference class and documented below with full reproduction steps and root cause analysis.

## Installation

### 1. Register the GitHub Repository

From your Magento 2 root directory, add this repository as a Composer VCS source:

```bash
composer config repositories.bluefairy-core-bugs vcs "https://github.com/bluefairydevelopment/magento-core-bugs.git"
```

### 2. Require the Package

```bash
composer require bluefairy/module-core-bugs
```

To install a specific branch, append `dev-[branch-name]`, e.g. `dev-main`.

### 3. Enable and Deploy

Enable the module:

```bash
php bin/magento module:enable BlueFairy_CoreBugs
```

Update the database and schema:

```bash
php bin/magento setup:upgrade
```

Compile and deploy (required in Production mode):

```bash
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
```

Flush the cache:

```bash
php bin/magento cache:flush
```

---

## Bug #1 — Design Config DataProvider ignores table prefix

**Module affected:** `magento/module-theme`
**Class affected:** `Magento\Theme\Ui\Component\Design\Config\DataProvider`
**Method affected:** `getCoreConfigData()` (private)
**Magento versions affected:** 2.4.x (confirmed on 2.4.7, 2.4.8)
**Reported:** 2026-04-01

### Symptom

Navigating to **Admin > Content > Design > Configuration** throws a fatal SQL error:

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table
'<dbname>.core_config_data' doesn't exist, query was:
SELECT `core_config_data`.* FROM `core_config_data`
WHERE (path = 'design/theme/theme_id')
```

Only affects stores with a configured `table_prefix` in `app/etc/env.php`. The page loads correctly on installations with no table prefix.

### Root Cause

`getCoreConfigData()` builds its query using `$connection->getTableName()` where `$connection` is a `\Magento\Framework\DB\Adapter\Pdo\Mysql` instance:

```php
// vendor/magento/module-theme/Ui/Component/Design/Config/DataProvider.php:130
$connection->select()->from($connection->getTableName('core_config_data'))
```

In Magento 2.4.x, `Pdo\Mysql::getTableName()` does **not** apply the table prefix. Its implementation is:

```php
// vendor/magento/framework/DB/Adapter/Pdo/Mysql.php:3611
public function getTableName($tableName)
{
    return ExpressionConverter::shortenEntityName($tableName, 't_');
}
```

This method is designed for generating shortened index and trigger names, not for prefixing table names. It returns the input string unchanged (unless it exceeds the DB identifier length limit).

The **correct** method for obtaining a prefixed table name is `ResourceConnection::getTableName()`, which reads the `table_prefix` value from `app/etc/env.php` and prepends it:

```php
$this->resourceConnection->getTableName('core_config_data')
// returns: mgh0_core_config_data
```

### Why It Worked Before

In Magento 2.3.x and early 2.4.x, `Pdo\Mysql::getTableName()` had a different implementation that did apply the table prefix. At some point during the 2.4.x series the method was refactored to only handle identifier shortening, breaking any code that relied on it for prefix application.

### Fix

`BlueFairy\CoreBugs\Ui\Component\Design\Config\DataProvider` is declared as a DI preference over the core class via `etc/di.xml`.

Because `getCoreConfigData()` is `private` in the parent (it cannot be overridden), the fix fully reimplements `getData()`, substituting the broken `$connection->getTableName()` call with `$this->resourceConnection->getTableName()`.

The grandparent's `getData()` (`\Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider::getData()`) is called directly to avoid duplicating the search/filter infrastructure logic.

### Workaround (no code change)

For environments where deploying the module is not practical, a MySQL view can be created as a temporary workaround:

```sql
CREATE OR REPLACE VIEW core_config_data AS SELECT * FROM mgh0_core_config_data;
```

Replace `mgh0_` with your actual table prefix. This view must be recreated if the database is reset.

---

## Bug #2 — SetLinkStatusObserver crashes on a null order-item id

**Module affected:** `magento/module-downloadable`
**Class affected:** `Magento\Downloadable\Observer\SetLinkStatusObserver`
**Method affected:** `execute()`
**Magento versions affected:** 2.4.9, module-downloadable 100.4.9 (confirmed on PHP 8.4/8.5)
**Reported:** 2026-09-05 ([M2MAP-190](https://bluefairydevelopment.atlassian.net/browse/M2MAP-190))

### Symptom

Placing ANY order that contains a downloadable product item fails. The storefront checkout hangs on the payment step; the underlying REST call returns a generic error that names nothing:

```
POST /rest/default/V1/carts/mine/payment-information -> 400
{"message":"A server error stopped your order from being placed. Please try to place your order again."}
```

The transaction rolls back cleanly — no order is created, no card is charged for a lost order — but no customer can complete a purchase containing a downloadable item.

### Root Cause

`execute()` runs on the `sales_order_save_after` event, during order placement. In its default branch (the one every normal, non-held/non-canceled order takes):

```php
// vendor/magento/module-downloadable/Observer/SetLinkStatusObserver.php:119
$downloadableItemsStatuses[$item->getId()] = $linkStatuses['avail'];
```

`$item->getId()` is `null` at this point in the order-placement lifecycle for this Magento/PHP combination. PHP 8.1+ deprecates using `null` as an array offset, and this Magento instance's registered error handler (`Magento\Framework\App\ErrorHandler`) promotes that deprecation to a fatal `Exception`, which propagates all the way up through `OrderRepository::save()` → `OrderService::place()` → `QuoteManagement::submit()` → the REST controller, surfacing as the generic 400 above. Confirmed directly from `exception.log`:

```
main.CRITICAL: Exception: Deprecated Functionality: Using null as an array
offset is deprecated, use an empty string instead in .../SetLinkStatusObserver.php on line 119
```

The core class already recognizes this exact hazard — its canceled/closed/complete branch guards it:

```php
$itemId = $item->getId();
if ($itemId !== null) {
    $downloadableItemsStatuses[$itemId] = $linkStatuses['expired'];
    // ...
}
```

— but the same guard is missing from the other three branches, including the default one every normal order takes.

### Fix

`BlueFairy\CoreBugs\Observer\Downloadable\SetLinkStatusObserver` is declared as a DI preference over the core class via `etc/di.xml`. Magento resolves event observers through the object manager, so a `<preference>` substitutes the whole class here exactly as it would for any other DI-resolved dependency — no `events.xml` changes needed.

Because `execute()` is one large method with no smaller piece to override cleanly, it is fully reimplemented, applying the same `$itemId !== null` guard the core class already uses in its one defended branch consistently across all of them, rather than patching only the single line that happens to crash today.

### Workaround (no code change)

None practical — this fires on every order placement that includes a downloadable item; there is no admin config to route around it.

---

*Add new bugs below this line following the same format.*
