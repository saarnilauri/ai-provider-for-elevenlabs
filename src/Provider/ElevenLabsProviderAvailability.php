<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Provider;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;

/**
 * Class to check availability for the ElevenLabs provider.
 *
 * Resolves an API key from the supported sources and then verifies it against
 * the ElevenLabs API, so that the Connectors screen reports whether the key
 * actually works rather than merely whether one was typed in.
 *
 * The other AI Client providers get this from the SDK's
 * {@see \WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability},
 * which treats any failure of the model listing as "not configured". Neither
 * half of that holds for ElevenLabs:
 *
 * - ElevenLabs API keys are permission-scoped, and `models_read` is optional.
 *   A key granted only Text to Speech is a valid, supported setup here, and
 *   {@see ProviderForElevenLabsModelMetadataDirectory} already falls back to a
 *   built-in model list for exactly that case. Treating the resulting 401 as a
 *   bad key would report a working setup as broken.
 * - That same fallback means the model listing never throws, so the SDK class
 *   would report every configuration as valid, including one with no key.
 *
 * ElevenLabs distinguishes the two failures in the response body, so this class
 * makes the request itself and reads `detail.status`: `invalid_api_key` is a
 * bad key, `missing_permissions` is a good key without `models_read`.
 *
 * @since 0.1.0
 */
class ElevenLabsProviderAvailability implements
    ProviderAvailabilityInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface
{
    use WithHttpTransporterTrait;
    use WithRequestAuthenticationTrait;

    /**
     * The endpoint used to verify a key.
     *
     * @since 1.0.1
     *
     * @var string
     */
    private const VERIFY_ENDPOINT = 'https://api.elevenlabs.io/v1/models';

    /**
     * Prefix for the transient holding a verification result.
     *
     * @since 1.0.1
     *
     * @var string
     */
    private const TRANSIENT_PREFIX = 'ai_provider_for_elevenlabs_key_check_';

    /**
     * How long a verification result stays cached, in seconds.
     *
     * @since 1.0.1
     *
     * @var int
     */
    private const CACHE_TTL = 15 * 60;

    /**
     * `detail.status` returned when the key itself is rejected.
     *
     * @since 1.0.1
     *
     * @var string
     */
    private const STATUS_INVALID_KEY = 'invalid_api_key';

    /**
     * `detail.status` returned when the key is genuine but lacks the scope.
     *
     * @since 1.0.1
     *
     * @var string
     */
    private const STATUS_MISSING_PERMISSIONS = 'missing_permissions';

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function isConfigured(): bool
    {
        $apiKey = $this->resolveApiKey();

        if ($apiKey === null) {
            return false;
        }

        $cached = $this->readCachedResult($apiKey);
        if ($cached !== null) {
            return $cached;
        }

        $verified = $this->verifyApiKey($apiKey);

        // A null verdict means the request could not be completed, rather than
        // that the key was rejected. Report the key as usable and cache nothing,
        // so a network blip never presents a working setup as misconfigured.
        if ($verified === null) {
            return true;
        }

        $this->writeCachedResult($apiKey, $verified);

        return $verified;
    }

    /**
     * Resolves the API key from the supported sources, in precedence order.
     *
     * The registry authentication is checked before the legacy option because
     * it is what carries the key on WordPress 7.0 and later: core reads its own
     * Connectors option and hands the key to the AI Client, and it is also what
     * core populates when validating a key typed into the Connectors screen.
     *
     * @since 1.0.1
     *
     * @return string|null The API key, or null when none is configured.
     */
    private function resolveApiKey(): ?string
    {
        $envKey = getenv('ELEVENLABS_API_KEY');
        if (is_string($envKey) && $envKey !== '') {
            return $envKey;
        }

        if (defined('ELEVENLABS_API_KEY')) {
            $value = constant('ELEVENLABS_API_KEY');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        try {
            $authentication = $this->getRequestAuthentication();
            if ($authentication instanceof ApiKeyRequestAuthentication) {
                $key = $authentication->getApiKey();
                if ($key !== '') {
                    return $key;
                }
            }
        } catch (RuntimeException $e) {
            // Authentication not set yet; fall through to the legacy option.
        }

        if (function_exists('get_option')) {
            // Legacy wp-ai-client credentials option (pre-7.0).
            $option = get_option('wp_ai_client_provider_credentials');
            if (is_array($option) && isset($option['elevenlabs']) && is_string($option['elevenlabs'])) {
                $legacyKey = $option['elevenlabs'];
                if ($legacyKey !== '') {
                    return $legacyKey;
                }
            }
        }

        return null;
    }

    /**
     * Asks ElevenLabs whether the key is accepted.
     *
     * The request is authenticated with {@see ElevenLabsApiKeyAuthentication}
     * built from the resolved key rather than with whatever authentication the
     * registry currently holds. Core sets a generic `Authorization: Bearer`
     * authentication on the registry immediately before validating a key, and
     * ElevenLabs only accepts the `xi-api-key` header, so reusing the registry
     * instance would reject every key it was asked to check.
     *
     * @since 1.0.1
     *
     * @param string $apiKey The API key to verify.
     * @return bool|null True if accepted, false if rejected, null if undetermined.
     */
    private function verifyApiKey(string $apiKey): ?bool
    {
        try {
            $request = new Request(
                HttpMethodEnum::GET(),
                self::VERIFY_ENDPOINT,
                ['Content-Type' => 'application/json']
            );

            $request = (new ElevenLabsApiKeyAuthentication($apiKey))->authenticateRequest($request);
            $response = $this->getHttpTransporter()->send($request);
        } catch (\Throwable $e) {
            // No transporter yet, or the request could not be sent.
            return null;
        }

        return $this->interpretResponse($response);
    }

    /**
     * Turns a verification response into a verdict.
     *
     * Only an outright rejection of the key counts as a failure. A 403 from an
     * IP allowlist, a rate limit, or a server error says nothing reliable about
     * the key, so those are left undetermined rather than shown to the user as
     * a bad credential.
     *
     * @since 1.0.1
     *
     * @param Response $response The verification response.
     * @return bool|null True if accepted, false if rejected, null if undetermined.
     */
    private function interpretResponse(Response $response): ?bool
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return true;
        }

        if ($statusCode !== 401) {
            return null;
        }

        $detailStatus = $this->readDetailStatus($response);

        // A scoped key without models_read is still a working key: the model
        // metadata directory falls back to its built-in list for that case.
        if ($detailStatus === self::STATUS_MISSING_PERMISSIONS) {
            return true;
        }

        if ($detailStatus === self::STATUS_INVALID_KEY) {
            return false;
        }

        // An unrecognised 401 is still an authentication failure.
        return false;
    }

    /**
     * Reads `detail.status` out of an ElevenLabs error response.
     *
     * @since 1.0.1
     *
     * @param Response $response The response to read.
     * @return string|null The status string, or null when absent.
     */
    private function readDetailStatus(Response $response): ?string
    {
        try {
            $data = $response->getData();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($data) || !isset($data['detail']) || !is_array($data['detail'])) {
            return null;
        }

        $status = $data['detail']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * Reads a cached verification result for the given key.
     *
     * @since 1.0.1
     *
     * @param string $apiKey The API key the result belongs to.
     * @return bool|null The cached verdict, or null when nothing is cached.
     */
    private function readCachedResult(string $apiKey): ?bool
    {
        if (!function_exists('get_transient')) {
            return null;
        }

        $cached = get_transient($this->cacheKey($apiKey));

        if ($cached === '1') {
            return true;
        }

        if ($cached === '0') {
            return false;
        }

        return null;
    }

    /**
     * Caches a verification result for the given key.
     *
     * @since 1.0.1
     *
     * @param string $apiKey   The API key the result belongs to.
     * @param bool   $verified The verdict to cache.
     * @return void
     */
    private function writeCachedResult(string $apiKey, bool $verified): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient($this->cacheKey($apiKey), $verified ? '1' : '0', self::CACHE_TTL);
    }

    /**
     * Builds the transient name for a key.
     *
     * The key is hashed rather than stored, so the credential never becomes
     * part of an option name, and changing the key invalidates the cache on its
     * own instead of needing an explicit flush.
     *
     * @since 1.0.1
     *
     * @param string $apiKey The API key to fingerprint.
     * @return string The transient name.
     */
    private function cacheKey(string $apiKey): string
    {
        return self::TRANSIENT_PREFIX . substr(hash('sha256', $apiKey), 0, 12);
    }
}
