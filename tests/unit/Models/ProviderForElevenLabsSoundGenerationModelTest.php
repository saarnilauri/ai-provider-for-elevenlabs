<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Unit tests for ProviderForElevenLabsSoundGenerationModel.
 *
 * @covers \AiProviderForElevenLabs\Models\ProviderForElevenLabsSoundGenerationModel
 */
class ProviderForElevenLabsSoundGenerationModelTest extends TestCase
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
        $this->modelMetadata->method('getId')->willReturn('elevenlabs-sound-generation');
        $this->providerMetadata = $this->createStub(ProviderMetadata::class);
        $this->providerMetadata->method('getName')->willReturn('AI Provider for ElevenLabs');
        $this->mockHttpTransporter = $this->createMock(HttpTransporterInterface::class);
        $this->mockRequestAuthentication = $this->createMock(RequestAuthenticationInterface::class);

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnCallback(static function (Request $request): Request {
                return $request->withHeader('xi-api-key', 'test-key');
            });
    }

    /**
     * Creates a model instance with optional config.
     */
    private function createModel(?ModelConfig $modelConfig = null): MockProviderForElevenLabsSoundGenerationModel
    {
        $model = new MockProviderForElevenLabsSoundGenerationModel(
            $this->modelMetadata,
            $this->providerMetadata,
            $this->mockHttpTransporter,
            $this->mockRequestAuthentication
        );

        if ($modelConfig !== null) {
            $model->setConfig($modelConfig);
        }

        return $model;
    }

    /**
     * Returns a simple user prompt.
     *
     * @return list<Message>
     */
    private function createPrompt(string $text = 'A thunderstorm with heavy rain'): array
    {
        return [new Message(MessageRoleEnum::user(), [new MessagePart($text)])];
    }

    // ------------------------------------------------------------------
    // Successful generation
    // ------------------------------------------------------------------

    public function testSuccessfulSoundGeneration(): void
    {
        $binaryAudio = 'fake-binary-audio-data';
        $response = new Response(200, ['Content-Type' => 'audio/mpeg'], $binaryAudio);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateSpeechResult($this->createPrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('sound-generation', $result->getId());
        $this->assertCount(1, $result->getCandidates());

        $audioFile = $result->toAudioFile();
        $this->assertSame('audio/mpeg', $audioFile->getMimeType());
        $this->assertTrue($audioFile->isAudio());
        $this->assertSame(base64_encode($binaryAudio), $audioFile->getBase64Data());
    }

    public function testBinaryResponseIsBase64Encoded(): void
    {
        $binaryAudio = "\xFF\xD8\xFF\xE0\x00\x10JFIF"; // arbitrary binary bytes
        $response = new Response(200, ['Content-Type' => 'audio/mpeg'], $binaryAudio);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateSpeechResult($this->createPrompt());

        $audioFile = $result->toFile();
        $decoded = base64_decode($audioFile->getBase64Data(), true);
        $this->assertSame($binaryAudio, $decoded);
    }

    // ------------------------------------------------------------------
    // Custom options
    // ------------------------------------------------------------------

    public function testCustomDurationAndPromptInfluenceAreSent(): void
    {
        $binaryAudio = 'audio-data';
        $response = new Response(200, ['Content-Type' => 'audio/mpeg'], $binaryAudio);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Request $request) {
                $data = $request->getData();
                return $data !== null
                    && isset($data['text'])
                    && $data['text'] === 'A door creaking open'
                    && isset($data['duration_seconds'])
                    && abs($data['duration_seconds'] - 3.0) < 0.001
                    && isset($data['prompt_influence'])
                    && abs($data['prompt_influence'] - 0.5) < 0.001;
            }))
            ->willReturn($response);

        $config = ModelConfig::fromArray([
            'customOptions' => [
                'duration_seconds' => 3.0,
                'prompt_influence' => 0.5,
            ],
        ]);

        $model = $this->createModel($config);
        $model->generateSpeechResult($this->createPrompt('A door creaking open'));
    }

    public function testPrepareSoundGenerationParamsWithoutCustomOptions(): void
    {
        $model = $this->createModel();

        $params = $model->exposePrepareSoundGenerationParams('A thunderstorm');

        $this->assertSame(['text' => 'A thunderstorm'], $params);
    }

    public function testPrepareSoundGenerationParamsWithCustomOptions(): void
    {
        $config = ModelConfig::fromArray([
            'customOptions' => [
                'duration_seconds' => 5.0,
                'prompt_influence' => 0.3,
            ],
        ]);

        $model = $this->createModel($config);

        $params = $model->exposePrepareSoundGenerationParams('A door creaking');

        $this->assertSame('A door creaking', $params['text']);
        $this->assertEqualsWithDelta(5.0, $params['duration_seconds'], 0.001);
        $this->assertEqualsWithDelta(0.3, $params['prompt_influence'], 0.001);
    }

    // ------------------------------------------------------------------
    // API failure handling
    // ------------------------------------------------------------------

    public function testApiFailureThrowsException(): void
    {
        $errorResponse = new Response(
            401,
            ['Content-Type' => 'application/json'],
            '{"detail":{"status":"unauthorized"}}'
        );

        $this->mockHttpTransporter
            ->method('send')
            ->willReturn($errorResponse);

        $model = $this->createModel();

        $this->expectException(ClientException::class);
        $model->generateSpeechResult($this->createPrompt());
    }

    public function testEmptyResponseBodyThrowsException(): void
    {
        $response = new Response(200, ['Content-Type' => 'audio/mpeg'], '');

        $this->mockHttpTransporter
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();

        $this->expectException(\RuntimeException::class);
        $model->generateSpeechResult($this->createPrompt());
    }

    // ------------------------------------------------------------------
    // Prompt validation
    // ------------------------------------------------------------------

    public function testEmptyPromptThrowsException(): void
    {
        $model = $this->createModel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        $model->generateSpeechResult([]);
    }

    public function testPromptWithoutTextThrowsException(): void
    {
        $model = $this->createModel();

        // Create a message with a file part instead of text.
        $filePart = new MessagePart(new \WordPress\AiClient\Files\DTO\File(
            'https://example.com/audio.mp3'
        ));
        $message = new Message(MessageRoleEnum::user(), [$filePart]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('text description');
        $model->generateSpeechResult([$message]);
    }
}
