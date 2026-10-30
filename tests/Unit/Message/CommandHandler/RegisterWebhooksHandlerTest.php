<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\CommandHandler;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\RegisterWebhooksHandler;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class RegisterWebhooksHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<RegisteredWebhooksRepositoryInterface> */
    private ObjectProphecy $repository;

    /** @var ObjectProphecy<WebhookRegistrarInterface> */
    private ObjectProphecy $registrar;

    /** @var ObjectProphecy<FactoryInterface> */
    private ObjectProphecy $factory;

    protected function setUp(): void
    {
        $this->repository = $this->prophesize(RegisteredWebhooksRepositoryInterface::class);
        $this->registrar = $this->prophesize(WebhookRegistrarInterface::class);
        $this->registrar->getVersion()->willReturn('version-1');
        $this->factory = $this->prophesize(FactoryInterface::class);
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_and_records_the_registered_version(): void
    {
        $registeredWebhooks = new RegisteredWebhooks();

        $this->registrar->register()->shouldBeCalledOnce();
        $this->repository->findOneByVersion('version-1')->willReturn(null);
        $this->factory->createNew()->willReturn($registeredWebhooks);
        $this->repository->add($registeredWebhooks)->shouldBeCalledOnce();

        $before = new \DateTimeImmutable();
        $this->handle();

        self::assertSame('version-1', $registeredWebhooks->getVersion());
        self::assertNotNull($registeredWebhooks->getRegisteredAt());
        self::assertGreaterThanOrEqual($before, $registeredWebhooks->getRegisteredAt());
    }

    /**
     * @test
     */
    public function it_updates_the_registration_time_of_an_already_registered_version(): void
    {
        $registeredWebhooks = new RegisteredWebhooks();
        $registeredWebhooks->setVersion('version-1');
        $registeredWebhooks->setRegisteredAt(new \DateTimeImmutable('2020-01-01'));

        $this->registrar->register()->shouldBeCalledOnce();
        $this->repository->findOneByVersion('version-1')->willReturn($registeredWebhooks);
        $this->factory->createNew()->shouldNotBeCalled();
        $this->repository->add($registeredWebhooks)->shouldBeCalledOnce();

        $this->handle();

        self::assertGreaterThan(new \DateTimeImmutable('2020-01-02'), $registeredWebhooks->getRegisteredAt());
    }

    /**
     * @test
     */
    public function it_throws_when_the_factory_does_not_create_registered_webhooks(): void
    {
        $this->registrar->register()->shouldBeCalledOnce();
        $this->repository->findOneByVersion('version-1')->willReturn(null);
        $this->factory->createNew()->willReturn(new \stdClass());
        $this->repository->add(Argument::any())->shouldNotBeCalled();

        $this->expectException(\InvalidArgumentException::class);

        $this->handle();
    }

    private function handle(): void
    {
        $handler = new RegisterWebhooksHandler($this->repository->reveal(), $this->registrar->reveal(), $this->factory->reveal());
        $handler(new RegisterWebhooks());
    }
}
