<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Voices;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;

/**
 * Directory for listing and retrieving ElevenLabs voices.
 *
 * Fetches voice data from the ElevenLabs `/v2/voices` API endpoint and provides
 * methods to list, filter, and look up voices by ID.
 *
 * The `/v1/voices` endpoint this class originally used is deprecated by
 * ElevenLabs and stops working entirely once a workspace holds more than 500
 * voices. `/v2/voices` replaces it, but is paginated and defaults to a page
 * size of 10, so {@see VoiceDirectory::fetchAllVoices()} walks every page.
 *
 * Results are memoized per instance and, when running inside WordPress, cached
 * in a transient keyed by API key so that separate credentials never share a
 * cached voice list.
 *
 * The `/v2` migration, pagination, per-key caching, and default voice
 * resolution were contributed by Jake Spurlock
 * (https://github.com/whyisjake/ai-provider-for-elevenlabs).
 *
 * @link https://elevenlabs.io/docs/api-reference/voices/search
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
     * The default ElevenLabs API base URL.
     *
     * @var string
     */
    private const DEFAULT_BASE_URL = 'https://api.elevenlabs.io/v1';

    /**
     * Number of voices to request per page.
     *
     * 100 is the maximum the ElevenLabs API accepts.
     *
     * @var int
     */
    private const PAGE_SIZE = 100;

    /**
     * Hard ceiling on pages walked, guarding against a pagination loop.
     *
     * @var int
     */
    private const MAX_PAGES = 100;

    /**
     * Prefix for the WordPress transient holding the cached voice list.
     *
     * @var string
     */
    private const TRANSIENT_PREFIX = 'ai_provider_elevenlabs_voices_';

    /**
     * Lifetime of the cached voice list, in seconds.
     *
     * @var int
     */
    private const CACHE_TTL = 900;

    /**
     * The ElevenLabs API base URL.
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Memoized voice list for this instance.
     *
     * @var array<string, VoiceData>|null
     */
    private ?array $voices = null;

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
     * @param bool $forceRefresh Whether to bypass the memoized and cached lists.
     * @return array<string, VoiceData> Voices keyed by voice ID.
     */
    public function getVoices(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            if ($this->voices !== null) {
                return $this->voices;
            }

            $cached = $this->readCachedVoices();
            if ($cached !== null) {
                $this->voices = $cached;

                return $cached;
            }
        }

        $voices = $this->fetchAllVoices();

        $this->voices = $voices;
        $this->writeCachedVoices($voices);

        return $voices;
    }

    /**
     * Get a single voice by ID.
     *
     * Reads from the memoized list rather than the single-voice endpoint, since
     * the full list is already needed for voice selection and default
     * resolution, and is fetched at most once per request.
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
     * Determines a sensible default voice for the current account.
     *
     * ElevenLabs requires a voice ID in the text-to-speech request path, so a
     * caller that has not chosen one still needs a usable voice. Premade voices
     * are preferred because they are available to every account; an account
     * holding only cloned voices falls back to the first voice it has.
     *
     * @since 0.4.0
     *
     * @return string|null The default voice ID, or null if the account has no voices.
     */
    public function getDefaultVoiceId(): ?string
    {
        $voices = $this->getVoices();

        foreach ($voices as $voice) {
            if ($voice['category'] === 'premade') {
                return $voice['id'];
            }
        }

        $first = reset($voices);

        return $first === false ? null : $first['id'];
    }

    /**
     * Clears the memoized and cached voice lists.
     *
     * @since 0.4.0
     *
     * @return void
     */
    public function flushCache(): void
    {
        $this->voices = null;

        if (function_exists('delete_transient')) {
            delete_transient($this->cacheKey());
        }
    }

    /**
     * Fetches every page of the voice list from the API.
     *
     * @since 0.4.0
     *
     * @return array<string, VoiceData> Voices keyed by voice ID.
     */
    protected function fetchAllVoices(): array
    {
        $endpoint = $this->voicesEndpoint();
        $voices = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['page_size' => (string) self::PAGE_SIZE];
            if ($pageToken !== null) {
                $query['next_page_token'] = $pageToken;
            }

            $request = new Request(
                HttpMethodEnum::GET(),
                $endpoint . '?' . http_build_query($query),
                ['Content-Type' => 'application/json']
            );

            $request = $this->getRequestAuthentication()->authenticateRequest($request);
            $response = $this->getHttpTransporter()->send($request);

            ResponseUtil::throwIfNotSuccessful($response);

            $data = $response->getData();

            if ($data === null || !isset($data['voices']) || !is_array($data['voices'])) {
                break;
            }

            foreach ($data['voices'] as $voiceData) {
                if (!is_array($voiceData) || !isset($voiceData['voice_id'])) {
                    continue;
                }

                /** @var array<string, mixed> $voiceData */
                $mapped = $this->mapVoiceData($voiceData);
                $voices[$mapped['id']] = $mapped;
            }

            if (empty($data['has_more'])) {
                break;
            }

            $nextToken = isset($data['next_page_token']) && is_string($data['next_page_token'])
                ? $data['next_page_token']
                : '';

            // Stop if the API claims more pages but does not advance the cursor.
            if ($nextToken === '' || $nextToken === $pageToken) {
                break;
            }

            $pageToken = $nextToken;
        }

        return $voices;
    }

    /**
     * Builds the voices endpoint URL.
     *
     * Voices moved to `/v2` while the rest of the provider still targets `/v1`,
     * so the version segment is swapped rather than tracked as a second base URL.
     *
     * @since 0.4.0
     *
     * @return string The fully qualified voices endpoint.
     */
    protected function voicesEndpoint(): string
    {
        $base = rtrim($this->baseUrl, '/');
        $versioned = preg_replace('#/v\d+$#', '/v2', $base);

        return ($versioned ?? $base) . '/voices';
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

    /**
     * Reads the cached voice list, when running inside WordPress.
     *
     * @since 0.4.0
     *
     * @return array<string, VoiceData>|null The cached voices, or null when absent.
     */
    private function readCachedVoices(): ?array
    {
        if (!function_exists('get_transient')) {
            return null;
        }

        $cached = get_transient($this->cacheKey());

        /** @var array<string, VoiceData>|null $result */
        $result = is_array($cached) ? $cached : null;

        return $result;
    }

    /**
     * Writes the voice list to the cache, when running inside WordPress.
     *
     * @since 0.4.0
     *
     * @param array<string, VoiceData> $voices The voices to cache.
     * @return void
     */
    private function writeCachedVoices(array $voices): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        set_transient($this->cacheKey(), $voices, self::CACHE_TTL);
    }

    /**
     * Builds the cache key for the current credentials.
     *
     * The API key is fingerprinted into the key so that two sites, or two keys
     * on one site, never read each other's cached voice list.
     *
     * @since 0.4.0
     *
     * @return string The transient key.
     */
    private function cacheKey(): string
    {
        $fingerprint = 'default';

        try {
            $authentication = $this->getRequestAuthentication();
            if ($authentication instanceof ApiKeyRequestAuthentication) {
                $fingerprint = substr(hash('sha256', $authentication->getApiKey()), 0, 12);
            }
        } catch (RuntimeException $e) {
            // Authentication not set yet; fall back to the shared key.
        }

        return self::TRANSIENT_PREFIX . $fingerprint;
    }
}
