<?php

namespace Lofmp\Cfcheckout\Controller\Standard;

use Cashfree\Cfcheckout\Model\Cfcheckout;
use Magento\Framework\Controller\ResultFactory;

class Response extends \Cashfree\Cfcheckout\Controller\Standard\Response {

    /**
     * Get related order by quote
     */
    protected function getCurrentOrder($quote)
    {
        $quoteId = $quote->getId();
        # fetch the related sales order
        # To avoid duplicate order entry for same quote
        $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
                                            ->getCollection()
                                            ->addFieldToSelect('entity_id')
                                            ->addFilter('quote_id', $quoteId)
                                            ->getFirstItem();
        $salesOrder = $collection->getData();

        if (empty($salesOrder['entity_id']) === true) {
            $order = $this->quoteManagement->submit($quote);
        } else {
            $order = $this->orderRepository->get($salesOrder['entity_id']);
        }
        return $order;
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute() {
        $returnUrl = $this->getCheckoutHelper()->getUrl('checkout');
        $params = $this->getRequest()->getParams();
        $quoteId = strip_tags($params["orderId"]);
        list($quoteId) = explode('_', $quoteId);
        $quote = $this->getQuoteObject($params, $quoteId);
        if (!$this->getCustomerSession()->isLoggedIn()) {
            $customerId = $quote->getCustomer()->getId();
            if(!empty($customerId)) {
                $customer = $this->customerFactory->create()->load($customerId);
                $this->_customerSession->setCustomerAsLoggedIn($customer);
            }
        }
        try {
            $paymentMethod = $this->getPaymentMethod();
            $status = $paymentMethod->validateResponse($params);
            $debugLog = "";
            if ($status == "SUCCESS") {
                # fetch the related sales order
                # To avoid duplicate order entry for same quote
                $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
                                                   ->getCollection()
                                                   ->addFieldToSelect('entity_id')
                                                   ->addFilter('quote_id', $quoteId)
                                                   ->getFirstItem();
                $salesOrder = $collection->getData();

                if (empty($salesOrder['entity_id']) === true) {
                    $order = $this->quoteManagement->submit($quote);
                    $payment = $order->getPayment();

                    $paymentMethod->postProcessing($order, $payment, $params);
                } else {
                    $order = $this->orderRepository->get($salesOrder['entity_id']);
                }
                $this->_checkoutSession
                            ->setLastQuoteId($quote->getId())
                            ->setLastSuccessQuoteId($quote->getId())
                            ->clearHelperData();

                if ($order) {
                    $this->_checkoutSession->setLastOrderId($order->getId())
                                        ->setLastRealOrderId($order->getIncrementId())
                                        ->setLastOrderStatus($order->getStatus());


                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_response',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }

                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/success');
                $this->messageManager->addSuccess(__('Your payment was successful'));
                $debugLog = "Order status changes to processing for quote id: ".$quoteId;

            } else if ($status == "CANCELLED") {
                $order = $this->getCurrentOrder($quote);
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $this->_checkoutSession->replaceQuote($quote);
                $this->messageManager->addError($params['txMsg']);
                $debugLog = "Order status changes to cancelled for quote id: ".$quoteId;
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/cart');
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_response',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            } else if ($status == "FAILED") {
                $order = $this->getCurrentOrder($quote);
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $this->_checkoutSession->replaceQuote($quote);
                $this->messageManager->addError($params['txMsg']);
                $debugLog = "Order status changes to falied for quote id: ".$quoteId;
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/cart');
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_response',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            } else if($status == "PENDING"){
                $order = $this->getCurrentOrder($quote);
                $debugLog = "Order status changes to pending for quote id: ".$quoteId;
                $this->messageManager->addWarning(__('Your payment is pending'));
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_response',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            } else{
                $order = $this->getCurrentOrder($quote);
                $debugLog = "Order status changes to pending for quote id: ".$quoteId;
                $this->messageManager->addErrorMessage(__('There is an error.Payment status is pending'));
                $returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/failure');
                if ($order) {
                    $this->_eventManager->dispatch(
                        'cfcheckout_controller_standard_response',
                        [
                            'order_ids' => [$order->getId()],
                            'order' => $order,
                            'quote' => $quote,
                            'status' => $status,
                            'request' => $this
                        ]
                    );
                }
            }

            $enabledDebug = $paymentMethod->enabledDebugLog();
            if($enabledDebug === "1"){
                $this->logger->info($debugLog);
            }

        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order.'));
        }

        $this->getResponse()->setRedirect($returnUrl);
    }
}
