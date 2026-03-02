<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Metadata;

use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use Exception;
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
 *     can_be_finetuned?: bool
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
     * {@inheritDoc}
     *
     * Extends the base implementation to add a hardcoded sound generation model
     * entry, since the ElevenLabs /models endpoint only returns TTS models.
     *
     * @since 0.1.0
     */
    /**
     * Known ElevenLabs TTS models used as fallback when the /models endpoint
     * is inaccessible (e.g. API key lacks models_read permission).
     *
     * @since 0.1.0
     *
     * @var array<string, string> Model ID => display name.
     */
    private const FALLBACK_MODELS = [
        'eleven_flash_v2'        => 'Flash v2',
        'eleven_flash_v2_5'      => 'Flash v2.5',
        'eleven_monolingual_v1'  => 'English v1',
        'eleven_multilingual_v1' => 'Multilingual v1',
        'eleven_multilingual_v2' => 'Multilingual v2',
        'eleven_turbo_v2'        => 'Turbo v2',
        'eleven_turbo_v2_5'      => 'Turbo v2.5',
    ];

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
        $ttsOptions = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
            new SupportedOption(OptionEnum::outputSpeechVoice()),
            new SupportedOption(OptionEnum::outputMimeType()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

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
        } elseif (!array_is_list($responseData)) {
            throw ResponseException::fromMissingData('ElevenLabs', 'models');
        }

        $ttsOptions = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
            new SupportedOption(OptionEnum::outputSpeechVoice()),
            new SupportedOption(OptionEnum::outputMimeType()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        /** @var list<ModelData> $modelsData */
        $ttsModelsData = array_filter(
            $modelsData,
            static function (array $modelData): bool {
                return !empty($modelData['can_do_text_to_speech']);
            }
        );

        $models = array_values(
            array_map(
                static function (array $modelData) use ($ttsOptions): ModelMetadata {
                    $modelId = $modelData['model_id'];
                    $modelName = $modelData['name'] ?? $modelId;

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
