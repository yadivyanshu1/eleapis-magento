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
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Emits a "customer.created" event when a customer registers WITH a reachable phone.
 *
 * Magento's standard registration form collects no telephone, so most registrations carry no
 * phone — those are NOT announced here (an unmessageable contact has no value downstream).
 * CustomerAddressSaved emits the event later, the moment the customer's first phone appears.
 * Registrations that do include a phone (e.g. some checkout flows) announce immediately.
 *
 * The whole body is guarded: a failure to build or deliver the event must never
 * disturb registration.
 */
class CustomerRegistered implements ObserverInterface
{
    /** @var Config */
    private $config;

    /** @var PayloadBuilder */
    private $payloadBuilder;

    /** @var EventClient */
    private $eventClient;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param Config $config
     * @param PayloadBuilder $payloadBuilder
     * @param EventClient $eventClient
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        PayloadBuilder $payloadBuilder,
        EventClient $eventClient,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->payloadBuilder = $payloadBuilder;
        $this->eventClient = $eventClient;
        $this->logger = $logger;
    }

    /**
     * Builds and sends the customer.created envelope. Never throws into the registration flow.
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

            $customer = $observer->getEvent()->getData('customer');

            if (!$customer instanceof CustomerInterface || !$customer->getId()) {
                return;
            }

            $envelope = $this->payloadBuilder->buildCustomerEnvelope(Config::EVENT_CUSTOMER_CREATED, $customer);

            // No phone at registration → stay silent; CustomerAddressSaved announces this
            // customer once their first phone number arrives.
            if (($envelope['data']['customer']['phone'] ?? '') === '') {
                return;
            }

            $this->eventClient->send($envelope);
        } catch (\Throwable $e) {
            $this->logger->error('[EleAPIs_Connector] customer.created observer failed: ' . $e->getMessage());
        }
    }
}
