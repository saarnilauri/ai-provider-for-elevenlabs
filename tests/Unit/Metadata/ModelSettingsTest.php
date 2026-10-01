<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Tests the model settings read from WordPress options and filters.
 *
 * Each test runs in its own process, because it defines the global WordPress
 * functions and the `ELEVENLABS_DEFAULT_MODEL_ID` constant, neither of which can
 * be undone.
 *
 * @covers \AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ModelSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__) . '/Stubs/wordpress-functions.php';

        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_filters'] = [];
    }

    /**
     * Returns the IDs of a models map, in order.
     *
     * @param array<string, ModelMetadata> $models
     * @return list<string>
     */
    private function modelIds(array $models): array
    {
        return array_values(array_map(static fn (ModelMetadata $model): string => $model->getId(), $models));
    }

    // ------------------------------------------------------------------
    // Default model
    // ------------------------------------------------------------------

    public function testDefaultModelIsReadFromTheOption(): void
    {
        $GLOBALS['wp_test_options']['ai_provider_for_elevenlabs_default_model_id'] = '  eleven_v3  ';

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame('eleven_v3', $directory->exposeResolveDefaultModelId());
    }

    public function testDefaultModelFilterReceivesTheOptionAndWins(): void
    {
        $GLOBALS['wp_test_options']['ai_provider_for_elevenlabs_default_model_id'] = 'eleven_v3';
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_default_model_id'] =
            function (string $modelId): string {
                $this->assertSame('eleven_v3', $modelId);

                return 'eleven_flash_v2_5';
            };

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame('eleven_flash_v2_5', $directory->exposeResolveDefaultModelId());
    }

    public function testDefaultModelFilterCanClearTheDefault(): void
    {
        $GLOBALS['wp_test_options']['ai_provider_for_elevenlabs_default_model_id'] = 'eleven_flash_v2';
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_default_model_id'] = static fn (): string => '';

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame('', $directory->exposeResolveDefaultModelId());
        $this->assertSame('eleven_v4', $this->modelIds($directory->exposeSendListModelsRequest())[0]);
    }

    public function testNonStringDefaultModelValuesAreIgnored(): void
    {
        $GLOBALS['wp_test_options']['ai_provider_for_elevenlabs_default_model_id'] = ['eleven_v3'];
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_default_model_id'] = static fn (): int => 42;

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame('', $directory->exposeResolveDefaultModelId());
    }

    public function testConstantTakesPrecedenceOverTheOption(): void
    {
        define('ELEVENLABS_DEFAULT_MODEL_ID', 'eleven_multilingual_v2');
        $GLOBALS['wp_test_options']['ai_provider_for_elevenlabs_default_model_id'] = 'eleven_v3';

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame('eleven_multilingual_v2', $directory->exposeResolveDefaultModelId());
    }

    public function testEnvironmentVariableTakesPrecedenceOverTheConstant(): void
    {
        define('ELEVENLABS_DEFAULT_MODEL_ID', 'eleven_multilingual_v2');
        putenv('ELEVENLABS_DEFAULT_MODEL_ID=eleven_flash_v2_5');

        try {
            $directory = new MockProviderForElevenLabsModelMetadataDirectory();
            $modelId = $directory->exposeResolveDefaultModelId();
        } finally {
            putenv('ELEVENLABS_DEFAULT_MODEL_ID');
        }

        $this->assertSame('eleven_flash_v2_5', $modelId);
    }

    public function testDefaultFromTheOptionIsListedFirst(): void
    {
        $GLOBALS['wp_test_options']['ai_provider_for_elevenlabs_default_model_id'] = 'eleven_flash_v2';

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame('eleven_flash_v2', $this->modelIds($directory->exposeSendListModelsRequest())[0]);
    }

    // ------------------------------------------------------------------
    // Allowed models
    // ------------------------------------------------------------------

    public function testAllowedModelsAreTrimmedDeduplicatedAndStripped(): void
    {
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_allowed_models'] = static fn (): array => [
            ' eleven_v4 ',
            'eleven_v4',
            '',
            '   ',
            42,
            null,
            'eleven_flash_v2_5',
        ];

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame(['eleven_v4', 'eleven_flash_v2_5'], $directory->exposeResolveAllowedModelIds());
    }

    public function testAllowedModelsFilterReceivesAnEmptyList(): void
    {
        $received = null;
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_allowed_models'] =
            static function ($modelIds) use (&$received) {
                $received = $modelIds;

                return $modelIds;
            };

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame([], $directory->exposeResolveAllowedModelIds());
        $this->assertSame([], $received);
    }

    public function testNonArrayAllowedModelsAreIgnored(): void
    {
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_allowed_models'] = static fn (): string => 'eleven_v4';

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame([], $directory->exposeResolveAllowedModelIds());
        $this->assertContains('eleven_multilingual_v2', $this->modelIds($directory->exposeSendListModelsRequest()));
    }

    public function testAllowedModelsFromTheFilterNarrowTheList(): void
    {
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_allowed_models'] =
            static fn (): array => ['eleven_flash_v2_5'];

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertSame(
            ['eleven_flash_v2_5', 'elevenlabs-sound-generation'],
            $this->modelIds($directory->exposeSendListModelsRequest())
        );
    }

    // ------------------------------------------------------------------
    // Alpha models
    // ------------------------------------------------------------------

    public function testAlphaModelsAreOffWithoutTheFilter(): void
    {
        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertFalse($directory->exposeIncludeAlphaModels());
    }

    public function testAlphaModelsFilterTurnsThemOn(): void
    {
        $GLOBALS['wp_test_filters']['ai_provider_for_elevenlabs_include_alpha_models'] = static fn (): bool => true;

        $directory = new MockProviderForElevenLabsModelMetadataDirectory();

        $this->assertTrue($directory->exposeIncludeAlphaModels());
    }
}
