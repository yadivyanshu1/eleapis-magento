<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Observer;

use EleAPIs\Connector\Model\Config;
use EleAPIs\Connector\Model\EventClient;
use EleAPIs\Connector\Model\PayloadBuilder;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Address;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Emits "customer.created" the moment a customer first becomes reachable by phone.
 *
 * Magento's registration form collects no telephone, so announcing at registration time yields an
 * unmessageable contact. Instead this observer watches address saves for the 0 → 1 phone
 * transition: the saved address gains a telephone it did not have before, and no other address of
 * the customer carries one. That fires exactly once per customer — address edits, extra
 * addresses, and phone changes later do not re-announce.
 *
 * The whole body is guarded: a failure must never disturb the address save.
 */
class CustomerAddressSaved implements ObserverInterface
{
    /** @var Config */
    private $config;

    /** @var PayloadBuilder */
    private $payloadBuilder;

    /** @var EventClient */
    private $eventClient;

    /** @var CustomerRepositoryInterface */
    private $customerRepository;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param Config $config
     * @param PayloadBuilder $payloadBuilder
     * @param EventClient $eventClient
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        PayloadBuilder $payloadBuilder,
        EventClient $eventClient,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->payloadBuilder = $payloadBuilder;
        $this->eventClient = $eventClient;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Emits customer.created on the customer's first-ever phone number. Never throws.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isReady()) {
                return;
            }

            $address = $observer->getEvent()->getData('customer_address');

            if (!$address instanceof Address) {
                return;
            }

            $phone = trim((string) $address->getData('telephone'));
            $previousPhone = trim((string) $address->getOrigData('telephone'));
            $customerId = (int) $address->getCustomerId();

            // Only the moment a phone first appears on this address (new address with phone, or
            // a phone added to a previously phone-less address) for a registered customer.
            if ($phone === '' || $previousPhone !== '' || !$customerId) {
                return;
            }

            $customer = $this->customerRepository->getById($customerId);

            // Another address already had a phone → this customer was announced before.
            if ($this->hasOtherAddressWithPhone($customer, (int) $address->getId())) {
                return;
            }

            $this->eventClient->send(
                $this->payloadBuilder->buildCustomerEnvelope(Config::EVENT_CUSTOMER_CREATED, $customer, $phone)
            );
        } catch (\Throwable $e) {
            $this->logger->error('[EleAPIs_Connector] customer address observer failed: ' . $e->getMessage());
        }
    }

    /**
     * Whether any address other than the one just saved already carries a telephone.
     *
     * @param CustomerInterface $customer
     * @param int $savedAddressId
     * @return bool
     */
    private function hasOtherAddressWithPhone(CustomerInterface $customer, int $savedAddressId): bool
    {
        foreach ((array) $customer->getAddresses() as $existing) {
            if ((int) $existing->getId() === $savedAddressId) {
                continue;
            }

            if (trim((string) $existing->getTelephone()) !== '') {
                return true;
            }
        }

        return false;
    }
}
