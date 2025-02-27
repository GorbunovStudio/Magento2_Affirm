<?php

declare(strict_types=1);

namespace Astound\Affirm\Plugin;

use Astound\Affirm\Service\PlacedOrderHolder;
use Braintree\Transaction;
use Closure;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use RuntimeException;

class OrderCancellation
{
    /**
     * @param Magento\Quote\Api\CartRepositoryInterface $quoteRepository 
     * @param Magento\Sales\Api\OrderRepositoryInterface $orderRepository 
     * @param PlacedOrderHolder $placedOrderHolder 
     * @param Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory 
     */
    public function __construct(
        private CartRepositoryInterface $quoteRepository,
        private OrderRepositoryInterface $orderRepository,
        private PlacedOrderHolder $placedOrderHolder,
        private CreditmemoFactory $creditmemoFactory
    ) {
    }

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

            // Abort if the order object is not available or available not a relevant order.
            if (!$order || $order->getIncrementId() !== $quote->getReservedOrderId()) {
                throw $e;
            }

            // Cancel the order in case when it was saved.
            if ($order->getId()) {
                $order->cancel();

                $this->orderRepository->save($order);

                throw $e;
            }

            /** @var \Magento\Sales\Model\Order\Payment|null */
            $orderPayment = $order->getPayment();

            // Abort if the order lacks payment information.
            if (!$orderPayment) {
                throw $e;
            }

            $methodInstance = $orderPayment->getMethodInstance();
            $methodInstance->setStore($order->getStoreId());

            $isPaymentCanceled = false;

            switch ($orderPayment->getAdditionalInformation('status')) {
                case Transaction::SUBMITTED_FOR_SETTLEMENT:
                    if ($methodInstance->canVoid()) {
                        $methodInstance->void($orderPayment);

                        $isPaymentCanceled = true;
                    }

                    break;
                case Transaction::SETTLED:
                case Transaction::SETTLING:
                    if ($orderPayment->getCreatedTransaction() && $orderPayment->getCreatedInvoice()) {
                        $creditmemo = $this->creditmemoFactory->createByOrder($order);
                        $creditmemo->setInvoice($orderPayment->getCreatedInvoice());

                        $orderPayment->setCreditmemo($creditmemo);
                        $orderPayment->setParentTransactionId($orderPayment->getCreatedTransaction()->getTxnId());

                        $methodInstance->refund($orderPayment, $orderPayment->getAmountPaid());

                        $isPaymentCanceled = true;    
                    }
                    
                    break; 
            }

            if ($isPaymentCanceled) {
                throw new RuntimeException(
                    "The order payment with transaction ID '{$orderPayment->getTransactionId()}' has been canceled.",
                    $e->getCode(),
                    $e
                );
            }

            throw new RuntimeException(
                "The order payment with transaction ID '{$orderPayment->getTransactionId()}' has not been canceled.
                 Please, notify us to cancel the payment.",
                $e->getCode(),
                $e
            );
        }
    }
}
