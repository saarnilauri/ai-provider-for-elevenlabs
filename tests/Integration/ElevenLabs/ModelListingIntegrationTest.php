<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Integration\ElevenLabs;

use AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use AiProviderForElevenLabs\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Integration tests for the model list against the live `/v1/models` endpoint.
 *
 * Listing models is free, so unlike the text-to-speech tests these spend no
 * credits. They catch ElevenLabs renaming, retiring or re-limiting a model the
 * plugin's defaults depend on. They require the ELEVENLABS_API_KEY environment
 * variable, and a key with the Models permission.
 *
 * @group integration
 * @group elevenlabs
 *
 * @coversNothing
 */
class ModelListingIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('ELEVENLABS_API_KEY');

        $this->createElevenLabsRegistry();
    }

    /**
     * Tests that the live list puts v4 first, with its measured character limit.
     */
    public function testLiveListLeadsWithV4(): void
    {
        $directory = ProviderForElevenLabs::modelMetadataDirectory();
        $this->assertInstanceOf(ProviderForElevenLabsModelMetadataDirectory::class, $directory);

        $modelIds = array_map(
            static fn (ModelMetadata $model): string => $model->getId(),
            $directory->listModelMetadata()
        );

        $this->assertSame('eleven_v4', $modelIds[0], 'Expected eleven_v4 to be listed first.');
        $this->assertContains('eleven_v4_turbo', $modelIds);
        $this->assertContains('eleven_multilingual_v2', $modelIds);
        $this->assertContains('elevenlabs-sound-generation', $modelIds);

        // Speech-to-speech models are not text-to-speech and must be filtered out.
        $this->assertNotContains('eleven_english_sts_v2', $modelIds);

        $this->assertSame(10000, $directory->getMaxTextLength('eleven_v4'));
    }
}
