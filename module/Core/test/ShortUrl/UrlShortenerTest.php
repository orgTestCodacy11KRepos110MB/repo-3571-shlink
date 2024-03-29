<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\ShortUrl;

use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shlinkio\Shlink\Core\Exception\NonUniqueSlugException;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\Helper\ShortCodeUniquenessHelperInterface;
use Shlinkio\Shlink\Core\ShortUrl\Helper\ShortUrlTitleResolutionHelperInterface;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlCreation;
use Shlinkio\Shlink\Core\ShortUrl\Repository\ShortUrlRepository;
use Shlinkio\Shlink\Core\ShortUrl\Resolver\SimpleShortUrlRelationResolver;
use Shlinkio\Shlink\Core\ShortUrl\UrlShortener;

class UrlShortenerTest extends TestCase
{
    private UrlShortener $urlShortener;
    private MockObject & EntityManager $em;
    private MockObject & ShortUrlTitleResolutionHelperInterface $titleResolutionHelper;
    private MockObject & ShortCodeUniquenessHelperInterface $shortCodeHelper;

    protected function setUp(): void
    {
        $this->titleResolutionHelper = $this->createMock(ShortUrlTitleResolutionHelperInterface::class);
        $this->shortCodeHelper = $this->createMock(ShortCodeUniquenessHelperInterface::class);

        // FIXME Should use the interface, but it doe snot define wrapInTransaction explicitly
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('persist')->willReturnCallback(fn (ShortUrl $shortUrl) => $shortUrl->setId('10'));
        $this->em->method('wrapInTransaction')->with($this->isType('callable'))->willReturnCallback(
            fn (callable $callback) => $callback(),
        );

        $this->urlShortener = new UrlShortener(
            $this->titleResolutionHelper,
            $this->em,
            new SimpleShortUrlRelationResolver(),
            $this->shortCodeHelper,
            $this->createMock(EventDispatcherInterface::class),
        );
    }

    /** @test */
    public function urlIsProperlyShortened(): void
    {
        $longUrl = 'http://foobar.com/12345/hello?foo=bar';
        $meta = ShortUrlCreation::fromRawData(['longUrl' => $longUrl]);
        $this->titleResolutionHelper->expects($this->once())->method('processTitleAndValidateUrl')->with(
            $meta,
        )->willReturnArgument(0);
        $this->shortCodeHelper->method('ensureShortCodeUniqueness')->willReturn(true);

        $shortUrl = $this->urlShortener->shorten($meta);

        self::assertEquals($longUrl, $shortUrl->getLongUrl());
    }

    /** @test */
    public function exceptionIsThrownWhenNonUniqueSlugIsProvided(): void
    {
        $meta = ShortUrlCreation::fromRawData(
            ['customSlug' => 'custom-slug', 'longUrl' => 'http://foobar.com/12345/hello?foo=bar'],
        );

        $this->shortCodeHelper->expects($this->once())->method('ensureShortCodeUniqueness')->willReturn(false);
        $this->titleResolutionHelper->expects($this->once())->method('processTitleAndValidateUrl')->with(
            $meta,
        )->willReturnArgument(0);

        $this->expectException(NonUniqueSlugException::class);

        $this->urlShortener->shorten($meta);
    }

    /**
     * @test
     * @dataProvider provideExistingShortUrls
     */
    public function existingShortUrlIsReturnedWhenRequested(ShortUrlCreation $meta, ShortUrl $expected): void
    {
        $repo = $this->createMock(ShortUrlRepository::class);
        $repo->expects($this->once())->method('findOneMatching')->willReturn($expected);
        $this->em->expects($this->once())->method('getRepository')->with(ShortUrl::class)->willReturn($repo);
        $this->titleResolutionHelper->expects($this->never())->method('processTitleAndValidateUrl');
        $this->shortCodeHelper->method('ensureShortCodeUniqueness')->willReturn(true);

        $result = $this->urlShortener->shorten($meta);

        self::assertSame($expected, $result);
    }

    public function provideExistingShortUrls(): iterable
    {
        $url = 'http://foo.com';

        yield [ShortUrlCreation::fromRawData(['findIfExists' => true, 'longUrl' => $url]), ShortUrl::withLongUrl(
            $url,
        )];
        yield [ShortUrlCreation::fromRawData(
            ['findIfExists' => true, 'customSlug' => 'foo', 'longUrl' => $url],
        ), ShortUrl::withLongUrl($url)];
        yield [
            ShortUrlCreation::fromRawData(['findIfExists' => true, 'longUrl' => $url, 'tags' => ['foo', 'bar']]),
            ShortUrl::create(ShortUrlCreation::fromRawData(['longUrl' => $url, 'tags' => ['foo', 'bar']])),
        ];
        yield [
            ShortUrlCreation::fromRawData(['findIfExists' => true, 'maxVisits' => 3, 'longUrl' => $url]),
            ShortUrl::create(ShortUrlCreation::fromRawData(['maxVisits' => 3, 'longUrl' => $url])),
        ];
        yield [
            ShortUrlCreation::fromRawData(
                ['findIfExists' => true, 'validSince' => Chronos::parse('2017-01-01'), 'longUrl' => $url],
            ),
            ShortUrl::create(
                ShortUrlCreation::fromRawData(['validSince' => Chronos::parse('2017-01-01'), 'longUrl' => $url]),
            ),
        ];
        yield [
            ShortUrlCreation::fromRawData(
                ['findIfExists' => true, 'validUntil' => Chronos::parse('2017-01-01'), 'longUrl' => $url],
            ),
            ShortUrl::create(
                ShortUrlCreation::fromRawData(['validUntil' => Chronos::parse('2017-01-01'), 'longUrl' => $url]),
            ),
        ];
        yield [
            ShortUrlCreation::fromRawData(['findIfExists' => true, 'domain' => 'example.com', 'longUrl' => $url]),
            ShortUrl::create(ShortUrlCreation::fromRawData(['domain' => 'example.com', 'longUrl' => $url])),
        ];
        yield [
            ShortUrlCreation::fromRawData([
                'findIfExists' => true,
                'validUntil' => Chronos::parse('2017-01-01'),
                'maxVisits' => 4,
                'longUrl' => $url,
                'tags' => ['baz', 'foo', 'bar'],
            ]),
            ShortUrl::create(ShortUrlCreation::fromRawData([
                'validUntil' => Chronos::parse('2017-01-01'),
                'maxVisits' => 4,
                'longUrl' => $url,
                'tags' => ['foo', 'bar', 'baz'],
            ])),
        ];
    }
}
