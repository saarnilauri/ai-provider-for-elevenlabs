<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
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
     * Tests that models are sorted alphabetically by ID.
     */
    public function testModelsAreSortedById(): void
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
            ])
        );

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertSame('eleven_flash_v2', $models[0]->getId());
        $this->assertSame('eleven_multilingual_v2', $models[1]->getId());
        $this->assertSame('eleven_turbo_v2_5', $models[2]->getId());
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
