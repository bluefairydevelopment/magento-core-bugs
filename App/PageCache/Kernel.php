<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @author      bluefairydevelopment.com
 * @email       staff@bluefairydevelopment.com
 * @brief       Fix for Bug #3 — Kernel::process() bakes no-store headers into every saved FPC entry
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\App\PageCache;

use Magento\Framework\App\Http\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\PageCache\Cache;
use Magento\Framework\App\PageCache\IdentifierInterface;
use Magento\Framework\App\PageCache\Kernel as CoreKernel;
use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\CookieDisablerInterface;
use Magento\PageCache\Model\Cache\Type as FullPageCache;

/**
 * Overrides core Kernel to save the PUBLIC Cache-Control headers to the
 * full-page-cache store instead of the no-store ones.
 *
 * The core class's process() calls $response->setNoCacheHeaders() — which
 * overwrites Cache-Control/Pragma/Expires on the live response object — and
 * only afterwards calls getPreparedData($response), which reads
 * $response->getHeaders()->toArray() for the array it hands to
 * fullPageCache->save(). Because both calls operate on the SAME response
 * object, the snapshot saved to the cache store already carries the
 * no-store headers that were only ever meant for this one live,
 * cache-miss response. Every subsequent cache HIT replays that saved
 * snapshot verbatim via buildResponse(), so a page that is correctly
 * marked public/cacheable upstream (Magento\PageCache\Model\Layout\
 * LayoutPlugin::afterGenerateElements() calls setPublicHeaders() earlier
 * in the same request) still serves Cache-Control: no-store forever,
 * even though the save/load cycle itself works and
 * X-Magento-Cache-Debug correctly reports HIT.
 *
 * Confirmed as stock Magento 2.4.9 core behaviour, independent of any
 * custom code or theme: reproduced on a from-scratch 2.4.9 install with
 * zero BlueFairy modules, under both the Hyvä default theme and stock
 * Luma.
 *
 * The core class's constructor dependencies needed here are private on
 * the parent and cannot be reused directly from a subclass, so this
 * class accepts the identical constructor signature and keeps its own
 * copies for its process() override; load() and buildResponse() are
 * untouched and continue to run as inherited from the parent.
 *
 * @see CoreKernel
 * @see https://github.com/magento/magento2/issues/41643
 */
class Kernel extends CoreKernel
{
    /**
     * @var SerializerInterface
     */
    private $bfdSerializer;

    /**
     * @var Context
     */
    private $bfdContext;

    /**
     * @var AppState
     */
    private $bfdState;

    /**
     * @var FullPageCache
     */
    private $bfdFullPageCache;

    /**
     * @var IdentifierInterface
     */
    private $bfdIdentifierForSave;

    /**
     * @var CookieDisablerInterface
     */
    private $bfdCookieDisabler;

    /**
     * @var HttpRequest
     */
    private $bfdRequest;

    /**
     * @param Cache $cache
     * @param IdentifierInterface $identifier
     * @param HttpRequest $request
     * @param Context|null $context
     * @param \Magento\Framework\App\Http\ContextFactory|null $contextFactory
     * @param \Magento\Framework\App\Response\HttpFactory|null $httpFactory
     * @param SerializerInterface|null $serializer
     * @param AppState|null $state
     * @param FullPageCache|null $fullPageCache
     * @param IdentifierInterface|null $identifierForSave
     * @param CookieDisablerInterface|null $cookieDisabler
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Cache $cache,
        IdentifierInterface $identifier,
        HttpRequest $request,
        ?Context $context = null,
        ?\Magento\Framework\App\Http\ContextFactory $contextFactory = null,
        ?\Magento\Framework\App\Response\HttpFactory $httpFactory = null,
        ?SerializerInterface $serializer = null,
        ?AppState $state = null,
        ?FullPageCache $fullPageCache = null,
        ?IdentifierInterface $identifierForSave = null,
        ?CookieDisablerInterface $cookieDisabler = null
    ) {
        parent::__construct(
            $cache,
            $identifier,
            $request,
            $context,
            $contextFactory,
            $httpFactory,
            $serializer,
            $state,
            $fullPageCache,
            $identifierForSave,
            $cookieDisabler
        );

        $objectManager = ObjectManager::getInstance();
        $this->bfdRequest = $request;
        $this->bfdContext = $context ?? $objectManager->get(Context::class);
        $this->bfdSerializer = $serializer ?? $objectManager->get(SerializerInterface::class);
        $this->bfdState = $state ?? $objectManager->get(AppState::class);
        $this->bfdFullPageCache = $fullPageCache ?? $objectManager->get(FullPageCache::class);
        $this->bfdIdentifierForSave = $identifierForSave ?? $objectManager->get(IdentifierInterface::class);
        $this->bfdCookieDisabler = $cookieDisabler ?? $objectManager->get(CookieDisablerInterface::class);
    }

    /**
     * Modify and cache application response.
     *
     * Reimplemented from the core class with ONE change: the data snapshot
     * handed to fullPageCache->save() is captured BEFORE
     * $response->setNoCacheHeaders() runs, not after, so the saved entry
     * keeps the public Cache-Control it had when LayoutPlugin marked the
     * page cacheable. setNoCacheHeaders() still runs, in the same place
     * relative to every other side effect, so the live (cache-miss)
     * response sent for THIS request is unchanged — it is still no-store,
     * exactly as the core class intends. Only what gets written to the
     * cache store for the NEXT request differs.
     *
     * @param HttpResponse $response
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function process(HttpResponse $response)
    {
        $cacheControlHeader = $response->getHeader('Cache-Control');
        if ($cacheControlHeader
            && preg_match('/public.*s-maxage=(\d+)/', $cacheControlHeader->getFieldValue(), $matches)
        ) {
            $maxAge = (int) $matches[1];

            if (($response->getHttpResponseCode() == 200 || $response->getHttpResponseCode() == 404)
                && !$response instanceof NotCacheableInterface
                && ($this->bfdRequest->isGet() || $this->bfdRequest->isHead())
            ) {
                $tagsHeader = $response->getHeader('X-Magento-Tags');
                $tags = $tagsHeader ? explode(',', $tagsHeader->getFieldValue() ?? '') : [];

                $response->clearHeader('Set-Cookie');
                if ($this->bfdState->getMode() != AppState::MODE_DEVELOPER) {
                    $response->clearHeader('X-Magento-Tags');
                }
                $this->bfdCookieDisabler->setCookiesDisabled(true);

                // THE FIX: snapshot the still-public headers now, before
                // setNoCacheHeaders() below overwrites Cache-Control on
                // $response. Upstream takes this snapshot after that call,
                // which is what bakes no-store into every saved entry.
                $preparedData = $this->getBfdPreparedData($response);

                $this->bfdFullPageCache->save(
                    $this->bfdSerializer->serialize($preparedData),
                    $this->bfdIdentifierForSave->getValue(),
                    $tags,
                    $maxAge
                );
            }

            $response->setNoCacheHeaders();
        }
    }

    /**
     * Get prepared data for storage in the cache.
     *
     * Same shape as the core class's private getPreparedData() — kept as a
     * separate method under a non-colliding name because the parent's
     * version is private and not inherited.
     *
     * @param HttpResponse $response
     * @return array
     */
    private function getBfdPreparedData(HttpResponse $response): array
    {
        return [
            'content' => $response->getContent(),
            'status_code' => $response->getStatusCode(),
            'headers' => $response->getHeaders()->toArray(),
            'context' => $this->bfdContext->toArray()
        ];
    }
}
