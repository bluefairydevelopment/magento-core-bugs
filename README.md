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

*Add new bugs below this line following the same format.*
