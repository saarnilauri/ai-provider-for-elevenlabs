<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Voices;

use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;

/**
 * Directory for listing and retrieving ElevenLabs voices.
 *
 * Fetches voice data from the ElevenLabs `/voices` API endpoint and
 * provides methods to list, filter, and look up voices by ID.
 *
 * @since 0.1.0
 *
 * @phpstan-type VoiceData array{
 *     id: string,
 *     name: string,
 *     category: string,
 *     labels: array<string, string>,
 *     description: string,
 *     preview_url: string
 * }
 */
class VoiceDirectory implements
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface
{
    use WithHttpTransporterTrait;
    use WithRequestAuthenticationTrait;

    /**
     * The ElevenLabs API base URL.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * The default ElevenLabs API base URL.
     *
     * @var string
     */
    private const DEFAULT_BASE_URL = 'https://api.elevenlabs.io/v1';

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param string $baseUrl The ElevenLabs API base URL.
     */
    public function __construct(string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * Get all available voices.
     *
     * @since 0.1.0
     *
     * @return array<string, VoiceData> Voices keyed by voice ID.
     */
    public function getVoices(): array
    {
        $request = new Request(
            HttpMethodEnum::GET(),
            $this->baseUrl . '/voices',
            ['Content-Type' => 'application/json']
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);

        ResponseUtil::throwIfNotSuccessful($response);

        $data = $response->getData();

        if ($data === null || !isset($data['voices']) || !is_array($data['voices'])) {
            return [];
        }

        $voices = [];
        foreach ($data['voices'] as $voiceData) {
            if (!is_array($voiceData) || !isset($voiceData['voice_id'])) {
                continue;
            }

            /** @var array<string, mixed> $voiceData */
            $mapped = $this->mapVoiceData($voiceData);
            $voices[$mapped['id']] = $mapped;
        }

        return $voices;
    }

    /**
     * Get a single voice by ID.
     *
     * @since 0.1.0
     *
     * @param string $voiceId The voice ID to look up.
     * @return VoiceData|null The voice data or null if not found.
     */
    public function getVoice(string $voiceId): ?array
    {
        $voices = $this->getVoices();

        return $voices[$voiceId] ?? null;
    }

    /**
     * Get voices filtered by category.
     *
     * @since 0.1.0
     *
     * @param string $category The category to filter by (e.g., 'premade', 'cloned', 'professional').
     * @return array<string, VoiceData> Voices matching the category, keyed by voice ID.
     */
    public function getVoicesByCategory(string $category): array
    {
        $voices = $this->getVoices();

        return array_filter(
            $voices,
            static function (array $voice) use ($category): bool {
                return $voice['category'] === $category;
            }
        );
    }

    /**
     * Maps raw ElevenLabs voice API data to the standardised voice structure.
     *
     * @since 0.1.0
     *
     * @param array<string, mixed> $voiceData Raw voice data from the API.
     * @return VoiceData The mapped voice data.
     */
    protected function mapVoiceData(array $voiceData): array
    {
        $voiceId = isset($voiceData['voice_id']) && is_string($voiceData['voice_id'])
            ? $voiceData['voice_id'] : '';
        $name = isset($voiceData['name']) && is_string($voiceData['name'])
            ? $voiceData['name'] : '';
        $category = isset($voiceData['category']) && is_string($voiceData['category'])
            ? $voiceData['category'] : '';
        $description = isset($voiceData['description']) && is_string($voiceData['description'])
            ? $voiceData['description'] : '';
        $previewUrl = isset($voiceData['preview_url']) && is_string($voiceData['preview_url'])
            ? $voiceData['preview_url'] : '';

        $rawLabels = isset($voiceData['labels']) && is_array($voiceData['labels']) ? $voiceData['labels'] : [];
        /** @var array<string, string> $labels */
        $labels = array_filter($rawLabels, 'is_string');

        return [
            'id'          => $voiceId,
            'name'        => $name,
            'category'    => $category,
            'labels'      => $labels,
            'description' => $description,
            'preview_url' => $previewUrl,
        ];
    }
}
