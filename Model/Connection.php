<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Performs the one-time activation handshake with the receiving webhook.
 *
 * Called when the merchant saves the module config. The receiver verifies the HMAC and flips the
 * connection ACTIVE. Returns a human-readable outcome for the admin message.
 */
class Connection
{
    /** @var Config */
    private $config;

    /** @var Signer */
    private $signer;

    /** @var Curl */
    private $curl;

    /** @var PayloadBuilder */
    private $payloadBuilder;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param Config $config
     * @param Signer $signer
     * @param Curl $curl
     * @param PayloadBuilder $payloadBuilder
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Signer $signer,
        Curl $curl,
        PayloadBuilder $payloadBuilder,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->signer = $signer;
        $this->curl = $curl;
        $this->payloadBuilder = $payloadBuilder;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Runs the signed activation handshake against the configured webhook.
     *
     * @return array{ok: bool, message: string}
     */
    public function activate(): array
    {
        if (!$this->config->isReady()) {
            return [
                'ok' => false,
                'message' => (string) __('Enter the Webhook URL and Webhook Secret, then set Enable = Yes.'),
            ];
        }

        try {
            $timestamp = (string) time();
            $signature = $this->signer->sign(
                $this->config->getHmacSecret(),
                $timestamp,
                $this->config->getBotApiKey()
            );
            $body = json_encode(
                ['store' => $this->resolveStoreMeta()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $url = rtrim($this->config->getWebhookUrl(), '/') . '/activate';

            $this->curl->setOptions([
                CURLOPT_TIMEOUT => Config::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => Config::CONNECT_TIMEOUT,
            ]);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader(Config::SIGNATURE_HEADER, $signature);
            $this->curl->addHeader(Config::TIMESTAMP_HEADER, $timestamp);
            $this->curl->post($url, (string) $body);

            $status = (int) $this->curl->getStatus();

            if ($status >= 200 && $status < 300) {
                return [
                    'ok' => true,
                    'message' => (string) __(
                        'Your store is connected. Open your dashboard to set up your WhatsApp automations.'
                    ),
                ];
            }

            $decoded = json_decode((string) $this->curl->getBody(), true);
            $reason = is_array($decoded) && isset($decoded['message']) && $decoded['message'] !== ''
                ? (string) $decoded['message']
                : ('HTTP ' . $status);

            return ['ok' => false, 'message' => (string) __('Activation failed: %1', $reason)];
        } catch (\Throwable $e) {
            $this->logger->error('[EleAPIs_Connector] activation failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => (string) __('Could not reach the server: %1', $e->getMessage())];
        }
    }

    /**
     * Store metadata for the default store view, sent with the handshake.
     *
     * @return array
     */
    private function resolveStoreMeta(): array
    {
        $storeId = 0;

        try {
            $store = $this->storeManager->getDefaultStoreView();
            $storeId = $store ? (int) $store->getId() : 0;
        } catch (\Throwable $e) {
            $storeId = 0;
        }

        return $this->payloadBuilder->buildStoreMeta($storeId);
    }
}
