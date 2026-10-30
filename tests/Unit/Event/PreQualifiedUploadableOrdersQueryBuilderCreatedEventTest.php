<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Event;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Event\PreQualifiedUploadableOrdersQueryBuilderCreatedEvent;

final class PreQualifiedUploadableOrdersQueryBuilderCreatedEventTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_exposes_the_query_builder_so_listeners_can_filter_the_orders(): void
    {
        $queryBuilder = new QueryBuilder($this->prophesize(EntityManagerInterface::class)->reveal());

        self::assertSame($queryBuilder, (new PreQualifiedUploadableOrdersQueryBuilderCreatedEvent($queryBuilder))->queryBuilder);
    }
}
