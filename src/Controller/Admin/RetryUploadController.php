<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Admin;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lets an admin retry the upload of a failed order, from the order's page or for the orders selected in the order grid.
 * A retried order is pending again, so the next upload run uploads it: nothing is asked of Shipmondo here
 *
 * Both actions change state, so, like Sylius's own state transitions in the admin, they take a PUT (a POST with
 * _method=PUT) with a CSRF token
 */
final class RetryUploadController extends AbstractController
{
    /**
     * The id of the bulk action's CSRF token. The order page's retry takes the order's id, as Sylius's state transitions do
     */
    final public const BULK_CSRF_TOKEN_ID = 'setono_sylius_shipmondo_bulk_retry_upload';

    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderUploaderInterface $orderUploader,
    ) {
    }

    public function retry(Request $request, int $id): RedirectResponse
    {
        $this->assertCsrfTokenValid((string) $id, $request);

        $order = $this->orderRepository->find($id);
        if (!$order instanceof OrderInterface) {
            throw $this->createNotFoundException(sprintf('The order with id %d does not exist', $id));
        }

        if ($this->orderUploader->retry($order)) {
            $this->addFlash('success', 'setono_sylius_shipmondo.order_upload_retried');
        } else {
            $this->addFlash('error', 'setono_sylius_shipmondo.order_upload_not_retried');
        }

        return $this->redirectToRoute('sylius_admin_order_show', ['id' => $id]);
    }

    /**
     * Retries every selected order that is failed and leaves the rest as they are. The selected orders are posted as
     * ids[], as Sylius's admin scripts post the selection of a bulk action
     */
    public function bulkRetry(Request $request): RedirectResponse
    {
        $this->assertCsrfTokenValid(self::BULK_CSRF_TOKEN_ID, $request);

        $retried = 0;
        $notRetried = 0;

        foreach ($this->orderRepository->findBy(['id' => $request->request->all('ids')]) as $order) {
            if ($order instanceof OrderInterface && $this->orderUploader->retry($order)) {
                ++$retried;
            } else {
                ++$notRetried;
            }
        }

        $this->addFlash($retried > 0 ? 'success' : 'info', [
            'message' => 'setono_sylius_shipmondo.orders_upload_retried',
            'parameters' => ['%count%' => $retried],
        ]);

        if ($notRetried > 0) {
            $this->addFlash('info', [
                'message' => 'setono_sylius_shipmondo.orders_upload_not_retried',
                'parameters' => ['%count%' => $notRetried],
            ]);
        }

        return $this->redirectToRoute('sylius_admin_order_index');
    }

    private function assertCsrfTokenValid(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_csrf_token'))) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Invalid CSRF token.');
        }
    }
}
