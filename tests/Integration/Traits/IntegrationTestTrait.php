<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Integration\Traits;

use AiProviderForElevenLabs\Provider\ElevenLabsApiKeyAuthentication;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Trait providing shared functionality for integration tests.
 *
 * This trait provides utility methods for integration tests that make
 * real API calls to ElevenLabs via the AiClient fluent API.
 */
trait IntegrationTestTrait
{
    /**
     * Skips the test if the specified environment variable is not set.
     *
     * @param string $envVar The name of the environment variable to check.
     */
    protected function requireApiKey(string $envVar): void
    {
        // Check both $_ENV (populated by symfony/dotenv) and getenv() (shell environment)
        $value = $_ENV[$envVar] ?? getenv($envVar);
        if ($value === false || $value === '' || $value === null) {
            $this->markTestSkipped("Skipping: {$envVar} environment variable is not set.");
        }
    }

    /**
     * Creates a ProviderRegistry with the ElevenLabs provider registered
     * and custom xi-api-key authentication configured.
     *
     * @return ProviderRegistry The configured registry.
     */
    protected function createElevenLabsRegistry(): ProviderRegistry
    {
        $apiKey = $_ENV['ELEVENLABS_API_KEY'] ?? getenv('ELEVENLABS_API_KEY');

        $registry = new ProviderRegistry();
        $registry->registerProvider(ProviderForElevenLabs::class);
        $registry->setProviderRequestAuthentication(
            ProviderForElevenLabs::class,
            new ElevenLabsApiKeyAuthentication((string) $apiKey)
        );

        return $registry;
    }
}
