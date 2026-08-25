<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * @covers \AiProviderForElevenLabs\Models\ProviderForElevenLabsTextToSpeechModel
 */
class ProviderForElevenLabsTextToSpeechModelTest extends TestCase
{
    /**
     * @var ModelMetadata&\PHPUnit\Framework\MockObject\Stub
     */
    private $modelMetadata;

    /**
     * @var ProviderMetadata&\PHPUnit\Framework\MockObject\Stub
     */
    private $providerMetadata;

    /**
     * @var HttpTransporterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockHttpTransporter;

    /**
     * @var RequestAuthenticationInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockRequestAuthentication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelMetadata = $this->createStub(ModelMetadata::class);
        $this->modelMetadata->method('getId')->willReturn('eleven_multilingual_v2');
        $this->providerMetadata = $this->createStub(ProviderMetadata::class);
        $this->providerMetadata->method('getName')->willReturn('AI Provider for ElevenLabs');
        $this->mockHttpTransporter = $this->createMock(HttpTransporterInterface::class);
        $this->mockRequestAuthentication = $this->createMock(RequestAuthenticationInterface::class);
    }

    /**
     * Creates a model instance with optional config.
     *
     * @param ModelConfig|null $modelConfig
     * @return MockProviderForElevenLabsTextToSpeechModel
     */
    private function createModel(?ModelConfig $modelConfig = null): MockProviderForElevenLabsTextToSpeechModel
    {
        $model = new MockProviderForElevenLabsTextToSpeechModel(
            $this->modelMetadata,
            $this->providerMetadata,
            $this->mockHttpTransporter,
            $this->mockRequestAuthentication
        );

        if ($modelConfig) {
            $model->setConfig($modelConfig);
        }

        return $model;
    }

    /**
     * Returns a minimal valid prompt.
     *
     * @param string $text
     * @return list<Message>
     */
    private function createPrompt(string $text = 'Hello, this is a test.'): array
    {
        return [new Message(MessageRoleEnum::user(), [new MessagePart($text)])];
    }

    /**
     * Creates a ModelConfig with outputSpeechVoice set.
     *
     * @param string $voiceId
     * @param array<string, mixed> $customOptions
     * @param string|null $outputMimeType
     * @return ModelConfig
     */
    private function createConfig(
        string $voiceId = 'JBFqnCBsd6RMkjVDRZzb',
        array $customOptions = [],
        ?string $outputMimeType = null
    ): ModelConfig {
        $configArray = ['outputSpeechVoice' => $voiceId];
        if ($customOptions !== []) {
            $configArray['customOptions'] = $customOptions;
        }
        if ($outputMimeType !== null) {
            $configArray['outputMimeType'] = $outputMimeType;
        }
        return ModelConfig::fromArray($configArray);
    }

    /**
     * Tests successful TTS generation with default settings.
     */
    public function testConvertTextToSpeechResultSuccess(): void
    {
        $audioBinary = 'fake-mp3-binary-audio-data';
        $config = $this->createConfig();

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, ['Content-Type' => ['audio/mpeg']], $audioBinary));

        $model = $this->createModel($config);
        $result = $model->convertTextToSpeechResult($this->createPrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertCount(1, $result->getCandidates());

        $candidate = $result->getCandidates()[0];
        $this->assertEquals(FinishReasonEnum::stop(), $candidate->getFinishReason());

        $parts = $candidate->getMessage()->getParts();
        $this->assertCount(1, $parts);

        $file = $parts[0]->getFile();
        $this->assertInstanceOf(File::class, $file);
        $this->assertTrue($file->isInline());
        $this->assertSame('audio/mpeg', $file->getMimeType());
        $this->assertSame(base64_encode($audioBinary), $file->getBase64Data());
    }

    /**
     * Tests that the voice ID is extracted from outputSpeechVoice and used in the URL.
     */
    public function testVoiceIdUsedInRequestUrl(): void
    {
        $capturedRequests = [];
        $config = $this->createConfig('MyVoiceId123');

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(function ($request) use (&$capturedRequests) {
                $capturedRequests[] = $request;
                return new Response(200, [], 'audio-data');
            });

        $model = $this->createModel($config);
        $model->convertTextToSpeechResult($this->createPrompt());

        $this->assertCount(1, $capturedRequests);
        $this->assertStringContainsString(
            'text-to-speech/MyVoiceId123',
            $capturedRequests[0]->getUri()
        );
    }

    /**
     * Tests that the request body contains the correct model_id, text, and default voice settings.
     */
    public function testRequestBodyContainsExpectedParameters(): void
    {
        $capturedRequests = [];
        $config = $this->createConfig();

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(function ($request) use (&$capturedRequests) {
                $capturedRequests[] = $request;
                return new Response(200, [], 'audio-data');
            });

        $model = $this->createModel($config);
        $model->convertTextToSpeechResult($this->createPrompt('Hello world'));

        $data = $capturedRequests[0]->getData();
        $this->assertIsArray($data);
        $this->assertSame('Hello world', $data['text']);
        $this->assertSame('eleven_multilingual_v2', $data['model_id']);
        $this->assertSame('mp3_44100_128', $data['output_format']);
        $this->assertSame(0.5, $data['voice_settings']['stability']);
        $this->assertSame(0.75, $data['voice_settings']['similarity_boost']);
        $this->assertSame(0.0, $data['voice_settings']['style']);
        $this->assertTrue($data['voice_settings']['use_speaker_boost']);
    }

    /**
     * Tests that custom voice settings override the defaults.
     */
    public function testCustomVoiceSettingsOverrideDefaults(): void
    {
        $config = $this->createConfig('voice1', [
            'stability'         => 0.9,
            'similarity_boost'  => 0.3,
            'style'             => 0.5,
            'use_speaker_boost' => false,
        ]);

        $model = $this->createModel($config);
        $settings = $model->exposeResolveVoiceSettings();

        $this->assertSame(0.9, $settings['stability']);
        $this->assertSame(0.3, $settings['similarity_boost']);
        $this->assertSame(0.5, $settings['style']);
        $this->assertFalse($settings['use_speaker_boost']);
    }

    /**
     * Tests partial custom voice settings override only the provided keys.
     */
    public function testPartialCustomVoiceSettingsOverride(): void
    {
        $config = $this->createConfig('voice1', [
            'stability' => 0.8,
        ]);

        $model = $this->createModel($config);
        $settings = $model->exposeResolveVoiceSettings();

        $this->assertSame(0.8, $settings['stability']);
        $this->assertSame(0.75, $settings['similarity_boost']);
        $this->assertSame(0.0, $settings['style']);
        $this->assertTrue($settings['use_speaker_boost']);
    }

    /**
     * Tests that default voice settings are applied when no custom options are set.
     */
    public function testDefaultVoiceSettingsApplied(): void
    {
        $config = $this->createConfig();

        $model = $this->createModel($config);
        $settings = $model->exposeResolveVoiceSettings();

        $this->assertSame(0.5, $settings['stability']);
        $this->assertSame(0.75, $settings['similarity_boost']);
        $this->assertSame(0.0, $settings['style']);
        $this->assertTrue($settings['use_speaker_boost']);
    }

    /**
     * Tests output format mapping from outputMimeType.
     */
    public function testOutputFormatFromMimeType(): void
    {
        $config = $this->createConfig('voice1', [], 'audio/ogg');

        $model = $this->createModel($config);
        $format = $model->exposeResolveOutputFormat();

        $this->assertSame('opus_48000_128', $format);
    }

    /**
     * Tests output format from custom options takes precedence.
     */
    public function testOutputFormatFromCustomOptions(): void
    {
        $config = $this->createConfig('voice1', ['output_format' => 'pcm_22050']);

        $model = $this->createModel($config);
        $format = $model->exposeResolveOutputFormat();

        $this->assertSame('pcm_22050', $format);
    }

    /**
     * Tests default output format when no configuration is provided.
     */
    public function testDefaultOutputFormat(): void
    {
        $config = $this->createConfig();

        $model = $this->createModel($config);
        $format = $model->exposeResolveOutputFormat();

        $this->assertSame('mp3_44100_128', $format);
    }

    /**
     * Tests MIME type resolution from various output format prefixes.
     */
    public function testResolveMimeTypeFromFormat(): void
    {
        $config = $this->createConfig();
        $model = $this->createModel($config);

        $this->assertSame('audio/mpeg', $model->exposeResolveMimeTypeFromFormat('mp3_44100_128'));
        $this->assertSame('audio/pcm', $model->exposeResolveMimeTypeFromFormat('pcm_22050'));
        $this->assertSame('audio/basic', $model->exposeResolveMimeTypeFromFormat('ulaw_8000'));
        $this->assertSame('audio/opus', $model->exposeResolveMimeTypeFromFormat('opus_48000_64'));
        $this->assertSame('audio/aac', $model->exposeResolveMimeTypeFromFormat('aac_44100_128'));
    }

    /**
     * Tests that API failure (non-200) throws an exception.
     */
    public function testApiFailureThrowsException(): void
    {
        $config = $this->createConfig();

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(401, [], '{"detail":"Unauthorized"}'));

        $model = $this->createModel($config);

        $this->expectException(ClientException::class);
        $model->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Routes voice-list requests to a JSON payload and everything else to audio.
     *
     * @param list<array<string, mixed>> $voices        Voices the account should report.
     * @param list<string>               $capturedUris  Populated with every request URI sent.
     * @return void
     */
    private function stubTransportWithVoiceList(array $voices, array &$capturedUris): void
    {
        $capturedUris = [];

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(
                static function (Request $request) use ($voices, &$capturedUris): Response {
                    $capturedUris[] = $request->getUri();

                    if (strpos($request->getUri(), '/voices') !== false) {
                        return new Response(
                            200,
                            ['Content-Type' => 'application/json'],
                            json_encode(['voices' => $voices, 'has_more' => false], JSON_THROW_ON_ERROR)
                        );
                    }

                    return new Response(200, ['Content-Type' => ['audio/mpeg']], 'audio-data');
                }
            );
    }

    /**
     * Tests that an unconfigured voice falls back to the account's default voice.
     */
    public function testDefaultVoiceIsUsedWhenNoneIsConfigured(): void
    {
        $capturedUris = [];
        $this->stubTransportWithVoiceList(
            [
                ['voice_id' => 'cloned-voice', 'name' => 'MyClone', 'category' => 'cloned'],
                ['voice_id' => 'premade-voice', 'name' => 'Rachel', 'category' => 'premade'],
            ],
            $capturedUris
        );

        $model = $this->createModel();
        $result = $model->convertTextToSpeechResult($this->createPrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertStringContainsString('/v2/voices', $capturedUris[0]);
        $this->assertStringContainsString('text-to-speech/premade-voice', $capturedUris[1]);
    }

    /**
     * Tests that an explicitly configured voice is used without any voice lookup.
     */
    public function testConfiguredVoiceSkipsTheVoiceLookup(): void
    {
        $capturedUris = [];
        $this->stubTransportWithVoiceList(
            [['voice_id' => 'premade-voice', 'name' => 'Rachel', 'category' => 'premade']],
            $capturedUris
        );

        $model = $this->createModel($this->createConfig('ExplicitVoice'));
        $model->convertTextToSpeechResult($this->createPrompt());

        $this->assertCount(1, $capturedUris);
        $this->assertStringContainsString('text-to-speech/ExplicitVoice', $capturedUris[0]);
    }

    /**
     * Tests that an account with no voices falls back to the hardcoded
     * default voice ("George") rather than failing.
     */
    public function testMissingVoiceIdFallsBackToDefaultVoiceWhenAccountHasNoVoices(): void
    {
        $capturedUris = [];
        $this->stubTransportWithVoiceList([], $capturedUris);

        $model = $this->createModel();
        $model->convertTextToSpeechResult($this->createPrompt());

        $this->assertCount(2, $capturedUris);
        $this->assertStringContainsString('/v2/voices', $capturedUris[0]);
        $this->assertStringContainsString(
            'text-to-speech/' . MockProviderForElevenLabsTextToSpeechModel::DEFAULT_VOICE_ID,
            $capturedUris[1]
        );
    }

    /**
     * Tests that a failing voice lookup still falls back to the hardcoded
     * default voice ("George") rather than failing the TTS request.
     */
    public function testMissingVoiceIdFallsBackToDefaultVoiceWhenLookupFails(): void
    {
        $capturedUris = [];

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(static function (Request $request) use (&$capturedUris): Response {
                $capturedUris[] = $request->getUri();

                if (strpos($request->getUri(), '/voices') !== false) {
                    return new Response(401, [], '{"detail":"Unauthorized"}');
                }

                return new Response(200, ['Content-Type' => ['audio/mpeg']], 'audio-data');
            });

        $model = $this->createModel();
        $model->convertTextToSpeechResult($this->createPrompt());

        $this->assertCount(2, $capturedUris);
        $this->assertStringContainsString(
            'text-to-speech/' . MockProviderForElevenLabsTextToSpeechModel::DEFAULT_VOICE_ID,
            $capturedUris[1]
        );
    }

    /**
     * Tests that an empty string voice ID falls back to the default voice.
     */
    public function testEmptyStringVoiceIdFallsBackToDefaultVoice(): void
    {
        $capturedUris = [];
        $this->stubTransportWithVoiceList([], $capturedUris);

        $config = ModelConfig::fromArray(['outputSpeechVoice' => '']);
        $model = $this->createModel($config);

        $this->assertSame(
            MockProviderForElevenLabsTextToSpeechModel::DEFAULT_VOICE_ID,
            $model->exposeGetVoiceId()
        );
    }

    /**
     * Tests that an explicitly configured voice ID always wins over defaults.
     */
    public function testExplicitVoiceIdWinsOverDefault(): void
    {
        putenv('ELEVENLABS_DEFAULT_VOICE_ID=env-voice-id');

        try {
            $config = $this->createConfig('ExplicitVoiceId');
            $model = $this->createModel($config);

            $this->assertSame('ExplicitVoiceId', $model->exposeGetVoiceId());
        } finally {
            putenv('ELEVENLABS_DEFAULT_VOICE_ID');
        }
    }

    /**
     * Tests that the ELEVENLABS_DEFAULT_VOICE_ID environment variable overrides
     * the hardcoded default voice, without any voice lookup request.
     */
    public function testEnvVarOverridesDefaultVoice(): void
    {
        putenv('ELEVENLABS_DEFAULT_VOICE_ID=env-voice-id');

        $this->mockHttpTransporter
            ->expects($this->never())
            ->method('send');

        try {
            $model = $this->createModel();

            $this->assertSame('env-voice-id', $model->exposeGetVoiceId());
        } finally {
            putenv('ELEVENLABS_DEFAULT_VOICE_ID');
        }
    }

    /**
     * Tests that empty text prompt throws InvalidArgumentException.
     */
    public function testEmptyPromptThrowsException(): void
    {
        $config = $this->createConfig();
        $model = $this->createModel($config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('text message');

        // Create a message with a file part (no text).
        $filePart = new MessagePart(new File(base64_encode('fake-audio'), 'audio/mpeg'));
        $model->convertTextToSpeechResult([
            new Message(MessageRoleEnum::user(), [$filePart]),
        ]);
    }

    /**
     * Tests that empty audio response body throws ResponseException.
     */
    public function testEmptyAudioResponseThrowsException(): void
    {
        $config = $this->createConfig();

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, [], null));

        $model = $this->createModel($config);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('audio response body was empty');
        $model->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Tests that token usage is always zero (ElevenLabs does not report tokens).
     */
    public function testTokenUsageIsZero(): void
    {
        $config = $this->createConfig();

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturn(new Response(200, [], 'audio-data'));

        $model = $this->createModel($config);
        $result = $model->convertTextToSpeechResult($this->createPrompt());

        $this->assertSame(0, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(0, $result->getTokenUsage()->getCompletionTokens());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * Tests extracting text from multiple messages concatenates them.
     */
    public function testExtractTextFromMultipleMessages(): void
    {
        $config = $this->createConfig();
        $model = $this->createModel($config);

        $messages = [
            new Message(MessageRoleEnum::user(), [new MessagePart('Hello')]),
            new Message(MessageRoleEnum::user(), [new MessagePart('World')]),
        ];

        $text = $model->exposeExtractTextFromPrompt($messages);
        $this->assertSame('Hello World', $text);
    }

    /**
     * Tests that the result audio file is marked as audio type.
     */
    public function testResultFileIsAudio(): void
    {
        $config = $this->createConfig();

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturn(new Response(200, [], 'audio-data'));

        $model = $this->createModel($config);
        $result = $model->convertTextToSpeechResult($this->createPrompt());

        $file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        $this->assertInstanceOf(File::class, $file);
        $this->assertTrue($file->isAudio());
    }

    // ------------------------------------------------------------------
    // Custom options
    // ------------------------------------------------------------------

    /**
     * Sends one request with the given config and returns its decoded body.
     *
     * @param ModelConfig $config The model configuration to use.
     * @return array<string, mixed> The decoded request body.
     */
    private function captureRequestBody(ModelConfig $config): array
    {
        $captured = null;

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(static function (Request $request) use (&$captured): Response {
                if ($captured === null) {
                    $captured = $request->getData();
                }
                return new Response(200, ['Content-Type' => ['audio/mpeg']], 'audio-data');
            });

        $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());

        return (array) $captured;
    }

    public function testUnrecognisedCustomOptionsReachTheRequestBody(): void
    {
        $body = $this->captureRequestBody($this->createConfig('v1', [
            'language_code'            => 'de',
            'seed'                     => 12345,
            'apply_text_normalization' => 'on',
        ]));

        $this->assertSame('de', $body['language_code']);
        $this->assertSame(12345, $body['seed']);
        $this->assertSame('on', $body['apply_text_normalization']);
    }

    public function testSpeedIsSentInsideVoiceSettings(): void
    {
        $body = $this->captureRequestBody($this->createConfig('v1', ['speed' => 1.15]));

        $this->assertSame(1.15, $body['voice_settings']['speed']);
        $this->assertArrayNotHasKey('speed', $body);
    }

    public function testSpeedIsAbsentWhenNotRequested(): void
    {
        $body = $this->captureRequestBody($this->createConfig('v1'));

        $this->assertArrayNotHasKey('speed', $body['voice_settings']);
    }

    public function testVoiceSettingsAndTopLevelOptionsAreRoutedSeparately(): void
    {
        $body = $this->captureRequestBody($this->createConfig('v1', [
            'stability'     => 0.9,
            'language_code' => 'fr',
        ]));

        $this->assertSame(0.9, $body['voice_settings']['stability']);
        $this->assertArrayNotHasKey('stability', $body);
        $this->assertSame('fr', $body['language_code']);
        $this->assertArrayNotHasKey('language_code', $body['voice_settings']);
    }

    public function testRequestBodyIsUnchangedWhenNoCustomOptionsAreSet(): void
    {
        $body = $this->captureRequestBody($this->createConfig('v1'));

        $this->assertSame(
            ['text', 'model_id', 'voice_settings', 'output_format'],
            array_keys($body)
        );
    }

    /**
     * @dataProvider reservedParameterProvider
     */
    public function testCustomOptionCollidingWithAProviderParameterThrows(string $reservedKey): void
    {
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')
            ->willReturn(new Response(200, [], 'audio-data'));

        $model = $this->createModel($this->createConfig('v1', [$reservedKey => 'hijacked']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reservedKey);
        $model->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedParameterProvider(): array
    {
        return [
            'text'           => ['text'],
            'model_id'       => ['model_id'],
            'voice_settings' => ['voice_settings'],
        ];
    }

    /**
     * @dataProvider providerManagedParameterProvider
     */
    public function testContinuityParametersAreRejectedRegardlessOfTextLength(string $key): void
    {
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter->method('send')
            ->willReturn(new Response(200, [], 'audio-data'));

        $model = $this->createModel($this->createConfig('v1', [$key => 'context']));

        // Short text is the case that would otherwise slip through, since nothing
        // is chunked and no collision occurs.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($key);
        $model->convertTextToSpeechResult($this->createPrompt('Short.'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function providerManagedParameterProvider(): array
    {
        return [
            'previous_text' => ['previous_text'],
            'next_text'     => ['next_text'],
        ];
    }

    // ------------------------------------------------------------------
    // Long-form narration
    // ------------------------------------------------------------------

    /**
     * Captures every request body sent, returning distinct audio per call.
     *
     * @param list<array<string, mixed>> $bodies Populated with each decoded request body.
     * @return void
     */
    private function captureAllRequestBodies(array &$bodies): void
    {
        $bodies = [];
        $counter = 0;

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(
                static function (Request $request) use (&$bodies, &$counter): Response {
                    $bodies[] = (array) $request->getData();
                    $counter++;
                    return new Response(200, ['Content-Type' => ['audio/mpeg']], 'audio-' . $counter . '|');
                }
            );
    }

    /**
     * Builds text guaranteed to exceed the model's limit.
     */
    private function longText(int $sentences = 4000): string
    {
        return trim(str_repeat('This is a sentence of moderate length. ', $sentences));
    }

    public function testTextWithinTheLimitStillIssuesExactlyOneRequest(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))
            ->convertTextToSpeechResult($this->createPrompt('Short enough.'));

        $this->assertCount(1, $bodies);
        $this->assertArrayNotHasKey('previous_text', $bodies[0]);
        $this->assertArrayNotHasKey('next_text', $bodies[0]);
    }

    public function testLongTextIsNarratedAcrossSeveralRequests(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))
            ->convertTextToSpeechResult($this->createPrompt($this->longText()));

        $this->assertGreaterThan(1, count($bodies));
    }

    public function testChunkTextRejoinsToTheOriginalPrompt(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $text = $this->longText();
        $this->createModel($this->createConfig('v1'))
            ->convertTextToSpeechResult($this->createPrompt($text));

        $rejoined = implode('', array_column($bodies, 'text'));
        $this->assertSame($text, $rejoined, 'Narrated text did not match the prompt.');
    }

    public function testEveryChunkStaysWithinTheModelLimit(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))
            ->convertTextToSpeechResult($this->createPrompt($this->longText()));

        // The stubbed model is eleven_multilingual_v2, whose measured limit is 10,000.
        foreach ($bodies as $body) {
            $this->assertLessThanOrEqual(10000, mb_strlen((string) $body['text']));
        }

        $this->assertGreaterThan(1, count($bodies), 'The text should have been split.');
    }

    public function testContinuityParametersBracketTheChunks(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))
            ->convertTextToSpeechResult($this->createPrompt($this->longText()));

        $last = count($bodies) - 1;

        $this->assertArrayNotHasKey('previous_text', $bodies[0]);
        $this->assertArrayHasKey('next_text', $bodies[0]);
        $this->assertArrayHasKey('previous_text', $bodies[$last]);
        $this->assertArrayNotHasKey('next_text', $bodies[$last]);

        // Each chunk's previous_text is genuinely the preceding chunk.
        for ($i = 1; $i <= $last; $i++) {
            $this->assertSame($bodies[$i - 1]['text'], $bodies[$i]['previous_text']);
        }
    }

    public function testReturnedAudioIsTheChunkResponsesInOrder(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $result = $this->createModel($this->createConfig('v1'))
            ->convertTextToSpeechResult($this->createPrompt($this->longText()));

        $file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        $this->assertInstanceOf(File::class, $file);

        $expected = '';
        for ($i = 1; $i <= count($bodies); $i++) {
            $expected .= 'audio-' . $i . '|';
        }

        $this->assertSame($expected, base64_decode((string) $file->getBase64Data()));
    }

    public function testResultIsASingleCandidateNotOnePerChunk(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $result = $this->createModel($this->createConfig('v1'))
            ->convertTextToSpeechResult($this->createPrompt($this->longText()));

        // toFile() reads candidates[0], so more than one candidate would hand the
        // caller only the first chunk while appearing successful.
        $this->assertCount(1, $result->getCandidates());
    }

    public function testUnjoinableFormatRaisesBeforeAnyRequestIsSent(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $config = $this->createConfig('v1', [], 'audio/ogg');
        $model = $this->createModel($config);

        try {
            $model->convertTextToSpeechResult($this->createPrompt($this->longText()));
            $this->fail('Expected an unjoinable output format to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('opus', $e->getMessage());
        }

        $this->assertCount(0, $bodies, 'No credits should be spent on audio that cannot be joined.');
    }

    public function testUnjoinableFormatIsStillFineForShortText(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1', [], 'audio/ogg'))
            ->convertTextToSpeechResult($this->createPrompt('Short enough.'));

        $this->assertCount(1, $bodies);
    }

    public function testFailurePartwayThroughDoesNotReturnTruncatedAudio(): void
    {
        $calls = 0;

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(static function () use (&$calls): Response {
                $calls++;
                if ($calls >= 2) {
                    return new Response(500, [], '{"detail":"boom"}');
                }
                return new Response(200, ['Content-Type' => ['audio/mpeg']], 'audio');
            });

        $model = $this->createModel($this->createConfig('v1'));

        $this->expectException(ServerException::class);
        $model->convertTextToSpeechResult($this->createPrompt($this->longText()));
    }

    public function testEmptyBodyOnALaterChunkRaises(): void
    {
        $calls = 0;

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(static function () use (&$calls): Response {
                $calls++;
                return new Response(200, ['Content-Type' => ['audio/mpeg']], $calls >= 2 ? '' : 'audio');
            });

        $model = $this->createModel($this->createConfig('v1'));

        $this->expectException(ResponseException::class);
        $model->convertTextToSpeechResult($this->createPrompt($this->longText()));
    }

    // ------------------------------------------------------------------
    // Per-chunk narration seam
    // ------------------------------------------------------------------

    public function testNarrateChunkIssuesExactlyOneRequestForTheGivenText(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))->narrateChunk('v1', 'One piece of text.');

        $this->assertCount(1, $bodies);
        $this->assertSame('One piece of text.', $bodies[0]['text']);
        $this->assertSame('eleven_multilingual_v2', $bodies[0]['model_id']);
        $this->assertArrayHasKey('voice_settings', $bodies[0]);
    }

    public function testNarrateChunkReturnsTheRawAudioBytes(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $model = $this->createModel($this->createConfig('v1'));

        $this->assertSame('audio-1|', $model->narrateChunk('v1', 'Text.'));
    }

    public function testNarrateChunkCarriesNeighboursWhenGiven(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))
            ->narrateChunk('v1', 'Middle.', 'Before.', 'After.');

        $this->assertSame('Before.', $bodies[0]['previous_text']);
        $this->assertSame('After.', $bodies[0]['next_text']);
    }

    public function testNarrateChunkOmitsContinuityWhenNoNeighboursGiven(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))->narrateChunk('v1', 'Alone.');

        $this->assertArrayNotHasKey('previous_text', $bodies[0]);
        $this->assertArrayNotHasKey('next_text', $bodies[0]);
    }

    /**
     * A job persists neighbours as strings, so the first and last chunk arrive
     * with empty ones rather than nulls. They must be treated as absent, or the
     * request carries a meaningless empty continuity parameter.
     */
    public function testNarrateChunkTreatsEmptyNeighboursAsAbsent(): void
    {
        $bodies = [];
        $this->captureAllRequestBodies($bodies);

        $this->createModel($this->createConfig('v1'))->narrateChunk('v1', 'Alone.', '', '');

        $this->assertArrayNotHasKey('previous_text', $bodies[0]);
        $this->assertArrayNotHasKey('next_text', $bodies[0]);
    }

    public function testNarrateChunkUsesTheVoiceItIsGivenRatherThanTheConfiguredOne(): void
    {
        $uris = [];

        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(static function (Request $request) use (&$uris): Response {
                $uris[] = $request->getUri();
                return new Response(200, ['Content-Type' => ['audio/mpeg']], 'audio');
            });

        $this->createModel($this->createConfig('configured-voice'))
            ->narrateChunk('job-voice', 'Text.');

        $this->assertStringContainsString('job-voice', $uris[0]);
        $this->assertStringNotContainsString('configured-voice', $uris[0]);
    }

    public function testNarrateChunkRaisesOnAnEmptyResponseBody(): void
    {
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->mockHttpTransporter
            ->method('send')
            ->willReturn(new Response(200, ['Content-Type' => ['audio/mpeg']], ''));

        $model = $this->createModel($this->createConfig('v1'));

        $this->expectException(ResponseException::class);
        $model->narrateChunk('v1', 'Text.');
    }

    public function testSplitAcceptsASmallerLimitThanTheModelAllows(): void
    {
        $text = $this->longText(20);
        $chunks = $this->createModel($this->createConfig('v1'))
            ->splitTextForRequests($text, 'mp3_44100_128', 200);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame($text, implode('', $chunks), 'Splitting lost or duplicated text.');

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, mb_strlen($chunk));
        }
    }

    /**
     * A limit above what the API accepts is never valid, so it is clamped rather
     * than trusted -- otherwise a job could talk the model into a request the
     * endpoint rejects outright.
     */
    public function testSplitClampsALimitAboveTheModelMaximum(): void
    {
        $chunks = $this->createModel($this->createConfig('v1'))
            ->splitTextForRequests($this->longText(), 'mp3_44100_128', 10000000);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(10000, mb_strlen($chunk));
        }
    }

    public function testSplitWithoutALimitStillUsesTheModelLimit(): void
    {
        $model = $this->createModel($this->createConfig('v1'));

        $this->assertSame(
            $model->splitTextForRequests($this->longText(), 'mp3_44100_128'),
            $model->splitTextForRequests($this->longText(), 'mp3_44100_128', 10000)
        );
    }
}
