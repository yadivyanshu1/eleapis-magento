<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Model;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * Sends a signed event envelope to the configured webhook URL.
 *
 * Fail-safe by design: it never throws to the caller, so a slow or failing endpoint can never
 * disrupt checkout / order save.
 */
class EventClient
{
    /** @var Config */
    private $config;

    /** @var Signer */
    private $signer;

    /** @var Curl */
    private $curl;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param Config $config
     * @param Signer $signer
     * @param Curl $curl
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Signer $signer,
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->signer = $signer;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    /**
     * Signs and POSTs the envelope to the configured webhook. Never throws.
     *
     * @param array $envelope
     * @return void
     */
    public function send(array $envelope): void
    {
        try {
            if (!$this->config->isReady()) {
                return;
            }

            $timestamp = (string) time();
            $signature = $this->signer->sign(
                $this->config->getHmacSecret(),
                $timestamp,
                $this->config->getBotApiKey()
            );
            $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->curl->setOptions([
                CURLOPT_TIMEOUT => Config::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => Config::CONNECT_TIMEOUT,
            ]);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader(Config::SIGNATURE_HEADER, $signature);
            $this->curl->addHeader(Config::TIMESTAMP_HEADER, $timestamp);
            $this->curl->addHeader(Config::DELIVERY_ID_HEADER, (string) ($envelope['deliveryId'] ?? ''));
            $this->curl->post($this->config->getWebhookUrl(), (string) $body);

            $this->logOutcome($envelope);
        } catch (\Throwable $e) {
            $this->logger->error('[EleAPIs_Connector] event delivery failed: ' . $e->getMessage());
        }
    }

    /**
     * Logs every delivery outcome: non-2xx as error, accepted as debug.
     *
     * @param array $envelope
     * @return void
     */
    private function logOutcome(array $envelope): void
    {
        $status = (int) $this->curl->getStatus();
        $event = (string) ($envelope['event'] ?? '');
        $deliveryId = (string) ($envelope['deliveryId'] ?? '');

        if ($status < 200 || $status >= 300) {
            $responseSnippet = substr((string) $this->curl->getBody(), 0, 200);
            $this->logger->error(
                '[EleAPIs_Connector] event ' . $event . ' (deliveryId ' . $deliveryId . ') rejected with HTTP '
                . $status . ': ' . $responseSnippet
            );

            return;
        }

        $this->logger->debug(
            '[EleAPIs_Connector] event ' . $event . ' (deliveryId ' . $deliveryId . ') delivered, HTTP ' . $status
        );
    }
}
