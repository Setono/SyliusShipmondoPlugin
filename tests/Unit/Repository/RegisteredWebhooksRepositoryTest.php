<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepository;

final class RegisteredWebhooksRepositoryTest extends TestCase
{
    /**
     * The latest registration is the one registered last, and of two registered at the same time, the one recorded last
     *
     * @test
     */
    public function it_finds_the_latest_registration(): void
    {
        $registeredWebhooks = new RegisteredWebhooks();

        self::assertSame($registeredWebhooks, $this->repository($registeredWebhooks)->findLatest());
    }

    /**
     * @test
     */
    public function it_returns_null_when_no_registration_is_recorded(): void
    {
        self::assertNull($this->repository(null)->findLatest());
    }

    /**
     * @test
     */
    public function it_throws_when_the_repository_returns_something_else(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository(new \stdClass())->findLatest();
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
            ->with([], ['registeredAt' => 'DESC', 'id' => 'DESC'])
            ->willReturn($result)
        ;

        return $repository;
    }
}
