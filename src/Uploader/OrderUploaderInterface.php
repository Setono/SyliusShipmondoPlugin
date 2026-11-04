<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * Uploads Sylius orders to Shipmondo as sales orders, and keeps each order's Shipmondo state in step with how its
 * upload went
 */
interface OrderUploaderInterface
{
    /**
     * Runs an upload run: puts each pending order that qualifies for upload in uploading, then uploads it through the
     * UploadOrder command.
     *
     * When UploadOrder is handled synchronously (the default), the run stops early after a credentials failure, a 429,
     * or three rejected uploads in a row, because the remaining orders would most likely fail the same way. They stay
     * pending for the next run.
     *
     * @return UploadOutcome|null the outcome that stopped the run early ({@see UploadOutcome::CredentialsFailure},
     *                            {@see UploadOutcome::RateLimited}, or {@see UploadOutcome::Rejected} for the third
     *                            rejected upload in a row), or null when the run got through every order
     */
    public function upload(): ?UploadOutcome;

    /**
     * Uploads an order whose Shipmondo state is uploading, and settles that state: uploaded (and linked to its sales
     * order), failed, or back to pending for the next upload run. The returned outcome says which, and why. A failed
     * upload is logged, never thrown.
     *
     * An order in any other Shipmondo state is left alone ({@see UploadOutcome::Skipped}).
     *
     * @param int|null $version the order's version when its upload started. When the order has another version
     *                          now, it changed since, so it goes back to pending without being uploaded
     */
    public function uploadOrder(OrderInterface $order, ?int $version = null): UploadOutcome;
}
