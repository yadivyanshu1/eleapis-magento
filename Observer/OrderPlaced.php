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
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Emits an "order.created" event when an order is placed.
 *
 * The whole body is guarded: a failure to build or deliver the event must never
 * disturb order placement.
 */
class OrderPlaced implements ObserverInterface
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
     * Builds and sends the order.created envelope. Never throws into the checkout flow.
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

            $order = $observer->getEvent()->getData('order');

            // sales_order_place_after fires before the order is persisted, so the entity id is not
            // assigned yet. The increment id IS reserved by this point and is the stable, universally
            // available identifier (also present on save_after), so the whole pipeline keys on it.
            if (!$order instanceof OrderInterface || !$order->getIncrementId()) {
                return;
            }

            $this->eventClient->send(
                $this->payloadBuilder->buildOrderEnvelope(Config::EVENT_ORDER_CREATED, $order)
            );
        } catch (\Throwable $e) {
            $this->logger->error('[EleAPIs_Connector] order.created observer failed: ' . $e->getMessage());
        }
    }
}
