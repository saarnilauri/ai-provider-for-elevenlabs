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
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
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
     * @var ModelMetadata&\PHPUnit\Framework\MockObject\MockObject
     */
    private $modelMetadata;

    /**
     * @var ProviderMetadata&\PHPUnit\Framework\MockObject\MockObject
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
     * Tests that missing voice ID throws InvalidArgumentException.
     */
    public function testMissingVoiceIdThrowsException(): void
    {
        $model = $this->createModel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outputSpeechVoice');
        $model->convertTextToSpeechResult($this->createPrompt());
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
}
