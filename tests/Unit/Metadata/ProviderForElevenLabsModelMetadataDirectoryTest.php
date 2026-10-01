<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * @covers \AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory
 */
class ProviderForElevenLabsModelMetadataDirectoryTest extends TestCase
{
    /**
     * Tests parsing model metadata from an ElevenLabs API response.
     */
    public function testParseResponseToModelMetadataList(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                [
                    'model_id' => 'eleven_multilingual_v2',
                    'name' => 'Eleven Multilingual v2',
                    'can_do_text_to_speech' => true,
                    'can_do_voice_conversion' => true,
                    'can_be_finetuned' => true,
                ],
                [
                    'model_id' => 'eleven_turbo_v2_5',
                    'name' => 'Eleven Turbo v2.5',
                    'can_do_text_to_speech' => true,
                    'can_do_voice_conversion' => false,
                    'can_be_finetuned' => false,
                ],
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertCount(2, $models);

        // Models should be sorted by ID.
        $this->assertSame('eleven_multilingual_v2', $models[0]->getId());
        $this->assertSame('Eleven Multilingual v2', $models[0]->getName());
        $this->assertSame('eleven_turbo_v2_5', $models[1]->getId());
        $this->assertSame('Eleven Turbo v2.5', $models[1]->getName());
    }

    /**
     * Tests that TTS models receive TEXT_TO_SPEECH_CONVERSION capability.
     */
    public function testTtsModelsHaveCorrectCapability(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                [
                    'model_id' => 'eleven_multilingual_v2',
                    'name' => 'Eleven Multilingual v2',
                    'can_do_text_to_speech' => true,
                ],
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertCount(1, $models);
        $this->assertContains(
            CapabilityEnum::textToSpeechConversion(),
            $models[0]->getSupportedCapabilities()
        );
    }

    /**
     * Tests that TTS models have the correct supported options.
     */
    public function testTtsModelsHaveCorrectOptions(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                [
                    'model_id' => 'eleven_multilingual_v2',
                    'name' => 'Eleven Multilingual v2',
                    'can_do_text_to_speech' => true,
                ],
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $optionNames = array_map(
            static fn (SupportedOption $option): string => $option->getName()->value,
            $models[0]->getSupportedOptions()
        );

        $this->assertContains(OptionEnum::outputSpeechVoice()->value, $optionNames);
        $this->assertContains(OptionEnum::outputMimeType()->value, $optionNames);
        $this->assertContains(OptionEnum::customOptions()->value, $optionNames);
        $this->assertContains(OptionEnum::outputModalities()->value, $optionNames);
        $this->assertContains(OptionEnum::outputFileType()->value, $optionNames);

        $outputFileTypeOption = $this->findOption($models[0], OptionEnum::outputFileType());
        $this->assertNotNull($outputFileTypeOption);
        $this->assertTrue($outputFileTypeOption->isSupportedValue(FileTypeEnum::inline()));
    }

    /**
     * Tests that the fallback models map declares the same TTS options,
     * including inline outputFileType, so the two code paths cannot drift.
     */
    public function testFallbackModelsHaveCorrectOptions(): void
    {
        // No HTTP transporter is set, so the /models request fails and the
        // hardcoded fallback models map is used.
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $modelsMap = $directory->exposeSendListModelsRequest();

        $this->assertArrayHasKey('eleven_multilingual_v2', $modelsMap);

        $ttsModel = $modelsMap['eleven_multilingual_v2'];
        $optionNames = array_map(
            static fn (SupportedOption $option): string => $option->getName()->value,
            $ttsModel->getSupportedOptions()
        );

        $this->assertContains(OptionEnum::outputSpeechVoice()->value, $optionNames);
        $this->assertContains(OptionEnum::outputMimeType()->value, $optionNames);
        $this->assertContains(OptionEnum::outputFileType()->value, $optionNames);

        $outputFileTypeOption = $this->findOption($ttsModel, OptionEnum::outputFileType());
        $this->assertNotNull($outputFileTypeOption);
        $this->assertTrue($outputFileTypeOption->isSupportedValue(FileTypeEnum::inline()));
    }

    /**
     * Tests that the synthetic sound generation model declares inline outputFileType.
     */
    public function testSoundGenerationModelDeclaresInlineOutputFileType(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $modelsMap = $directory->exposeSendListModelsRequest();

        $this->assertArrayHasKey('elevenlabs-sound-generation', $modelsMap);

        $outputFileTypeOption = $this->findOption(
            $modelsMap['elevenlabs-sound-generation'],
            OptionEnum::outputFileType()
        );
        $this->assertNotNull($outputFileTypeOption);
        $this->assertTrue($outputFileTypeOption->isSupportedValue(FileTypeEnum::inline()));
    }

    /**
     * Tests that all models have AUDIO output modality.
     */
    public function testModelsHaveAudioOutputModality(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                [
                    'model_id' => 'eleven_multilingual_v2',
                    'name' => 'Eleven Multilingual v2',
                    'can_do_text_to_speech' => true,
                ],
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $outputModalitiesOption = $this->findOption($models[0], OptionEnum::outputModalities());
        $this->assertNotNull($outputModalitiesOption);
        $this->assertTrue(
            $this->supportedModalitiesInclude(
                $outputModalitiesOption->getSupportedValues() ?? [],
                ['audio']
            )
        );
    }

    /**
     * Tests that priority models lead and the rest are sorted by ID.
     */
    public function testModelsAreSortedByPriorityThenId(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                [
                    'model_id' => 'eleven_turbo_v2_5',
                    'name' => 'Eleven Turbo v2.5',
                    'can_do_text_to_speech' => true,
                ],
                [
                    'model_id' => 'eleven_flash_v2',
                    'name' => 'Eleven Flash v2',
                    'can_do_text_to_speech' => true,
                ],
                [
                    'model_id' => 'eleven_multilingual_v2',
                    'name' => 'Eleven Multilingual v2',
                    'can_do_text_to_speech' => true,
                ],
                [
                    'model_id' => 'eleven_v4',
                    'name' => 'Eleven v4',
                    'can_do_text_to_speech' => true,
                ],
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertSame(
            ['eleven_v4', 'eleven_multilingual_v2', 'eleven_flash_v2', 'eleven_turbo_v2_5'],
            array_map(static fn (ModelMetadata $model): string => $model->getId(), $models)
        );
    }

    // ------------------------------------------------------------------
    // Model selection
    // ------------------------------------------------------------------

    /**
     * Returns the IDs of a models map or list, in order.
     *
     * @param array<ModelMetadata> $models
     * @return list<string>
     */
    private function modelIds(array $models): array
    {
        return array_values(array_map(static fn (ModelMetadata $model): string => $model->getId(), $models));
    }

    public function testFallbackModelsListV4FirstAndNoRetiredModels(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $modelIds = $this->modelIds($directory->exposeSendListModelsRequest());

        $this->assertSame('eleven_v4', $modelIds[0]);
        $this->assertContains('eleven_v4_turbo', $modelIds);
        $this->assertContains('eleven_v3_conversational', $modelIds);
        $this->assertNotContains('eleven_monolingual_v1', $modelIds);
        $this->assertNotContains('eleven_multilingual_v1', $modelIds);
    }

    public function testConfiguredDefaultModelIsListedFirst(): void
    {
        putenv('ELEVENLABS_DEFAULT_MODEL_ID=eleven_flash_v2_5');

        try {
            $directory = new MockProviderForElevenLabsModelMetadataDirectory();
            $modelIds = $this->modelIds($directory->exposeSendListModelsRequest());
        } finally {
            putenv('ELEVENLABS_DEFAULT_MODEL_ID');
        }

        $this->assertSame(['eleven_flash_v2_5', 'eleven_v4', 'eleven_multilingual_v2'], array_slice($modelIds, 0, 3));
    }

    public function testDefaultModelFromThePriorityListMovesAheadOnce(): void
    {
        putenv('ELEVENLABS_DEFAULT_MODEL_ID=eleven_v3');

        try {
            $directory = new MockProviderForElevenLabsModelMetadataDirectory();
            $modelIds = $this->modelIds($directory->exposeSendListModelsRequest());
        } finally {
            putenv('ELEVENLABS_DEFAULT_MODEL_ID');
        }

        $this->assertSame(['eleven_v3', 'eleven_v4', 'eleven_multilingual_v2'], array_slice($modelIds, 0, 3));
        $this->assertSame($modelIds, array_values(array_unique($modelIds)));
    }

    public function testAllowedModelsNarrowTheLiveList(): void
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($this->buildModelsResponse([
                ['model_id' => 'eleven_v4', 'can_do_text_to_speech' => true],
                ['model_id' => 'eleven_flash_v2_5', 'can_do_text_to_speech' => true],
                ['model_id' => 'eleven_multilingual_v2', 'can_do_text_to_speech' => true],
            ]));

        $authentication = $this->createMock(RequestAuthenticationInterface::class);
        $authentication
            ->method('authenticateRequest')
            ->willReturnCallback(static fn (Request $request): Request => $request);

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $directory->setHttpTransporter($transporter);
        $directory->setRequestAuthentication($authentication);
        $directory->allowedModelIds = ['eleven_flash_v2_5', 'eleven_v4_turbo'];

        // eleven_v4_turbo is in the fallback list but not in this live response,
        // so it must not appear: the filter narrows the live list, it adds nothing.
        $this->assertSame(
            ['eleven_flash_v2_5', 'elevenlabs-sound-generation'],
            $this->modelIds($directory->exposeSendListModelsRequest())
        );
    }

    public function testUnknownDefaultModelIsIgnored(): void
    {
        putenv('ELEVENLABS_DEFAULT_MODEL_ID=eleven_not_a_model');

        try {
            $directory = new MockProviderForElevenLabsModelMetadataDirectory();
            $modelIds = $this->modelIds($directory->exposeSendListModelsRequest());
        } finally {
            putenv('ELEVENLABS_DEFAULT_MODEL_ID');
        }

        $this->assertSame('eleven_v4', $modelIds[0]);
        $this->assertNotContains('eleven_not_a_model', $modelIds);
    }

    public function testAllowedModelsNarrowTheListAndKeepSoundGeneration(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $directory->allowedModelIds = ['eleven_flash_v2_5', 'eleven_v4'];

        $this->assertSame(
            ['eleven_v4', 'eleven_flash_v2_5', 'elevenlabs-sound-generation'],
            $this->modelIds($directory->exposeSendListModelsRequest())
        );
    }

    public function testAllowedModelsMatchingNothingAreIgnored(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $directory->allowedModelIds = ['eleven_not_a_model'];

        $modelIds = $this->modelIds($directory->exposeSendListModelsRequest());

        $this->assertContains('eleven_v4', $modelIds);
        $this->assertContains('eleven_multilingual_v2', $modelIds);
    }

    public function testAlphaModelsAreExcludedUnlessIncluded(): void
    {
        $models = [
            [
                'model_id'              => 'eleven_v4',
                'can_do_text_to_speech' => true,
                'requires_alpha_access' => false,
            ],
            [
                'model_id'              => 'eleven_v5_alpha',
                'can_do_text_to_speech' => true,
                'requires_alpha_access' => true,
            ],
        ];

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $this->assertSame(
            ['eleven_v4'],
            $this->modelIds($directory->exposeParseResponseToModelMetadataList($this->buildModelsResponse($models)))
        );

        $directory->alphaModelsIncluded = true;
        $this->assertSame(
            ['eleven_v4', 'eleven_v5_alpha'],
            $this->modelIds($directory->exposeParseResponseToModelMetadataList($this->buildModelsResponse($models)))
        );
    }

    public function testCacheKeyChangesWithModelSettings(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $defaultKey = $directory->exposeGetBaseCacheKey();

        $directory->allowedModelIds = ['eleven_v4'];
        $allowedKey = $directory->exposeGetBaseCacheKey();

        putenv('ELEVENLABS_DEFAULT_MODEL_ID=eleven_v3');
        try {
            $defaultModelKey = $directory->exposeGetBaseCacheKey();
        } finally {
            putenv('ELEVENLABS_DEFAULT_MODEL_ID');
        }

        $this->assertNotSame($defaultKey, $allowedKey);
        $this->assertNotSame($allowedKey, $defaultModelKey);
    }

    /**
     * Tests that the model name defaults to model ID when name is missing.
     */
    public function testModelNameDefaultsToIdWhenMissing(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                [
                    'model_id' => 'eleven_monolingual_v1',
                    'can_do_text_to_speech' => true,
                ],
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertSame('eleven_monolingual_v1', $models[0]->getName());
    }

    /**
     * Tests that non-TTS models are filtered out.
     */
    public function testNonTtsModelsAreFilteredOut(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                [
                    'model_id' => 'eleven_multilingual_v2',
                    'name' => 'Eleven Multilingual v2',
                    'can_do_text_to_speech' => true,
                ],
                [
                    'model_id' => 'eleven_english_sts_v2',
                    'name' => 'Speech to Speech v2',
                    'can_do_text_to_speech' => false,
                    'can_do_voice_conversion' => true,
                ],
                [
                    'model_id' => 'eleven_no_flag_model',
                    'name' => 'No Flag Model',
                ],
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertCount(1, $models);
        $this->assertSame('eleven_multilingual_v2', $models[0]->getId());
    }

    /**
     * Tests that an empty response throws an exception.
     */
    public function testEmptyResponseThrowsException(): void
    {
        $response = new Response(200, [], json_encode([]));

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->expectException(\WordPress\AiClient\Providers\Http\Exception\ResponseException::class);
        $directory->exposeParseResponseToModelMetadataList($response);
    }

    // ------------------------------------------------------------------
    // Per-model character limits
    // ------------------------------------------------------------------

    /**
     * Builds a models response from raw model entries.
     *
     * @param list<array<string, mixed>> $models Raw model entries.
     * @return Response
     */
    private function buildModelsResponse(array $models): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode($models, JSON_THROW_ON_ERROR)
        );
    }

    public function testSeededLimitsAreAvailableWithoutFetchingModels(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame(10000, $directory->getMaxTextLength('eleven_v4'));
        $this->assertSame(10000, $directory->getMaxTextLength('eleven_v4_turbo'));
        $this->assertSame(10000, $directory->getMaxTextLength('eleven_multilingual_v2'));
        $this->assertSame(5000, $directory->getMaxTextLength('eleven_v3'));
        $this->assertSame(5000, $directory->getMaxTextLength('eleven_v3_conversational'));
        $this->assertSame(40000, $directory->getMaxTextLength('eleven_flash_v2_5'));
    }

    public function testLiveResponseOverridesTheSeededLimit(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $directory->exposeParseResponseToModelMetadataList($this->buildModelsResponse([
            [
                'model_id'                        => 'eleven_multilingual_v2',
                'name'                            => 'Multilingual v2',
                'can_do_text_to_speech'           => true,
                'maximum_text_length_per_request' => 12345,
            ],
        ]));

        $this->assertSame(12345, $directory->getMaxTextLength('eleven_multilingual_v2'));
    }

    public function testLimitIsLearnedForAModelNotSeededInConstants(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $directory->exposeParseResponseToModelMetadataList($this->buildModelsResponse([
            [
                'model_id'                        => 'eleven_future_v9',
                'name'                            => 'Future v9',
                'can_do_text_to_speech'           => true,
                'maximum_text_length_per_request' => 250000,
            ],
        ]));

        $this->assertSame(250000, $directory->getMaxTextLength('eleven_future_v9'));
    }

    public function testModelWithoutALimitFieldKeepsTheConservativeDefault(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $directory->exposeParseResponseToModelMetadataList($this->buildModelsResponse([
            [
                'model_id'              => 'eleven_unknown_v1',
                'name'                  => 'Unknown v1',
                'can_do_text_to_speech' => true,
            ],
        ]));

        $this->assertSame(
            MockProviderForElevenLabsModelMetadataDirectory::DEFAULT_MAX_TEXT_LENGTH,
            $directory->getMaxTextLength('eleven_unknown_v1')
        );
    }

    public function testUnknownModelReturnsTheConservativeDefault(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame(5000, $directory->getMaxTextLength('never-heard-of-it'));
    }

    public function testRetiredModelsWithoutAMeasuredLimitUseTheDefault(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        // The API no longer returns these, so no measured value exists. They must
        // not carry an invented limit.
        $this->assertSame(5000, $directory->getMaxTextLength('eleven_monolingual_v1'));
        $this->assertSame(5000, $directory->getMaxTextLength('eleven_multilingual_v1'));
    }

    public function testNonPositiveOrNonIntegerLimitsAreIgnored(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $directory->exposeParseResponseToModelMetadataList($this->buildModelsResponse([
            [
                'model_id'                        => 'eleven_multilingual_v2',
                'can_do_text_to_speech'           => true,
                'maximum_text_length_per_request' => 0,
            ],
        ]));

        $this->assertSame(10000, $directory->getMaxTextLength('eleven_multilingual_v2'));
    }

    /**
     * Finds a supported option by name.
     *
     * @param ModelMetadata $model
     * @param OptionEnum $option
     * @return SupportedOption|null
     */
    private function findOption(ModelMetadata $model, OptionEnum $option): ?SupportedOption
    {
        foreach ($model->getSupportedOptions() as $supportedOption) {
            if ($supportedOption->getName()->is($option)) {
                return $supportedOption;
            }
        }

        return null;
    }

    /**
     * Checks if the supported modality values include the expected set.
     *
     * @param list<mixed> $supportedValues
     * @param list<string> $expected
     * @return bool
     */
    private function supportedModalitiesInclude(array $supportedValues, array $expected): bool
    {
        foreach ($supportedValues as $value) {
            if (!is_array($value)) {
                continue;
            }

            $modalities = array_map(
                static function ($modality): ?string {
                    return $modality instanceof ModalityEnum ? $modality->value : null;
                },
                $value
            );

            $modalities = array_values(array_filter($modalities));
            sort($modalities);

            $expectedSorted = $expected;
            sort($expectedSorted);

            if ($modalities === $expectedSorted) {
                return true;
            }
        }

        return false;
    }
}
