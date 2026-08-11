<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Observer;

use EleAPIs\Connector\Model\Config;
use EleAPIs\Connector\Model\Connection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;

/**
 * When the module's config section is saved, run the activation handshake and surface the
 * outcome to the admin as a success / error message.
 */
class ConfigChanged implements ObserverInterface
{
    /** @var Config */
    private $config;

    /** @var Connection */
    private $connection;

    /** @var ManagerInterface */
    private $messageManager;

    /**
     * @param Config $config
     * @param Connection $connection
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        Config $config,
        Connection $connection,
        ManagerInterface $messageManager
    ) {
        $this->config = $config;
        $this->connection = $connection;
        $this->messageManager = $messageManager;
    }

    /**
     * Runs the activation handshake when the connector is enabled and config is saved.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Only attempt to connect when the merchant has the connector turned on.
        if (!$this->config->isEnabled()) {
            return;
        }

        $result = $this->connection->activate();

        if ($result['ok']) {
            $this->messageManager->addSuccessMessage($result['message']);

            return;
        }

        $this->messageManager->addErrorMessage($result['message']);
    }
}
