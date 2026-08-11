<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Cron;

use EleAPIs\Connector\Model\Config;
use EleAPIs\Connector\Model\EventClient;
use EleAPIs\Connector\Model\PayloadBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Emits one "cart.abandoned" event per abandonment episode of a stale active quote.
 *
 * Magento has no native abandoned-cart event, so this runs on cron: it finds active quotes with
 * items, a known customer email and no activity for a configured window, emits the event, then
 * stamps the quote row. A quote is eligible when it was never notified OR when it changed after
 * the last notification (logged-in customers reuse one quote long-term, so a returning customer
 * who abandons again re-arms naturally) — while an untouched cart is never re-notified.
 * Runs entirely inside the merchant's Magento — no polling of our infrastructure.
 */
class AbandonedCartScanner
{
    /** @var Config */
    private $config;

    /** @var EventClient */
    private $eventClient;

    /** @var PayloadBuilder */
    private $payloadBuilder;

    /** @var CollectionFactory */
    private $quoteCollectionFactory;

    /** @var CartRepositoryInterface */
    private $cartRepository;

    /** @var ResourceConnection */
    private $resource;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param Config $config
     * @param EventClient $eventClient
     * @param PayloadBuilder $payloadBuilder
     * @param CollectionFactory $quoteCollectionFactory
     * @param CartRepositoryInterface $cartRepository
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        EventClient $eventClient,
        PayloadBuilder $payloadBuilder,
        CollectionFactory $quoteCollectionFactory,
        CartRepositoryInterface $cartRepository,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->eventClient = $eventClient;
        $this->payloadBuilder = $payloadBuilder;
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->cartRepository = $cartRepository;
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * Scans for stale active quotes and emits cart.abandoned once per quote. Never throws.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isReady()) {
            return;
        }

        try {
            $threshold = gmdate('Y-m-d H:i:s', time() - (Config::ABANDONED_CART_DELAY_MINUTES * 60));

            $collection = $this->quoteCollectionFactory->create();
            $collection->addFieldToFilter('is_active', '1')
                ->addFieldToFilter('items_count', ['gt' => 0])
                ->addFieldToFilter('customer_email', ['notnull' => true])
                ->addFieldToFilter('updated_at', ['lteq' => $threshold]);
            // Never notified, OR modified after the last notification (a new abandonment episode).
            // Column-to-column comparison, so it goes on the select directly.
            $collection->getSelect()->where(sprintf(
                'main_table.%1$s IS NULL OR main_table.%1$s < main_table.updated_at',
                Config::QUOTE_ABANDONED_FLAG
            ));
            $collection->setPageSize(Config::ABANDONED_CART_BATCH_SIZE);

            foreach ($collection as $quoteRow) {
                $this->process((int) $quoteRow->getId());
            }
        } catch (\Throwable $e) {
            $this->logger->error('[EleAPIs_Connector] abandoned-cart scan failed: ' . $e->getMessage());
        }
    }

    /**
     * Emits the event for one quote and flags it as notified.
     *
     * @param int $quoteId
     * @return void
     */
    private function process(int $quoteId): void
    {
        try {
            $quote = $this->cartRepository->get($quoteId);

            if (!$quote instanceof Quote) {
                return;
            }

            $this->eventClient->send(
                $this->payloadBuilder->buildCartEnvelope(Config::EVENT_CART_ABANDONED, $quote)
            );

            $this->markSent($quoteId);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[EleAPIs_Connector] abandoned-cart quote ' . $quoteId . ' failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Sets the one-time notified flag on the quote row.
     *
     * A targeted parameterized UPDATE is used deliberately instead of CartRepository::save():
     * saving the quote would bump `updated_at` (breaking this scanner's own staleness filter)
     * and fire quote save events/plugins for what is only a bookkeeping flag.
     *
     * @param int $quoteId
     * @return void
     */
    private function markSent(int $quoteId): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName('quote'),
            [Config::QUOTE_ABANDONED_FLAG => gmdate('Y-m-d H:i:s')],
            ['entity_id = ?' => $quoteId]
        );
    }
}
