<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Integration\ElevenLabs;

use AiProviderForElevenLabs\Provider\ElevenLabsApiKeyAuthentication;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
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
     * Tests that a prompt with no configured voice still produces audio.
     *
     * This is the path a caller hits when they simply select the provider and
     * prompt it. ElevenLabs requires a voice ID in the request path, so the
     * provider resolves one from the account. Every other test in this file
     * pins a voice explicitly, so this is the only live coverage of that
     * resolution -- and the only one that proves the account lookup works
     * against the real API rather than a mocked voice list.
     *
     * Requires the API key to carry the Voices permission.
     */
    public function testTtsWithoutConfiguredVoiceUsesAccountDefault(): void
    {
        $audio = AiClient::prompt('No voice was configured for this request.', $this->registry)
            ->usingProvider('elevenlabs')
            ->convertTextToSpeech();

        $this->assertTrue($audio->isAudio());
        $this->assertNotEmpty($audio->getBase64Data());

        $audioData = base64_decode($audio->getBase64Data());
        $this->assertNotEmpty($audioData);

        $filePath = $this->audioOutputDir . '/tts_default_voice.mp3';
        file_put_contents($filePath, $audioData);
        $this->assertFileExists($filePath);
        $this->assertGreaterThan(0, filesize($filePath));
    }

    /**
     * Tests that the voice directory reports a default voice for the account.
     *
     * Complements the test above: that one proves audio comes back, this one
     * names the voice that was chosen so a failure distinguishes "no voice
     * could be resolved" from "synthesis failed".
     */
    public function testAccountExposesADefaultVoice(): void
    {
        $voiceDirectory = ProviderForElevenLabs::getVoiceDirectory();
        $voiceDirectory->setRequestAuthentication(
            new ElevenLabsApiKeyAuthentication((string) ($_ENV['ELEVENLABS_API_KEY'] ?? getenv('ELEVENLABS_API_KEY')))
        );

        $voices = $voiceDirectory->getVoices();
        $this->assertNotEmpty($voices, 'The account reported no voices at all.');

        $defaultVoiceId = $voiceDirectory->getDefaultVoiceId();
        $this->assertNotNull($defaultVoiceId);
        $this->assertArrayHasKey($defaultVoiceId, $voices);

        fwrite(
            STDERR,
            sprintf(
                "\nResolved default voice: %s (%s), from %d voice(s).\n",
                $voices[$defaultVoiceId]['name'],
                $defaultVoiceId,
                count($voices)
            )
        );
    }

    /**
     * Narrates text long enough to require several requests.
     *
     * The default model accepts 10,000 characters per request, so this exceeds
     * it deliberately. Beyond asserting that the audio comes back joined, this
     * records wall-clock time: chunked narration runs several sequential API
     * calls inside one synchronous request, and if a realistic post cannot
     * finish inside a normal PHP execution limit then this belongs in a
     * background job rather than a page request. The number is printed rather
     * than asserted, because a threshold here would be arbitrary and flaky.
     */
    public function testLongTextIsNarratedAcrossRequests(): void
    {
        $paragraph = 'WordPress powers a large share of the web, and the arrival of a shared AI '
            . 'client in core changes how plugins integrate with model providers. Rather than each '
            . 'plugin shipping its own HTTP layer and credential handling, a provider registers '
            . 'itself once and every consumer benefits. ';

        // Comfortably past the 10,000 character limit of eleven_multilingual_v2.
        $text = trim(str_repeat($paragraph, 60));
        $this->assertGreaterThan(10000, mb_strlen($text));

        $startedAt = microtime(true);

        $audio = AiClient::prompt($text, $this->registry)
            ->usingProvider('elevenlabs')
            ->usingModelConfig(ModelConfig::fromArray([
                'outputSpeechVoice' => self::DEFAULT_VOICE_ID,
            ]))
            ->convertTextToSpeech();

        $elapsed = microtime(true) - $startedAt;

        $this->assertTrue($audio->isAudio());
        $audioData = base64_decode($audio->getBase64Data());
        $this->assertNotEmpty($audioData);

        $filePath = $this->audioOutputDir . '/tts_long_form.mp3';
        file_put_contents($filePath, $audioData);

        fwrite(
            STDERR,
            sprintf(
                "\nLong-form narration: %d characters -> %s of audio in %.1fs.\n"
                . "  Compare against your max_execution_time before relying on the synchronous path.\n",
                mb_strlen($text),
                number_format(strlen($audioData) / 1024, 0) . ' KB',
                $elapsed
            )
        );
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
