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
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Emits an order update event only when the order status actually changes.
 *
 * Brand-new orders (empty original status) are skipped — those are handled by OrderPlaced —
 * so a placed order does not double-fire created + updated.
 *
 * The whole body is guarded: a failure to build or deliver the event must never
 * disturb order save.
 */
class OrderSavedAfter implements ObserverInterface
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
     * Emits updated/canceled/refunded on a real status transition. Never throws into order save.
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

            if (!$order instanceof Order || !$order->getEntityId()) {
                return;
            }

            $originalStatus = (string) $order->getOrigData('status');
            $currentStatus = (string) $order->getStatus();

            // Skip the brand-new order's first save (empty original status) — OrderPlaced already
            // emitted order.created for it — and no-op saves. Only real status transitions emit here.
            if ($originalStatus === '' || $originalStatus === $currentStatus) {
                return;
            }

            $this->eventClient->send(
                $this->payloadBuilder->buildOrderEnvelope($this->resolveEvent($order), $order)
            );
        } catch (\Throwable $e) {
            $this->logger->error('[EleAPIs_Connector] order update observer failed: ' . $e->getMessage());
        }
    }

    /**
     * Maps the order state after the transition to the emitted event name.
     *
     * @param Order $order
     * @return string
     */
    private function resolveEvent(Order $order): string
    {
        $state = (string) $order->getState();

        if ($state === Order::STATE_CANCELED) {
            return Config::EVENT_ORDER_CANCELED;
        }

        if ($state === Order::STATE_CLOSED) {
            return Config::EVENT_ORDER_REFUNDED;
        }

        return Config::EVENT_ORDER_UPDATED;
    }
}
