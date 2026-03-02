<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Provider;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * Class for HTTP request authentication using the ElevenLabs xi-api-key header.
 *
 * ElevenLabs uses a custom `xi-api-key` header instead of the standard
 * `Authorization: Bearer` header for API authentication.
 *
 * This class extends {@see ApiKeyRequestAuthentication} so that it passes the
 * `instanceof` check performed by the provider registry when validating
 * authentication method implementations.
 *
 * @since 0.1.0
 */
class ElevenLabsApiKeyAuthentication extends ApiKeyRequestAuthentication
{
    /**
     * {@inheritDoc}
     *
     * Overrides the parent to use the `xi-api-key` header instead of
     * the `Authorization: Bearer` header.
     *
     * @since 0.1.0
     */
    public function authenticateRequest(Request $request): Request
    {
        return $request->withHeader('xi-api-key', $this->getApiKey());
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public static function fromArray(array $array): self
    {
        static::validateFromArrayData($array, [self::KEY_API_KEY]);

        return new self($array[self::KEY_API_KEY]);
    }
}
