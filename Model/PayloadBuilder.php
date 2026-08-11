<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the normalized event envelope sent to the webhook.
 *
 * The receiving side (whatsapp-automation) maps this shape into its common pipeline, so the
 * contract here is the single source of the payload shape. Keep it stable and versioned.
 */
class PayloadBuilder
{
    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var ProductMetadataInterface */
    private $productMetadata;

    /** @var IdentityGeneratorInterface */
    private $identityGenerator;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ProductMetadataInterface $productMetadata
     * @param IdentityGeneratorInterface $identityGenerator
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        ProductMetadataInterface $productMetadata,
        IdentityGeneratorInterface $identityGenerator,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->productMetadata = $productMetadata;
        $this->identityGenerator = $identityGenerator;
        $this->logger = $logger;
    }

    /**
     * Envelope for an order lifecycle event.
     *
     * @param string $event
     * @param OrderInterface $order
     * @return array
     */
    public function buildOrderEnvelope(string $event, OrderInterface $order): array
    {
        return $this->envelope($event, (int) $order->getStoreId(), $this->buildOrderData($order));
    }

    /**
     * Envelope for a customer lifecycle event.
     *
     * @param string $event
     * @param CustomerInterface $customer
     * @param string|null $phoneOverride Known-fresh phone (e.g. from a just-saved address) that
     *                                   takes precedence over the customer's stored addresses.
     * @return array
     */
    public function buildCustomerEnvelope(
        string $event,
        CustomerInterface $customer,
        ?string $phoneOverride = null
    ): array {
        return $this->envelope(
            $event,
            (int) $customer->getStoreId(),
            $this->buildCustomerData($customer, $phoneOverride)
        );
    }

    /**
     * Envelope for an abandoned-cart event.
     *
     * @param string $event
     * @param Quote $quote
     * @return array
     */
    public function buildCartEnvelope(string $event, Quote $quote): array
    {
        return $this->envelope($event, (int) $quote->getStoreId(), $this->buildCartData($quote));
    }

    /**
     * Common versioned envelope wrapper.
     *
     * @param string $event
     * @param int $storeId
     * @param array $data
     * @return array
     */
    private function envelope(string $event, int $storeId, array $data): array
    {
        return [
            'specVersion' => Config::SPEC_VERSION,
            'event'       => $event,
            'deliveryId'  => $this->identityGenerator->generateId(),
            'occurredAt'  => gmdate('c'),
            'store'       => $this->buildStoreMeta($storeId),
            'data'        => $data,
        ];
    }

    /**
     * Order fields of the envelope.
     *
     * @param OrderInterface $order
     * @return array
     */
    private function buildOrderData(OrderInterface $order): array
    {
        $billing = $order->getBillingAddress();
        $firstName = $billing ? (string) $billing->getFirstname() : (string) $order->getCustomerFirstname();
        $lastName = $billing ? (string) $billing->getLastname() : (string) $order->getCustomerLastname();

        return [
            'orderId'   => (string) $order->getIncrementId(),
            // Null before persistence (order.created fires pre-save); the increment id (orderId)
            // is the stable key the receiver uses. Present on later status-change events.
            'entityId'  => $order->getEntityId() ? (int) $order->getEntityId() : null,
            'status'    => (string) $order->getStatus(),
            'customer'  => [
                'name'      => trim($firstName . ' ' . $lastName),
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'email'     => (string) $order->getCustomerEmail(),
                'phone'     => $billing ? (string) $billing->getTelephone() : '',
            ],
            'items'     => $this->buildOrderItems($order),
            'total'     => (float) $order->getGrandTotal(),
            'currency'  => (string) $order->getOrderCurrencyCode(),
            'createdAt' => (string) $order->getCreatedAt(),
            'updatedAt' => (string) $order->getUpdatedAt(),
        ];
    }

    /**
     * Customer fields of the envelope.
     *
     * @param CustomerInterface $customer
     * @param string|null $phoneOverride
     * @return array
     */
    private function buildCustomerData(CustomerInterface $customer, ?string $phoneOverride = null): array
    {
        $firstName = (string) $customer->getFirstname();
        $lastName = (string) $customer->getLastname();

        return [
            'customer' => [
                'name'      => trim($firstName . ' ' . $lastName),
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'email'     => (string) $customer->getEmail(),
                'phone'     => $phoneOverride !== null && $phoneOverride !== ''
                    ? $phoneOverride
                    : $this->firstCustomerPhone($customer),
            ],
        ];
    }

    /**
     * Abandoned-cart fields of the envelope.
     *
     * @param Quote $quote
     * @return array
     */
    private function buildCartData(Quote $quote): array
    {
        // Quote::getBillingAddress() always returns an address instance (empty if unset).
        // A cart abandoned before the checkout address step has empty quote addresses, so fall
        // back to what the logged-in account already provides: names stored on the quote row and
        // a phone from the customer's saved address book.
        $billing = $quote->getBillingAddress();
        $shipping = $quote->getShippingAddress();

        $firstName = (string) ($billing->getFirstname() ?: $quote->getData('customer_firstname'));
        $lastName = (string) ($billing->getLastname() ?: $quote->getData('customer_lastname'));
        $phone = (string) ($billing->getTelephone() ?: $shipping->getTelephone());

        if ($phone === '' && $quote->getCustomerId()) {
            $customer = $quote->getCustomer();

            if ($customer instanceof CustomerInterface) {
                $phone = $this->firstCustomerPhone($customer);
            }
        }

        return [
            'cartId'      => (int) $quote->getId(),
            'customer'    => [
                'name'      => trim($firstName . ' ' . $lastName),
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'email'     => (string) $quote->getCustomerEmail(),
                'phone'     => $phone,
            ],
            'items'       => $this->buildQuoteItems($quote),
            'total'       => (float) $quote->getGrandTotal(),
            'currency'    => (string) $quote->getQuoteCurrencyCode(),
            'abandonedAt' => (string) $quote->getUpdatedAt(),
        ];
    }

    /**
     * Top-level order line items (child items of configurables/bundles are skipped).
     *
     * @param OrderInterface $order
     * @return array
     */
    private function buildOrderItems(OrderInterface $order): array
    {
        $items = [];

        foreach ((array) $order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }

            $items[] = [
                'name'  => (string) $item->getName(),
                'sku'   => (string) $item->getSku(),
                'price' => (float) $item->getPrice(),
                'qty'   => (float) $item->getQtyOrdered(),
            ];
        }

        return $items;
    }

    /**
     * Visible quote line items.
     *
     * @param Quote $quote
     * @return array
     */
    private function buildQuoteItems(Quote $quote): array
    {
        $items = [];

        foreach ((array) $quote->getAllVisibleItems() as $item) {
            $items[] = [
                'name'  => (string) $item->getName(),
                'sku'   => (string) $item->getSku(),
                'price' => (float) $item->getPrice(),
                'qty'   => (float) $item->getQty(),
            ];
        }

        return $items;
    }

    /**
     * First non-empty telephone across the customer's addresses.
     *
     * @param CustomerInterface $customer
     * @return string
     */
    private function firstCustomerPhone(CustomerInterface $customer): string
    {
        foreach ((array) $customer->getAddresses() as $address) {
            $phone = (string) $address->getTelephone();

            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    /**
     * Store metadata block (best-effort: blanks on lookup failure, never throws).
     *
     * @param int $storeId
     * @return array
     */
    public function buildStoreMeta(int $storeId): array
    {
        $url = '';
        $code = '';
        $name = '';

        try {
            /** @var \Magento\Store\Model\Store $store */
            $store = $this->storeManager->getStore($storeId);
            $url = (string) $store->getBaseUrl();
            $code = (string) $store->getCode();
            $name = (string) $store->getName();
        } catch (\Throwable $e) {
            $this->logger->debug('[EleAPIs_Connector] store meta lookup failed: ' . $e->getMessage());
        }

        return [
            'url'            => $url,
            'code'           => $code,
            'name'           => $name,
            'edition'        => (string) $this->productMetadata->getEdition(),
            'magentoVersion' => (string) $this->productMetadata->getVersion(),
            'moduleVersion'  => Config::MODULE_VERSION,
        ];
    }
}
