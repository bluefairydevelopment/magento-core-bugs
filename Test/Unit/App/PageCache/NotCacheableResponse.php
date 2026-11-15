<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @brief       Test double type: an HTTP response that declares itself not cacheable
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\Test\Unit\App\PageCache;

use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Exists only so a test can mock a response that is both an HTTP response and NotCacheableInterface.
 */
abstract class NotCacheableResponse extends HttpResponse implements NotCacheableInterface
{
}
