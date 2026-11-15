<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @brief       Tests for Bug #3 — the full-page-cache entry keeps the public headers
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\Test\Unit\App\PageCache;

use BlueFairy\CoreBugs\App\PageCache\Kernel;
use Laminas\Http\Header\GenericHeader;
use Laminas\Http\Headers;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\PageCache\Cache;
use Magento\Framework\App\PageCache\IdentifierInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State as AppState;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\CookieDisablerInterface;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueFairy\CoreBugs\App\PageCache\Kernel
 */
#[AllowMockObjectsWithoutExpectations]
class KernelTest extends TestCase
{
    private const PUBLIC_CC = 'max-age=86400, public, s-maxage=86400';
    private const NO_STORE_CC = 'no-store, no-cache, must-revalidate, max-age=0';

    /** @var array<int, array{0: string, 1: string, 2: array, 3: int}> */
    private array $saved = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var string[] */
    private array $cleared = [];

    private bool $noCacheCalled = false;

    private bool $isGet = true;

    private bool $isHead = false;

    private string $mode = AppState::MODE_PRODUCTION;

    /** @var bool[] */
    private array $cookiesDisabled = [];

    private ?object $previousObjectManager = null;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, $this->previousObjectManager);
    }

    /**
     * @return array<string, object>
     */
    private function collaborators(): array
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('isGet')->willReturnCallback(fn (): bool => $this->isGet);
        $request->method('isHead')->willReturnCallback(fn (): bool => $this->isHead);

        $state = $this->createStub(AppState::class);
        $state->method('getMode')->willReturnCallback(fn (): string => $this->mode);

        $fullPageCache = $this->createStub(FullPageCache::class);
        $fullPageCache->method('save')->willReturnCallback(
            function ($data, $identifier, $tags = [], $lifeTime = null): bool {
                $this->saved[] = [$data, $identifier, $tags, $lifeTime];

                return true;
            }
        );

        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(fn ($data): string => (string) json_encode($data));

        $identifierForSave = $this->createStub(IdentifierInterface::class);
        $identifierForSave->method('getValue')->willReturn('page-key');

        $cookieDisabler = $this->createStub(CookieDisablerInterface::class);
        $cookieDisabler->method('setCookiesDisabled')->willReturnCallback(
            function (bool $disabled): void {
                $this->cookiesDisabled[] = $disabled;
            }
        );

        $context = $this->createStub(Context::class);
        $context->method('toArray')->willReturn(['data' => ['store' => 'default'], 'default' => []]);

        return [
            'request' => $request,
            'context' => $context,
            'serializer' => $serializer,
            'state' => $state,
            'fullPageCache' => $fullPageCache,
            'identifierForSave' => $identifierForSave,
            'cookieDisabler' => $cookieDisabler,
        ];
    }

    /**
     * The two factories are generated classes; the core constructor asks the object manager for them.
     *
     * @param array<string, object> $byClass
     */
    private function objectManager(array $byClass = []): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $type) => $byClass[$type] ?? new \stdClass());
        ObjectManager::setInstance($objectManager);
    }

    private function kernel(): Kernel
    {
        $c = $this->collaborators();
        $this->objectManager();

        return new Kernel(
            $this->createStub(Cache::class),
            $this->createStub(IdentifierInterface::class),
            $c['request'],
            $c['context'],
            null,
            null,
            $c['serializer'],
            $c['state'],
            $c['fullPageCache'],
            $c['identifierForSave'],
            $c['cookieDisabler']
        );
    }

    /**
     * A response whose headers behave like Magento's: setNoCacheHeaders() really replaces Cache-Control.
     *
     * @param array<string, string> $headers
     * @param class-string<HttpResponse> $class
     * @return HttpResponse&MockObject
     */
    private function response(array $headers, int $code = 200, string $class = HttpResponse::class): HttpResponse
    {
        $this->headers = $headers;
        $response = $this->createMock($class);
        $response->method('getHeader')->willReturnCallback(
            fn (string $name) => isset($this->headers[$name]) ? new GenericHeader($name, $this->headers[$name]) : false
        );
        $response->method('getHttpResponseCode')->willReturn($code);
        $response->method('getStatusCode')->willReturn($code);
        $response->method('getContent')->willReturn('<html>page</html>');
        $response->method('clearHeader')->willReturnCallback(function (string $name) use ($response) {
            $this->cleared[] = $name;
            unset($this->headers[$name]);

            return $response;
        });
        $response->method('setNoCacheHeaders')->willReturnCallback(function () use ($response) {
            $this->noCacheCalled = true;
            $this->headers['Cache-Control'] = self::NO_STORE_CC;

            return $response;
        });
        $response->method('getHeaders')->willReturnCallback(
            fn (): Headers => (new Headers())->addHeaders($this->headers)
        );

        return $response;
    }

    private function savedData(int $index = 0): array
    {
        return json_decode($this->saved[$index][0], true);
    }

    public function testTheSavedEntryKeepsThePublicCacheControlWhileTheLiveResponseIsNoStore(): void
    {
        $response = $this->response(['Cache-Control' => self::PUBLIC_CC, 'X-Magento-Tags' => 'cat_p_1,cms_b']);

        $this->kernel()->process($response);

        self::assertCount(1, $this->saved);
        self::assertSame(self::PUBLIC_CC, $this->savedData()['headers']['Cache-Control']);
        self::assertTrue($this->noCacheCalled, 'the live response must still get no-store');
        self::assertSame(self::NO_STORE_CC, $this->headers['Cache-Control']);
    }

    public function testTheEntryIsSavedUnderTheSaveIdentifierWithTagsAndTheSharedMaxAge(): void
    {
        $response = $this->response(['Cache-Control' => self::PUBLIC_CC, 'X-Magento-Tags' => 'cat_p_1,cms_b']);

        $this->kernel()->process($response);

        self::assertSame('page-key', $this->saved[0][1]);
        self::assertSame(['cat_p_1', 'cms_b'], $this->saved[0][2]);
        self::assertSame(86400, $this->saved[0][3]);
        $data = $this->savedData();
        self::assertSame('<html>page</html>', $data['content']);
        self::assertSame(200, $data['status_code']);
        self::assertSame(['data' => ['store' => 'default'], 'default' => []], $data['context']);
    }

    public function testCookiesAndTagsAreStrippedBeforeSavingOutsideDeveloperMode(): void
    {
        $response = $this->response([
            'Cache-Control' => self::PUBLIC_CC,
            'X-Magento-Tags' => 'cat_p_1',
            'Set-Cookie' => 'a=b',
        ]);

        $this->kernel()->process($response);

        self::assertSame(['Set-Cookie', 'X-Magento-Tags'], $this->cleared);
        self::assertSame([true], $this->cookiesDisabled);
        self::assertArrayNotHasKey('Set-Cookie', $this->savedData()['headers']);
        self::assertArrayNotHasKey('X-Magento-Tags', $this->savedData()['headers']);
    }

    public function testDeveloperModeKeepsTheTagsHeader(): void
    {
        $this->mode = AppState::MODE_DEVELOPER;
        $response = $this->response(['Cache-Control' => self::PUBLIC_CC, 'X-Magento-Tags' => 'cat_p_1']);

        $this->kernel()->process($response);

        self::assertSame(['Set-Cookie'], $this->cleared);
        self::assertSame('cat_p_1', $this->savedData()['headers']['X-Magento-Tags']);
    }

    public function testAPageWithoutTagsIsSavedWithNoTags(): void
    {
        $response = $this->response(['Cache-Control' => self::PUBLIC_CC]);

        $this->kernel()->process($response);

        self::assertSame([], $this->saved[0][2]);
    }

    public function testA404IsCachedAndAHeadRequestIsCached(): void
    {
        $this->kernel()->process($this->response(['Cache-Control' => self::PUBLIC_CC], 404));
        $this->isGet = false;
        $this->isHead = true;
        $this->kernel()->process($this->response(['Cache-Control' => self::PUBLIC_CC]));

        self::assertCount(2, $this->saved);
        self::assertSame(404, $this->savedData(0)['status_code']);
    }

    public function testNothingHappensWithoutACacheControlHeader(): void
    {
        $response = $this->response([]);
        $response->expects(self::never())->method('setNoCacheHeaders');

        $this->kernel()->process($response);

        self::assertSame([], $this->saved);
    }

    public function testNothingHappensWhenCacheControlIsNotPublicWithASharedMaxAge(): void
    {
        $response = $this->response(['Cache-Control' => 'private, max-age=0']);
        $response->expects(self::never())->method('setNoCacheHeaders');

        $this->kernel()->process($response);

        self::assertSame([], $this->saved);
    }

    public function testAnErrorResponseIsNotSavedButStillGetsNoStore(): void
    {
        $this->kernel()->process($this->response(['Cache-Control' => self::PUBLIC_CC], 500));

        self::assertSame([], $this->saved);
        self::assertTrue($this->noCacheCalled);
        self::assertSame([], $this->cookiesDisabled);
    }

    public function testANotCacheableResponseIsNotSaved(): void
    {
        $this->kernel()->process(
            $this->response(['Cache-Control' => self::PUBLIC_CC], 200, NotCacheableResponse::class)
        );

        self::assertSame([], $this->saved);
        self::assertTrue($this->noCacheCalled);
    }

    public function testAPostRequestIsNotSaved(): void
    {
        $this->isGet = false;
        $this->isHead = false;

        $this->kernel()->process($this->response(['Cache-Control' => self::PUBLIC_CC]));

        self::assertSame([], $this->saved);
        self::assertTrue($this->noCacheCalled);
    }

    public function testOmittedDependenciesComeFromTheObjectManager(): void
    {
        $c = $this->collaborators();
        $byClass = [
            Context::class => $c['context'],
            SerializerInterface::class => $c['serializer'],
            AppState::class => $c['state'],
            FullPageCache::class => $c['fullPageCache'],
            IdentifierInterface::class => $c['identifierForSave'],
            CookieDisablerInterface::class => $c['cookieDisabler'],
        ];
        $this->objectManager($byClass);

        $kernel = new Kernel(
            $this->createStub(Cache::class),
            $this->createStub(IdentifierInterface::class),
            $c['request']
        );
        $kernel->process($this->response(['Cache-Control' => self::PUBLIC_CC]));

        self::assertCount(1, $this->saved);
        self::assertSame('page-key', $this->saved[0][1]);
        self::assertSame(self::PUBLIC_CC, $this->savedData()['headers']['Cache-Control']);
    }
}
