<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Voices;

use AiProviderForElevenLabs\Voices\VoiceDirectory;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;

/**
 * Mock class for testing VoiceDirectory.
 *
 * Accepts transporter and authentication via constructor so that
 * they can be injected in unit tests without relying on a provider.
 */
class MockVoiceDirectory extends VoiceDirectory
{
    /**
     * Constructor.
     *
     * @param string                          $baseUrl               The API base URL.
     * @param HttpTransporterInterface         $httpTransporter       The HTTP transporter.
     * @param RequestAuthenticationInterface   $requestAuthentication The request authentication.
     */
    public function __construct(
        string $baseUrl,
        HttpTransporterInterface $httpTransporter,
        RequestAuthenticationInterface $requestAuthentication
    ) {
        parent::__construct($baseUrl);

        $this->setHttpTransporter($httpTransporter);
        $this->setRequestAuthentication($requestAuthentication);
    }

    /**
     * Exposes mapVoiceData for testing.
     *
     * @param array<string, mixed> $voiceData Raw voice data from the API.
     * @return array{id: string, name: string, category: string, labels: array<string, string>, description: string, preview_url: string}
     */
    public function exposeMapVoiceData(array $voiceData): array
    {
        return $this->mapVoiceData($voiceData);
    }
}
