<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Single source of truth for the module's brand, wire-protocol and environment constants.
 */
class Config
{
    /** Kept in sync with composer.json. */
    public const MODULE_VERSION = '1.0.0';

    /** Brand (display only). */
    public const BRAND_NAME = 'EleAPIs';

    /** Wire protocol, shared with the receiving webhook. */
    public const SIGNATURE_HEADER = 'X-Signature';
    public const TIMESTAMP_HEADER = 'X-Timestamp';
    public const DELIVERY_ID_HEADER = 'X-Delivery-Id';
    public const SIGNATURE_VERSION = 'v1';
    public const SPEC_VERSION = '1.0';

    /** Supported events (orders, customer, abandoned cart). */
    public const EVENT_ORDER_CREATED = 'order.created';
    public const EVENT_ORDER_UPDATED = 'order.updated';
    public const EVENT_ORDER_CANCELED = 'order.canceled';
    public const EVENT_ORDER_REFUNDED = 'order.refunded';
    public const EVENT_CUSTOMER_CREATED = 'customer.created';
    public const EVENT_CART_ABANDONED = 'cart.abandoned';

    /** Outbound HTTP timeouts (seconds). */
    public const REQUEST_TIMEOUT = 10;
    public const CONNECT_TIMEOUT = 5;

    /** Abandoned-cart cron tuning (edit here). */
    public const ABANDONED_CART_DELAY_MINUTES = 30;
    public const ABANDONED_CART_BATCH_SIZE = 50;

    /** Quote column flag set once a cart.abandoned event has been emitted (see etc/db_schema.xml). */
    public const QUOTE_ABANDONED_FLAG = 'ele_abandoned_sent_at';

    /** Admin system-config paths (section/group/field). */
    public const XML_PATH_ENABLED = 'eleapis_connector/general/enabled';
    public const XML_PATH_WEBHOOK_URL = 'eleapis_connector/general/webhook_url';
    public const XML_PATH_HMAC_SECRET = 'eleapis_connector/general/hmac_secret';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var EncryptorInterface */
    private $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the merchant has switched the connector on.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * The webhook endpoint pasted from the dashboard.
     *
     * @return string
     */
    public function getWebhookUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_WEBHOOK_URL));
    }

    /**
     * The signing secret pasted from the dashboard (stored encrypted at rest).
     *
     * @return string
     */
    public function getHmacSecret(): string
    {
        $stored = (string) $this->scopeConfig->getValue(self::XML_PATH_HMAC_SECRET);

        return $stored !== '' ? (string) $this->encryptor->decrypt($stored) : '';
    }

    /**
     * The bot API key is the last path segment of the webhook URL
     * (…/custom-app/magento/{botApiKey}) and is the value signed with the HMAC secret.
     *
     * @return string
     */
    public function getBotApiKey(): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- parsing a merchant-pasted URL string, no request context involved.
        $path = (string) parse_url($this->getWebhookUrl(), PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), static function ($part) {
            return $part !== '';
        }));

        return $segments !== [] ? (string) end($segments) : '';
    }

    /**
     * Enabled and fully configured.
     *
     * @return bool
     */
    public function isReady(): bool
    {
        return $this->isEnabled()
            && $this->getWebhookUrl() !== ''
            && $this->getHmacSecret() !== ''
            && $this->getBotApiKey() !== '';
    }
}
