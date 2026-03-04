<?php

declare(strict_types=1);

namespace Astound\Affirm\Plugin;

use Astound\Affirm\Service\PlacedOrderHolder;

use Closure;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use RuntimeException;

class OrderCancellation
{
    /**
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository 
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository 
     * @param \Astound\Affirm\Service\PlacedOrderHolder\PlacedOrderHolder $placedOrderHolder 
     * @param \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory 
     */
    public function __construct(
        private CartRepositoryInterface $quoteRepository,
        private OrderRepositoryInterface $orderRepository,
        private PlacedOrderHolder $placedOrderHolder,
        private CreditmemoFactory $creditmemoFactory
    ) {}

    public function aroundPlaceOrder(
        CartManagementInterface $subject,
        Closure $proceed,
        int $cartId,
        PaymentInterface $payment = null
    ): int {
        try {
            return (int)$proceed($cartId, $payment);
        } catch (\Throwable $e) {
            $quote = $this->quoteRepository->get((int) $cartId);

            $payment = $quote->getPayment();

            if ($payment->getMethod() !== 'affirm_gateway') {
                throw $e;
            }

            /** @var \Magento\Sales\Model\Order|null */
            $order = $this->placedOrderHolder->retrieve();


            if ($e instanceof ValidatorException && !$order) {
                throw $e;
            }

            $errorMessagePrefix = 'Unable to cancel payment: ';

            if (!$order) {
                throw new RuntimeException(
                    $errorMessagePrefix . "Order data unavailable. Reserved order ID: {$quote->getReservedOrderId()}",
                    $e->getCode(),
                    $e
                );
            }

            if ($order->getIncrementId() !== $quote->getReservedOrderId()) {
                throw new RuntimeException(
                    $errorMessagePrefix . "Available order data ({$order->getIncrementId()}, {$order->getId()}) doesn't match the quote value: {$quote->getReservedOrderId()}",
                    $e->getCode(),
                    $e
                );
            }

        
            if ($order->getId()) {
                $order->cancel();
                $this->orderRepository->save($order);
            }

            /** @var \Magento\Sales\Model\Order\Payment|null */
            $orderPayment = $order->getPayment();

            if (!$orderPayment) {
                throw $e;
            }

            $createdTransaction = $orderPayment->getCreatedTransaction();
            $transactionId = $createdTransaction?->getTxnId()
                ?: $orderPayment->getAdditionalInformation('transaction_id')
                ?: $orderPayment->getAdditionalInformation('charge_id');

            if (!$transactionId) {
                throw $e;
            }

            $methodInstance = $orderPayment->getMethodInstance();
            $methodInstance->setStore($order->getStoreId());

            if (!$methodInstance->canRefund()) {
                throw new RuntimeException(
                    $errorMessagePrefix . "Transaction can not be refunded.",
                    $e->getCode(),
                    $e
                );
            }

            $invoice = $orderPayment->getCreatedInvoice();

            if (!$invoice) {
                $invoice = $order->prepareInvoice();
                $invoice->register();
            }

            $creditmemo = $this->creditmemoFactory->createByOrder($order);
            $creditmemo->setInvoice($invoice);

            $orderPayment->setCreditmemo($creditmemo);
            $orderPayment->setParentTransactionId($transactionId);

            $methodInstance->refund($orderPayment, $orderPayment->getAmountPaid());
            throw $e;
        } finally {
            $this->placedOrderHolder->clear();
        }
    }
}
