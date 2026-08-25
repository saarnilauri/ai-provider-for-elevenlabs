<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Integration\ElevenLabs;

use AiProviderForElevenLabs\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Integration tests for ElevenLabs sound generation via the AiClient fluent API.
 *
 * These tests make real API calls to ElevenLabs and require the
 * ELEVENLABS_API_KEY environment variable to be set.
 *
 * @group integration
 * @group elevenlabs
 *
 * @coversNothing
 */
class SoundGenerationIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    private ProviderRegistry $registry;
    private string $audioOutputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('ELEVENLABS_API_KEY');

        $this->registry = $this->createElevenLabsRegistry();

        $this->audioOutputDir = dirname(__DIR__) . '/audio';
        if (!is_dir($this->audioOutputDir)) {
            mkdir($this->audioOutputDir, 0755, true);
        }
    }

    /**
     * Tests generating a sound effect using the fluent API.
     */
    public function testGenerateSoundEffect(): void
    {
        $audio = AiClient::prompt(
            'A gentle ocean wave crashing on a sandy beach',
            $this->registry
        )
            ->usingProvider('elevenlabs')
            ->usingModelPreference(['elevenlabs-sound-generation', 'elevenlabs'])
            ->generateSpeech();

        $this->assertSame('audio/mpeg', $audio->getMimeType());
        $this->assertNotEmpty($audio->getBase64Data());

        $audioData = base64_decode($audio->getBase64Data());
        $this->assertNotEmpty($audioData);

        $filePath = $this->audioOutputDir . '/sound-effect-ocean.mp3';
        file_put_contents($filePath, $audioData);
        $this->assertFileExists($filePath);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /**
     * Tests sound generation with custom duration and prompt influence.
     */
    public function testSoundEffectWithCustomOptions(): void
    {
        $audio = AiClient::prompt('A quick door knock', $this->registry)
            ->usingProvider('elevenlabs')
            ->usingModelPreference(['elevenlabs-sound-generation', 'elevenlabs'])
            ->usingModelConfig(ModelConfig::fromArray([
                'customOptions' => [
                    'duration_seconds' => 2.0,
                    'prompt_influence' => 0.5,
                ],
            ]))
            ->generateSpeech();

        $audioData = base64_decode($audio->getBase64Data());
        $this->assertNotEmpty($audioData);

        $filePath = $this->audioOutputDir . '/sound-effect-knock.mp3';
        file_put_contents($filePath, $audioData);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /**
     * Tests that generateSpeechResult returns full result with metadata.
     */
    public function testSoundGenerationResultIncludesProviderMetadata(): void
    {
        $result = AiClient::prompt(
            'A short bird chirp',
            $this->registry
        )
            ->usingProvider('elevenlabs')
            ->usingModelPreference(['elevenlabs-sound-generation', 'elevenlabs'])
            ->generateSpeechResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('elevenlabs', $result->getProviderMetadata()->getId());
        $this->assertSame('elevenlabs-sound-generation', $result->getModelMetadata()->getId());

        $audio = $result->toAudioFile();
        $this->assertSame('audio/mpeg', $audio->getMimeType());
    }
}
