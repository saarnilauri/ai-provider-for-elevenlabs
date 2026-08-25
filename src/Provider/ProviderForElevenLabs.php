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
 * The connector metadata (name, description, logo) and the voice directory
 * dependency-retry logic were contributed by Jake Spurlock
 * (https://github.com/whyisjake/ai-provider-for-elevenlabs).
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
     * @var bool Whether the voice directory has its transporter and authentication attached.
     */
    private static bool $voiceDirectoryReady = false;

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
        // This is the provider's label in the connector UI, so it names the
        // service rather than the plugin. The function_exists() guard keeps the
        // class usable as a plain Composer package outside WordPress, and the
        // literal is repeated so that i18n tooling can extract the string.
        $description = 'Text-to-speech and sound effects with ElevenLabs.';
        if (function_exists('__')) {
            $translated = __('Text-to-speech and sound effects with ElevenLabs.', 'ai-provider-for-elevenlabs');
            if (is_string($translated)) {
                $description = $translated;
            }
        }

        return new ProviderMetadata(
            'elevenlabs',
            'ElevenLabs',
            ProviderTypeEnum::cloud(),
            'https://elevenlabs.io/app/settings/api-keys',
            RequestAuthenticationMethod::apiKey(),
            $description,
            self::logoPath()
        );
    }

    /**
     * Resolves the provider logo path, when the asset is present.
     *
     * @since 0.4.0
     *
     * @return string|null The absolute path to the logo, or null if it is not bundled.
     */
    private static function logoPath(): ?string
    {
        $logoPath = dirname(__DIR__, 2) . '/assets/images/elevenlabs.svg';

        return file_exists($logoPath) ? $logoPath : null;
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
     * The voice directory provides access to the ElevenLabs voices endpoint for
     * listing and discovering available voices. The instance is lazy-initialized
     * and shares the HTTP transporter and authentication with the model metadata
     * directory.
     *
     * The transporter and authentication are re-applied on every call until both
     * are actually attached. They are frequently unavailable the first time this
     * runs, and an instance built without them can never recover on its own --
     * {@see VoiceDirectory::getVoices()} would throw for the rest of the request
     * even after credentials became available.
     *
     * @since 0.1.0
     *
     * @return VoiceDirectory The voice directory instance.
     */
    public static function getVoiceDirectory(): VoiceDirectory
    {
        if (self::$voiceDirectory === null) {
            self::$voiceDirectory = new VoiceDirectory();
        }

        if (!self::$voiceDirectoryReady) {
            self::$voiceDirectoryReady = self::attachDependencies(self::$voiceDirectory);
        }

        return self::$voiceDirectory;
    }

    /**
     * Attaches the shared HTTP transporter and authentication to the voice directory.
     *
     * @since 0.4.0
     *
     * @param VoiceDirectory $voiceDirectory The voice directory to configure.
     * @return bool True once both dependencies are attached, false otherwise.
     */
    private static function attachDependencies(VoiceDirectory $voiceDirectory): bool
    {
        $modelMetadataDirectory = static::modelMetadataDirectory();

        $hasTransporter = false;
        if ($modelMetadataDirectory instanceof WithHttpTransporterInterface) {
            try {
                $voiceDirectory->setHttpTransporter($modelMetadataDirectory->getHttpTransporter());
                $hasTransporter = true;
            } catch (RuntimeException $e) {
                // Not available yet; retried on the next call.
            }
        }

        $hasAuthentication = false;
        if ($modelMetadataDirectory instanceof WithRequestAuthenticationInterface) {
            try {
                $voiceDirectory->setRequestAuthentication($modelMetadataDirectory->getRequestAuthentication());
                $hasAuthentication = true;
            } catch (RuntimeException $e) {
                // Not available yet; retried on the next call.
            }
        }

        return $hasTransporter && $hasAuthentication;
    }
}
