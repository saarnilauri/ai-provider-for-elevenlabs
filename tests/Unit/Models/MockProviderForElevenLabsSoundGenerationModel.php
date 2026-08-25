<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Models;

use AiProviderForElevenLabs\Models\ProviderForElevenLabsSoundGenerationModel;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForElevenLabsSoundGenerationModel.
 */
class MockProviderForElevenLabsSoundGenerationModel extends ProviderForElevenLabsSoundGenerationModel
{
    /**
     * Constructor.
     *
     * @param ModelMetadata                  $metadata              The model metadata.
     * @param ProviderMetadata               $providerMetadata      The provider metadata.
     * @param HttpTransporterInterface        $httpTransporter       The HTTP transporter.
     * @param RequestAuthenticationInterface  $requestAuthentication The request authentication.
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
     * Exposes extractPromptText for testing.
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The extracted text.
     */
    public function exposeExtractPromptText(array $prompt): string
    {
        return $this->extractPromptText($prompt);
    }

    /**
     * Exposes prepareSoundGenerationParams for testing.
     *
     * @param string $text The text prompt.
     * @return array<string, mixed> The request parameters.
     */
    public function exposePrepareSoundGenerationParams(string $text): array
    {
        return $this->prepareSoundGenerationParams($text);
    }
}
