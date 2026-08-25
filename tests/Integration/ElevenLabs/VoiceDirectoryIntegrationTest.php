<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Integration\ElevenLabs;

use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use AiProviderForElevenLabs\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Integration tests for VoiceDirectory via the AiClient provider API.
 *
 * These tests make real API calls to ElevenLabs and require the
 * ELEVENLABS_API_KEY environment variable to be set.
 *
 * @group integration
 * @group elevenlabs
 *
 * @coversNothing
 */
class VoiceDirectoryIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('ELEVENLABS_API_KEY');

        $this->registry = $this->createElevenLabsRegistry();
    }

    /**
     * Tests that listing voices returns a non-empty array with correct structure.
     */
    public function testListAllVoices(): void
    {
        $voiceDirectory = ProviderForElevenLabs::getVoiceDirectory();
        $voices = $voiceDirectory->getVoices();

        $this->assertNotEmpty($voices, 'Expected at least one voice from the ElevenLabs API.');

        $firstVoice = reset($voices);
        $this->assertArrayHasKey('id', $firstVoice);
        $this->assertArrayHasKey('name', $firstVoice);
        $this->assertArrayHasKey('category', $firstVoice);
        $this->assertArrayHasKey('labels', $firstVoice);
        $this->assertArrayHasKey('description', $firstVoice);
        $this->assertArrayHasKey('preview_url', $firstVoice);

        $this->assertNotEmpty($firstVoice['id']);
        $this->assertNotEmpty($firstVoice['name']);
    }

    /**
     * Tests that premade voices are returned when filtering by category.
     */
    public function testPremadeVoicesAreReturned(): void
    {
        $voiceDirectory = ProviderForElevenLabs::getVoiceDirectory();
        $premade = $voiceDirectory->getVoicesByCategory('premade');

        $this->assertNotEmpty($premade, 'Expected at least one premade voice.');

        foreach ($premade as $voice) {
            $this->assertSame('premade', $voice['category']);
        }
    }

    /**
     * Tests that a specific voice can be retrieved by ID.
     */
    public function testGetSpecificVoiceById(): void
    {
        $voiceDirectory = ProviderForElevenLabs::getVoiceDirectory();
        $voices = $voiceDirectory->getVoices();
        $this->assertNotEmpty($voices);

        $knownId = array_key_first($voices);
        $voice = $voiceDirectory->getVoice($knownId);

        $this->assertNotNull($voice);
        $this->assertSame($knownId, $voice['id']);
    }
}
