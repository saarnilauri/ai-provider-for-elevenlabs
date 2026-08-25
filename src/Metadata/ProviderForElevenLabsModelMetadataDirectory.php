<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Metadata;

use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use Exception;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Class for the model metadata directory used by the provider for ElevenLabs.
 *
 * @since 0.1.0
 *
 * @phpstan-type ModelData array{
 *     model_id: string,
 *     name?: string|null,
 *     can_do_text_to_speech?: bool,
 *     can_do_voice_conversion?: bool,
 *     can_be_finetuned?: bool,
 *     maximum_text_length_per_request?: int|null
 * }
 * @phpstan-type ModelsResponseData list<ModelData>
 */
class ProviderForElevenLabsModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Hardcoded sound generation model ID.
     *
     * The ElevenLabs sound generation endpoint does not require a model ID,
     * so this is registered as a synthetic entry in the metadata directory.
     *
     * Note that this entry is advertised under `CapabilityEnum::speechGeneration()`,
     * which is a stand-in: sound effects are not speech. The AI Client has no
     * sound generation capability yet, and adding one is the subject of an open
     * upstream pull request. Once that lands, this entry and
     * {@see \AiProviderForElevenLabs\Models\ProviderForElevenLabsSoundGenerationModel}
     * should move onto the real capability, and the provider's `createModel()`
     * branch should follow.
     *
     * @link https://github.com/WordPress/php-ai-client/pull/222
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const SOUND_GENERATION_MODEL_ID = 'elevenlabs-sound-generation';

    /**
     * Display name for the sound generation model.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const SOUND_GENERATION_MODEL_NAME = 'ElevenLabs Sound Generation';

    /**
     * Known ElevenLabs TTS models used as fallback when the /models endpoint
     * is inaccessible (e.g. API key lacks models_read permission).
     *
     * @since 0.1.0
     *
     * @var array<string, string> Model ID => display name.
     */
    private const FALLBACK_MODELS = [
        'eleven_v3'              => 'v3',
        'eleven_flash_v2'        => 'Flash v2',
        'eleven_flash_v2_5'      => 'Flash v2.5',
        'eleven_monolingual_v1'  => 'English v1',
        'eleven_multilingual_v1' => 'Multilingual v1',
        'eleven_multilingual_v2' => 'Multilingual v2',
        'eleven_turbo_v2'        => 'Turbo v2',
        'eleven_turbo_v2_5'      => 'Turbo v2.5',
    ];

    /**
     * Maximum characters accepted per text-to-speech request, by model.
     *
     * The limit varies sharply by model and the API reports it as
     * `maximum_text_length_per_request`, so these values seed the lookup and are
     * overwritten by a live `/models` response whenever one is available. They
     * exist so that a provider which has not listed models still chunks against
     * a real limit instead of the pessimistic default.
     *
     * Values were read from a live `/v1/models` response. `eleven_monolingual_v1`
     * and `eleven_multilingual_v1` are absent deliberately: the API no longer
     * returns them, so no measured value exists and they fall through to
     * {@see self::DEFAULT_MAX_TEXT_LENGTH} rather than carrying an invented number.
     *
     * Contributed by Jake Spurlock (https://github.com/whyisjake/ai-provider-for-elevenlabs).
     *
     * @since 0.4.0
     *
     * @var array<string, int> Model ID => maximum characters per request.
     */
    private const MODEL_TEXT_LIMITS = [
        'eleven_v3'              => 5000,
        'eleven_multilingual_v2' => 10000,
        'eleven_turbo_v2'        => 30000,
        'eleven_flash_v2'        => 30000,
        'eleven_turbo_v2_5'      => 40000,
        'eleven_flash_v2_5'      => 40000,
    ];

    /**
     * Character limit assumed for a model with no known limit.
     *
     * Set to the smallest limit observed across real models, so an unknown or
     * newly released model is chunked more finely than necessary rather than
     * having an over-long request rejected by the API.
     *
     * @since 0.4.0
     *
     * @var int
     */
    public const DEFAULT_MAX_TEXT_LENGTH = 5000;

    /**
     * Per-model character limits, seeded from constants and refreshed from the API.
     *
     * @since 0.4.0
     *
     * @var array<string, int>
     */
    private array $textLimits = self::MODEL_TEXT_LIMITS;

    /**
     * Returns the maximum characters accepted in one request for a model.
     *
     * @since 0.4.0
     *
     * @param string $modelId The model identifier.
     * @return int The character limit, or the conservative default when unknown.
     */
    public function getMaxTextLength(string $modelId): int
    {
        return $this->textLimits[$modelId] ?? self::DEFAULT_MAX_TEXT_LENGTH;
    }

    /**
     * {@inheritDoc}
     *
     * Extends the base implementation to add a hardcoded sound generation model
     * entry, since the ElevenLabs /models endpoint only returns TTS models.
     * Falls back to a known model list when the /models endpoint is inaccessible.
     *
     * @since 0.1.0
     */
    protected function sendListModelsRequest(): array
    {
        try {
            $modelsMap = parent::sendListModelsRequest();
        } catch (Exception $e) {
            // Fall back to hardcoded models when /models is inaccessible
            // (e.g. API key lacks models_read permission).
            $modelsMap = $this->buildFallbackModelsMap();
        }

        $soundGenOptions = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        $modelsMap[self::SOUND_GENERATION_MODEL_ID] = new ModelMetadata(
            self::SOUND_GENERATION_MODEL_ID,
            self::SOUND_GENERATION_MODEL_NAME,
            [CapabilityEnum::speechGeneration()],
            $soundGenOptions
        );

        return $modelsMap;
    }

    /**
     * Builds a model metadata map from the hardcoded fallback models.
     *
     * @since 0.1.0
     *
     * @return array<string, ModelMetadata> Model metadata keyed by model ID.
     */
    private function buildFallbackModelsMap(): array
    {
        $ttsOptions = $this->getTtsOptions();

        $map = [];
        foreach (self::FALLBACK_MODELS as $modelId => $modelName) {
            $map[$modelId] = new ModelMetadata(
                $modelId,
                $modelName,
                [CapabilityEnum::textToSpeechConversion()],
                $ttsOptions
            );
        }

        return $map;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            ProviderForElevenLabs::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * The ElevenLabs /models endpoint returns a flat JSON array of model objects
     * (not wrapped in a "data" key like OpenAI-compatible APIs).
     *
     * @since 0.1.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData|array<string, mixed> $responseData */
        $responseData = $response->getData();

        if (!is_array($responseData) || $responseData === []) {
            throw ResponseException::fromMissingData('ElevenLabs', 'models');
        }

        // The ElevenLabs API returns a flat array of model objects.
        // If the response is wrapped in a key (e.g. future API changes), handle both formats.
        $modelsData = $responseData;
        if (isset($responseData['data']) && is_array($responseData['data'])) {
            $modelsData = $responseData['data'];
        } elseif ($responseData !== array_values($responseData)) {
            // Not a list of models. (`array_is_list()` is avoided so the package
            // runs on PHP 7.4 without WordPress providing the polyfill.)
            throw ResponseException::fromMissingData('ElevenLabs', 'models');
        }

        $ttsOptions = $this->getTtsOptions();

        /** @var list<ModelData> $modelsData */
        $ttsModelsData = array_filter(
            $modelsData,
            static function (array $modelData): bool {
                return !empty($modelData['can_do_text_to_speech']);
            }
        );

        $models = array_values(
            array_map(
                function (array $modelData) use ($ttsOptions): ModelMetadata {
                    $modelId = $modelData['model_id'];
                    $modelName = $modelData['name'] ?? $modelId;

                    /*
                     * The API is authoritative on the per-request character limit, so a live
                     * value replaces the seeded constant. ModelMetadata has nowhere to carry
                     * it, hence the separate lookup.
                     */
                    if (
                        isset($modelData['maximum_text_length_per_request'])
                        && is_int($modelData['maximum_text_length_per_request'])
                        && $modelData['maximum_text_length_per_request'] > 0
                    ) {
                        $this->textLimits[$modelId] = $modelData['maximum_text_length_per_request'];
                    }

                    return new ModelMetadata(
                        $modelId,
                        $modelName,
                        [CapabilityEnum::textToSpeechConversion()],
                        $ttsOptions
                    );
                },
                $ttsModelsData
            )
        );

        usort($models, [$this, 'modelSortCallback']);

        return $models;
    }

    /**
     * Returns the supported options shared by all ElevenLabs TTS models.
     *
     * The `outputFileType` option must be declared as `inline`, since the model
     * returns base64-encoded audio and callers (such as the WordPress AI plugin)
     * require inline output when checking text-to-speech support.
     *
     * @since 0.3.0
     *
     * @return list<SupportedOption> The supported options.
     */
    private function getTtsOptions(): array
    {
        return [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
            new SupportedOption(OptionEnum::outputSpeechVoice()),
            new SupportedOption(OptionEnum::outputMimeType()),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
            new SupportedOption(OptionEnum::customOptions()),
        ];
    }

    /**
     * Callback function for sorting models by ID, to be used with `usort()`.
     *
     * @since 0.1.0
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        return strcmp($a->getId(), $b->getId());
    }
}
