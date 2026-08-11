<?php
/**
 * Copyright © EleAPIs. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace EleAPIs\Connector\Model;

/**
 * Builds the HMAC signature the receiving side verifies.
 *
 * Canonical string: "{version}:{timestamp}:{botApiKey}" — brand-neutral and independent of the
 * request body (the body is not signed), so it survives the webhook → queue → automation hops.
 */
class Signer
{
    /**
     * Signs the canonical payload and returns the versioned signature header value.
     *
     * @param string $secret
     * @param string $timestamp
     * @param string $botApiKey
     * @return string Signature header value, e.g. "v1=<hex hmac-sha256>"
     */
    public function sign(string $secret, string $timestamp, string $botApiKey): string
    {
        $signedPayload = Config::SIGNATURE_VERSION . ':' . $timestamp . ':' . $botApiKey;

        return Config::SIGNATURE_VERSION . '=' . hash_hmac('sha256', $signedPayload, $secret);
    }
}
