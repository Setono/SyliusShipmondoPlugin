<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepository;

final class RegisteredWebhooksRepositoryTest extends TestCase
{
    /**
     * @test
     */
    public function it_finds_the_registered_webhooks_by_version(): void
    {
        $registeredWebhooks = new RegisteredWebhooks();

        self::assertSame($registeredWebhooks, $this->repository($registeredWebhooks)->findOneByVersion('v1'));
    }

    /**
     * @test
     */
    public function it_returns_null_when_the_version_is_not_registered(): void
    {
        self::assertNull($this->repository(null)->findOneByVersion('v1'));
    }

    /**
     * @test
     */
    public function it_throws_when_the_repository_returns_something_else(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository(new \stdClass())->findOneByVersion('v1');
    }

    private function repository(?object $result): RegisteredWebhooksRepository
    {
        $repository = $this->getMockBuilder(RegisteredWebhooksRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock()
        ;

        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['version' => 'v1'])
            ->willReturn($result)
        ;

        return $repository;
    }
}
