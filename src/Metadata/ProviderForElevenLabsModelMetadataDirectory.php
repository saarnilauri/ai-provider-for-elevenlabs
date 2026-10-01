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
 *     requires_alpha_access?: bool,
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
        'eleven_v4'                => 'v4',
        'eleven_v4_turbo'          => 'v4 Turbo',
        'eleven_v3'                => 'v3',
        'eleven_v3_conversational' => 'v3 Conversational',
        'eleven_flash_v2'          => 'Flash v2',
        'eleven_flash_v2_5'        => 'Flash v2.5',
        'eleven_multilingual_v2'   => 'Multilingual v2',
        'eleven_turbo_v2'          => 'Turbo v2',
        'eleven_turbo_v2_5'        => 'Turbo v2.5',
    ];

    /**
     * Models placed first when listing, in priority order.
     *
     * The AI Client uses the first listed model that meets a prompt's
     * requirements when the caller states no model preference, so this order
     * decides the implicit default. Multilingual models lead, so a site that
     * never picks a model does not get an English-only one. Models not listed
     * here follow in alphabetical order.
     *
     * @since 1.1.0
     *
     * @var list<string>
     */
    private const MODEL_PRIORITY = [
        'eleven_v4',
        'eleven_multilingual_v2',
        'eleven_v3',
        'eleven_v4_turbo',
        'eleven_flash_v2_5',
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
        'eleven_v4'                => 10000,
        'eleven_v4_turbo'          => 10000,
        'eleven_v3'                => 5000,
        'eleven_v3_conversational' => 5000,
        'eleven_multilingual_v2'   => 10000,
        'eleven_turbo_v2'          => 30000,
        'eleven_flash_v2'          => 30000,
        'eleven_turbo_v2_5'        => 40000,
        'eleven_flash_v2_5'        => 40000,
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
     * The model list is cached for a day, and what it holds depends on the
     * default model, the allowed models and whether alpha models are included.
     * Folding those settings into the key means a changed setting is picked up
     * at once, instead of the list cached under the old settings being served
     * until it expires.
     *
     * @since 1.1.0
     */
    protected function getBaseCacheKey(): string
    {
        $settings = [
            $this->resolveDefaultModelId(),
            $this->resolveAllowedModelIds(),
            $this->includeAlphaModels(),
        ];

        return parent::getBaseCacheKey() . '_' . md5((string) json_encode($settings));
    }

    /**
     * {@inheritDoc}
     *
     * Extends the base implementation to add a hardcoded sound generation model
     * entry, since the ElevenLabs /models endpoint only returns TTS models.
     * Falls back to a known model list when the /models endpoint is inaccessible.
     * The text-to-speech models are then narrowed to the allowed models, if any
     * are configured.
     *
     * @since 0.1.0
     * @since 1.1.0 Narrows the text-to-speech models to the allowed models.
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

        $allowedModelIds = $this->resolveAllowedModelIds();
        if ($allowedModelIds !== []) {
            $allowedModelsMap = array_intersect_key($modelsMap, array_flip($allowedModelIds));

            // An allow-list naming no model the account offers would leave
            // text-to-speech with nothing to run on, so it is ignored instead.
            if ($allowedModelsMap !== []) {
                $modelsMap = $allowedModelsMap;
            }
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

        $models = [];
        foreach (self::FALLBACK_MODELS as $modelId => $modelName) {
            $models[] = new ModelMetadata(
                $modelId,
                $modelName,
                [CapabilityEnum::textToSpeechConversion()],
                $ttsOptions
            );
        }

        $map = [];
        foreach ($this->sortModels($models) as $model) {
            $map[$model->getId()] = $model;
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

        $includeAlphaModels = $this->includeAlphaModels();

        /** @var list<ModelData> $modelsData */
        $ttsModelsData = array_filter(
            $modelsData,
            static function (array $modelData) use ($includeAlphaModels): bool {
                if (empty($modelData['can_do_text_to_speech'])) {
                    return false;
                }

                // The API lists alpha models to every account but does not say
                // whether this one has been granted access, so they are left out
                // unless a site opts in.
                return $includeAlphaModels || empty($modelData['requires_alpha_access']);
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

        return $this->sortModels($models);
    }

    /**
     * Sorts models so that the preferred default comes first.
     *
     * The configured default model leads, then the models in
     * {@see self::MODEL_PRIORITY}, then the rest by ID. The AI Client falls back
     * to the first matching model when a prompt names no model preference, so
     * this order is what makes the configured default take effect.
     *
     * @since 1.1.0
     *
     * @param list<ModelMetadata> $models The models to sort.
     * @return list<ModelMetadata> The sorted models.
     */
    private function sortModels(array $models): array
    {
        $rankedModelIds = self::MODEL_PRIORITY;

        $defaultModelId = $this->resolveDefaultModelId();
        if ($defaultModelId !== '') {
            array_unshift($rankedModelIds, $defaultModelId);
        }

        // A default that is also in the priority list keeps its first, higher rank.
        $ranks = [];
        foreach ($rankedModelIds as $modelId) {
            $ranks[$modelId] = $ranks[$modelId] ?? count($ranks);
        }

        usort(
            $models,
            function (ModelMetadata $a, ModelMetadata $b) use ($ranks): int {
                $rankA = $ranks[$a->getId()] ?? PHP_INT_MAX;
                $rankB = $ranks[$b->getId()] ?? PHP_INT_MAX;

                if ($rankA !== $rankB) {
                    return $rankA <=> $rankB;
                }

                return $this->modelSortCallback($a, $b);
            }
        );

        return $models;
    }

    /**
     * Resolves the model to list first, making it the default for prompts that
     * name no model preference.
     *
     * The default is resolved from (in order): the `ELEVENLABS_DEFAULT_MODEL_ID`
     * environment variable, the `ELEVENLABS_DEFAULT_MODEL_ID` constant and the
     * `ai_provider_for_elevenlabs_default_model_id` WordPress option, then passed
     * through the `ai_provider_for_elevenlabs_default_model_id` WordPress filter.
     * An empty result leaves {@see self::MODEL_PRIORITY} in charge, and a model
     * the account does not offer is ignored.
     *
     * @since 1.1.0
     *
     * @return string The default model ID, or an empty string for none.
     */
    protected function resolveDefaultModelId(): string
    {
        $modelId = '';

        $envModelId = getenv('ELEVENLABS_DEFAULT_MODEL_ID');
        if (is_string($envModelId) && trim($envModelId) !== '') {
            $modelId = trim($envModelId);
        }

        if ($modelId === '' && defined('ELEVENLABS_DEFAULT_MODEL_ID')) {
            $constantModelId = constant('ELEVENLABS_DEFAULT_MODEL_ID');
            if (is_string($constantModelId) && trim($constantModelId) !== '') {
                $modelId = trim($constantModelId);
            }
        }

        if ($modelId === '' && function_exists('get_option')) {
            $optionModelId = get_option('ai_provider_for_elevenlabs_default_model_id', '');
            if (is_string($optionModelId) && trim($optionModelId) !== '') {
                $modelId = trim($optionModelId);
            }
        }

        if (function_exists('apply_filters')) {
            /**
             * Filters the model listed first, used when a prompt names no model preference.
             *
             * @since 1.1.0
             *
             * @param string $modelId The resolved default model ID, or an empty string for none.
             */
            $filteredModelId = apply_filters('ai_provider_for_elevenlabs_default_model_id', $modelId);
            if (is_string($filteredModelId)) {
                $modelId = trim($filteredModelId);
            }
        }

        return $modelId;
    }

    /**
     * Resolves the text-to-speech models the site allows.
     *
     * Read from the `ai_provider_for_elevenlabs_allowed_models` WordPress filter.
     * An empty list, the default, allows every model the account offers. The
     * sound generation model is never affected.
     *
     * @since 1.1.0
     *
     * @return list<string> The allowed model IDs, or an empty list for no restriction.
     */
    protected function resolveAllowedModelIds(): array
    {
        if (!function_exists('apply_filters')) {
            return [];
        }

        /**
         * Filters the text-to-speech models offered to the AI Client.
         *
         * Return a list of model IDs to offer only those, or an empty list to
         * offer every model the account has. A list matching none of the
         * account's models is ignored.
         *
         * @since 1.1.0
         *
         * @param list<string> $modelIds The allowed model IDs. Empty for no restriction.
         */
        $modelIds = apply_filters('ai_provider_for_elevenlabs_allowed_models', []);
        if (!is_array($modelIds)) {
            return [];
        }

        $modelIds = array_filter(
            array_map(
                static fn ($modelId): string => is_string($modelId) ? trim($modelId) : '',
                $modelIds
            ),
            static fn (string $modelId): bool => $modelId !== ''
        );

        return array_values(array_unique($modelIds));
    }

    /**
     * Determines whether models marked as requiring alpha access are listed.
     *
     * Read from the `ai_provider_for_elevenlabs_include_alpha_models` WordPress
     * filter, off by default.
     *
     * @since 1.1.0
     *
     * @return bool Whether alpha models are listed.
     */
    protected function includeAlphaModels(): bool
    {
        if (!function_exists('apply_filters')) {
            return false;
        }

        /**
         * Filters whether models that require alpha access are listed.
         *
         * ElevenLabs lists alpha models to every account without saying whether
         * the account has access, so enable this only if yours does.
         *
         * @since 1.1.0
         *
         * @param bool $include Whether to list alpha models. Default false.
         */
        return (bool) apply_filters('ai_provider_for_elevenlabs_include_alpha_models', false);
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
