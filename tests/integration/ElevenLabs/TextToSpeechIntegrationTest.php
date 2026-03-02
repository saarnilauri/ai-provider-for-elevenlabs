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
 * Integration tests for ElevenLabs text-to-speech via the AiClient fluent API.
 *
 * These tests make real API calls to ElevenLabs and require the
 * ELEVENLABS_API_KEY environment variable to be set.
 *
 * @group integration
 * @group elevenlabs
 *
 * @coversNothing
 */
class TextToSpeechIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var string A known premade voice ID (Rachel).
     */
    private const DEFAULT_VOICE_ID = '21m00Tcm4TlvDq8ikWAM';

    /**
     * @var string A known premade voice ID (Hale).
     */
    private const CUSTOM_VOICE_ID = 'wWWn96OtTHu1sn8SRGEr';

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
     * Tests generating speech from simple text using the fluent API.
     */
    public function testSimpleTtsGeneration(): void
    {
        $audio = AiClient::prompt('Hello, this is a test.', $this->registry)
            ->usingProvider('elevenlabs')
            ->usingModelConfig(ModelConfig::fromArray([
                'outputSpeechVoice' => self::DEFAULT_VOICE_ID,
            ]))
            ->convertTextToSpeech();

        $this->assertTrue($audio->isAudio());
        $this->assertNotEmpty($audio->getBase64Data());

        $audioData = base64_decode($audio->getBase64Data());
        $this->assertNotEmpty($audioData);

        $filePath = $this->audioOutputDir . '/tts_simple.mp3';
        file_put_contents($filePath, $audioData);
        $this->assertFileExists($filePath);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /**
     * Tests TTS with custom voice settings via the fluent API.
     */
    public function testTtsWithCustomVoiceSettings(): void
    {
        $audio = AiClient::prompt('Testing custom voice settings.', $this->registry)
            ->usingProvider('elevenlabs')
            ->usingModelPreference(['eleven_multilingual_v2', 'elevenlabs'])
            ->usingModelConfig(ModelConfig::fromArray([
                'outputSpeechVoice' => self::CUSTOM_VOICE_ID,
                'customOptions' => [
                    'stability'         => 0.7,
                    'similarity_boost'  => 0.8,
                    'style'             => 0.2,
                    'use_speaker_boost' => true,
                ],
            ]))
            ->convertTextToSpeech();

        $this->assertTrue($audio->isAudio());

        $audioData = base64_decode($audio->getBase64Data());
        $filePath = $this->audioOutputDir . '/tts_custom_settings.mp3';
        file_put_contents($filePath, $audioData);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /**
     * Tests TTS with a different output format.
     */
    public function testTtsWithDifferentOutputFormat(): void
    {
        $audio = AiClient::prompt('Different format test.', $this->registry)
            ->usingProvider('elevenlabs')
            ->usingModelConfig(ModelConfig::fromArray([
                'outputSpeechVoice' => self::DEFAULT_VOICE_ID,
                'customOptions' => [
                    'output_format' => 'mp3_22050_32',
                ],
            ]))
            ->convertTextToSpeech();

        $audioData = base64_decode($audio->getBase64Data());
        $filePath = $this->audioOutputDir . '/tts_low_quality.mp3';
        file_put_contents($filePath, $audioData);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /**
     * Tests that convertTextToSpeechResult returns full result with metadata.
     */
    public function testTtsResultIncludesProviderMetadata(): void
    {
        $result = AiClient::prompt('Metadata test.', $this->registry)
            ->usingProvider('elevenlabs')
            ->usingModelConfig(ModelConfig::fromArray([
                'outputSpeechVoice' => self::DEFAULT_VOICE_ID,
            ]))
            ->convertTextToSpeechResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('elevenlabs', $result->getProviderMetadata()->getId());
        $this->assertNotEmpty($result->getModelMetadata()->getId());

        $audio = $result->toAudioFile();
        $this->assertTrue($audio->isAudio());
        $this->assertNotEmpty($audio->getBase64Data());
    }
}
