<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Provider;

use AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory;
use AiProviderForElevenLabs\Models\ProviderForElevenLabsSoundGenerationModel;
use AiProviderForElevenLabs\Models\ProviderForElevenLabsTextToSpeechModel;
use AiProviderForElevenLabs\Voices\VoiceDirectory;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the WordPress AI Client provider for ElevenLabs.
 *
 * @since 0.1.0
 */
class ProviderForElevenLabs extends AbstractApiProvider
{
    /**
     * @var VoiceDirectory|null Lazy-initialized voice directory instance.
     */
    private static ?VoiceDirectory $voiceDirectory = null;

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function baseUrl(): string
    {
        return 'https://api.elevenlabs.io/v1';
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isSpeechGeneration()) {
                return new ProviderForElevenLabsSoundGenerationModel($modelMetadata, $providerMetadata);
            }
        }
        foreach ($capabilities as $capability) {
            if ($capability->isTextToSpeechConversion()) {
                return new ProviderForElevenLabsTextToSpeechModel($modelMetadata, $providerMetadata);
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'elevenlabs',
            'AI Provider for ElevenLabs',
            ProviderTypeEnum::cloud(),
            'https://elevenlabs.io/app/settings/api-keys',
            RequestAuthenticationMethod::apiKey()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ElevenLabsProviderAvailability();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ProviderForElevenLabsModelMetadataDirectory();
    }

    /**
     * Gets the voice directory instance.
     *
     * The voice directory provides access to the ElevenLabs /voices endpoint
     * for listing and discovering available voices. The instance is
     * lazy-initialized and shares the HTTP transporter and authentication
     * with the model metadata directory.
     *
     * @since 0.1.0
     *
     * @return VoiceDirectory The voice directory instance.
     */
    public static function getVoiceDirectory(): VoiceDirectory
    {
        if (self::$voiceDirectory === null) {
            $modelMetadataDirectory = static::modelMetadataDirectory();

            self::$voiceDirectory = new VoiceDirectory();

            if ($modelMetadataDirectory instanceof WithHttpTransporterInterface) {
                try {
                    self::$voiceDirectory->setHttpTransporter($modelMetadataDirectory->getHttpTransporter());
                } catch (RuntimeException $e) {
                    // HTTP transporter not yet set, will be set later.
                }
            }

            if ($modelMetadataDirectory instanceof WithRequestAuthenticationInterface) {
                try {
                    $auth = $modelMetadataDirectory->getRequestAuthentication();
                    self::$voiceDirectory->setRequestAuthentication($auth);
                } catch (RuntimeException $e) {
                    // Request authentication not yet set, will be set later.
                }
            }
        }

        return self::$voiceDirectory;
    }
}
