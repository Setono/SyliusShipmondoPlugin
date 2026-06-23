<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\CommandHandler;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Setono\Shipmondo\Client\Client;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\UploadOrderHandler;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\WorkflowInterface;

final class UploadOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderRepositoryInterface> */
    private ObjectProphecy $orderRepository;

    /** @var ObjectProphecy<SalesOrderDataMapperInterface> */
    private ObjectProphecy $salesOrderDataMapper;

    /** @var ObjectProphecy<WorkflowInterface> */
    private ObjectProphecy $orderWorkflow;

    /** @var ObjectProphecy<HttpClientInterface> */
    private ObjectProphecy $httpClient;

    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $this->salesOrderDataMapper = $this->prophesize(SalesOrderDataMapperInterface::class);
        $this->orderWorkflow = $this->prophesize(WorkflowInterface::class);
        $this->httpClient = $this->prophesize(HttpClientInterface::class);
        $this->psr17 = new Psr17Factory();
    }

    /**
     * @test
     */
    public function it_uploads_the_order_and_completes_the_upload(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getVersion()->willReturn(6);
        $this->orderRepository->find(42)->willReturn($order->reveal());

        $this->salesOrderDataMapper->map(Argument::cetera())->shouldBeCalled();
        $this->httpClient->sendRequest(Argument::type(RequestInterface::class))
            ->willReturn($this->psr17->createResponse()->withBody($this->psr17->createStream('{"id":12345}')))
        ;

        $order->setShipmondoId(12345)->shouldBeCalled();
        $this->orderWorkflow->apply($order->reveal(), OrderWorkflow::TRANSITION_COMPLETE_UPLOAD)
            ->willReturn(new Marking())
            ->shouldBeCalled()
        ;

        $this->handle(new UploadOrder(42));
    }

    /**
     * @test
     */
    public function it_throws_when_the_order_does_not_exist(): void
    {
        $this->orderRepository->find(42)->willReturn(null);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle(new UploadOrder(42));
    }

    /**
     * @test
     */
    public function it_throws_when_the_order_was_updated_since_upload_was_requested(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getVersion()->willReturn(6);
        $this->orderRepository->find(42)->willReturn($order->reveal());

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle(new UploadOrder(42, 5));
    }

    private function handle(UploadOrder $message): void
    {
        $client = new Client('user', 'key', true, $this->httpClient->reveal(), $this->psr17, $this->psr17);

        $handler = new UploadOrderHandler(
            $this->orderRepository->reveal(),
            $client,
            $this->salesOrderDataMapper->reveal(),
            $this->orderWorkflow->reveal(),
        );

        $handler($message);
    }
}
