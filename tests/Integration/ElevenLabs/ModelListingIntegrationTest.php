<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Integration\ElevenLabs;

use AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use AiProviderForElevenLabs\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
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

    private const SOUND_GENERATION_MODEL_ID = 'elevenlabs-sound-generation';

    private ProviderForElevenLabsModelMetadataDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('ELEVENLABS_API_KEY');

        $this->createElevenLabsRegistry();

        $directory = ProviderForElevenLabs::modelMetadataDirectory();
        $this->assertInstanceOf(ProviderForElevenLabsModelMetadataDirectory::class, $directory);
        $this->directory = $directory;

        // A list cached by an earlier test could be the fallback one.
        $this->directory->invalidateCaches();
    }

    /**
     * Fetches the raw `/v1/models` response with the provider's own transport
     * and credentials, failing unless the API answered successfully.
     *
     * @return array<string, array<string, mixed>> Text-to-speech models keyed by model ID.
     */
    private function fetchLiveTtsModels(): array
    {
        $request = new Request(HttpMethodEnum::GET(), ProviderForElevenLabs::url('models'));
        $request = $this->directory->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->directory->getHttpTransporter()->send($request);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Expected /v1/models to succeed; the key may lack the Models permission.'
        );

        $data = $response->getData();
        $this->assertIsArray($data);

        $models = [];
        foreach ($data as $model) {
            if (
                is_array($model)
                && !empty($model['can_do_text_to_speech'])
                && empty($model['requires_alpha_access'])
            ) {
                $models[(string) $model['model_id']] = $model;
            }
        }

        $this->assertNotEmpty($models, 'Expected /v1/models to return text-to-speech models.');

        return $models;
    }

    /**
     * Tests that the provider lists the live models, not its fallback list.
     *
     * The fallback list carries the same model IDs and seeded limits, so IDs and
     * limits alone cannot tell the two apart. The names can: the API returns
     * names such as "Eleven v4" where the fallback list has "v4".
     */
    public function testProviderListsTheLiveModels(): void
    {
        $liveModels = $this->fetchLiveTtsModels();

        $listedNames = [];
        foreach ($this->directory->listModelMetadata() as $model) {
            if ($model->getId() !== self::SOUND_GENERATION_MODEL_ID) {
                $listedNames[$model->getId()] = $model->getName();
            }
        }

        $liveNames = array_map(
            static fn (array $model): string => (string) ($model['name'] ?? $model['model_id']),
            $liveModels
        );

        ksort($listedNames);
        ksort($liveNames);
        $this->assertSame($liveNames, $listedNames, 'Expected the provider to list the live models.');
    }

    /**
     * Tests that the live list puts v4 first, with the live character limit.
     */
    public function testLiveListLeadsWithV4(): void
    {
        $liveModels = $this->fetchLiveTtsModels();
        $this->assertArrayHasKey('eleven_v4', $liveModels);

        $models = $this->directory->listModelMetadata();
        $modelIds = array_map(static fn (ModelMetadata $model): string => $model->getId(), $models);

        $this->assertSame('eleven_v4', $modelIds[0], 'Expected eleven_v4 to be listed first.');

        // Only the live response carries this name, so a fallback list fails here.
        $this->assertSame(
            $liveModels['eleven_v4']['name'],
            $models[0]->getName(),
            'Expected the live eleven_v4 entry, not the fallback one.'
        );
        $this->assertContains(self::SOUND_GENERATION_MODEL_ID, $modelIds);

        // Speech-to-speech models are not text-to-speech and must be filtered out.
        $this->assertNotContains('eleven_english_sts_v2', $modelIds);

        $this->assertSame(
            $liveModels['eleven_v4']['maximum_text_length_per_request'],
            $this->directory->getMaxTextLength('eleven_v4')
        );
        $this->assertSame(10000, $this->directory->getMaxTextLength('eleven_v4'));
    }
}
