<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Models;

use AiProviderForElevenLabs\Models\ProviderForElevenLabsTextToSpeechModel;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForElevenLabsTextToSpeechModel.
 */
class MockProviderForElevenLabsTextToSpeechModel extends ProviderForElevenLabsTextToSpeechModel
{
    /**
     * Constructor.
     *
     * @param ModelMetadata $metadata
     * @param ProviderMetadata $providerMetadata
     * @param HttpTransporterInterface $httpTransporter
     * @param RequestAuthenticationInterface $requestAuthentication
     */
    public function __construct(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata,
        HttpTransporterInterface $httpTransporter,
        RequestAuthenticationInterface $requestAuthentication
    ) {
        parent::__construct($metadata, $providerMetadata);

        $this->setHttpTransporter($httpTransporter);
        $this->setRequestAuthentication($requestAuthentication);
    }

    /**
     * Exposes extractTextFromPrompt for testing.
     *
     * @param list<Message> $messages
     * @return string
     */
    public function exposeExtractTextFromPrompt(array $messages): string
    {
        return $this->extractTextFromPrompt($messages);
    }

    /**
     * Exposes resolveVoiceSettings for testing.
     *
     * @return array<string, mixed>
     */
    public function exposeResolveVoiceSettings(): array
    {
        return $this->resolveVoiceSettings();
    }

    /**
     * Exposes resolveOutputFormat for testing.
     *
     * @return string
     */
    public function exposeResolveOutputFormat(): string
    {
        return $this->resolveOutputFormat();
    }

    /**
     * Exposes resolveMimeTypeFromFormat for testing.
     *
     * @param string $outputFormat
     * @return string
     */
    public function exposeResolveMimeTypeFromFormat(string $outputFormat): string
    {
        return $this->resolveMimeTypeFromFormat($outputFormat);
    }
}
