<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Provider;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Class to check availability for the ElevenLabs provider.
 *
 * Checks whether the ELEVENLABS_API_KEY environment variable or constant
 * is set. This avoids calling the /models endpoint (which requires the
 * models_read permission) just to verify configuration.
 *
 * @since 0.1.0
 */
class ElevenLabsProviderAvailability implements ProviderAvailabilityInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function isConfigured(): bool
    {
        $apiKey = getenv('ELEVENLABS_API_KEY');
        if ($apiKey !== false && $apiKey !== '') {
            return true;
        }

        if (defined('ELEVENLABS_API_KEY')) {
            $value = constant('ELEVENLABS_API_KEY');
            return is_string($value) && $value !== '';
        }

        return false;
    }
}
