<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Models;

use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for text-to-speech models used by the provider for ElevenLabs.
 *
 * Calls the ElevenLabs `POST /text-to-speech/{voice_id}` endpoint to convert
 * text into audio. The binary audio response is base64-encoded and returned as
 * an inline {@see File} in the result.
 *
 * @since 0.1.0
 */
class ProviderForElevenLabsTextToSpeechModel extends AbstractApiBasedModel implements
    TextToSpeechConversionModelInterface
{
    /**
     * Default voice settings applied when no custom values are provided.
     *
     * @since 0.1.0
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_VOICE_SETTINGS = [
        'stability'         => 0.5,
        'similarity_boost'  => 0.75,
        'style'             => 0.0,
        'use_speaker_boost' => true,
    ];

    /**
     * Default output format when no outputMimeType is configured.
     *
     * @since 0.1.0
     *
     * @var string
     */
    private const DEFAULT_OUTPUT_FORMAT = 'mp3_44100_128';

    /**
     * Map of MIME types to ElevenLabs output_format values.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    private const MIME_TYPE_TO_OUTPUT_FORMAT = [
        'audio/mpeg' => 'mp3_44100_128',
        'audio/mp3'  => 'mp3_44100_128',
        'audio/pcm'  => 'pcm_44100',
        'audio/wav'  => 'pcm_44100',
        'audio/ogg'  => 'opus_48000_128',
        'audio/opus' => 'opus_48000_128',
        'audio/aac'  => 'aac_44100_128',
    ];

    /**
     * Map of ElevenLabs output_format prefixes to MIME types.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    private const OUTPUT_FORMAT_PREFIX_TO_MIME = [
        'mp3'  => 'audio/mpeg',
        'pcm'  => 'audio/pcm',
        'ulaw' => 'audio/basic',
        'opus' => 'audio/opus',
        'aac'  => 'audio/aac',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        $text = $this->extractTextFromPrompt($prompt);
        $voiceId = $this->getVoiceId();
        $outputFormat = $this->resolveOutputFormat();
        $voiceSettings = $this->resolveVoiceSettings();

        $requestData = [
            'text'           => $text,
            'model_id'       => $this->metadata()->getId(),
            'voice_settings' => $voiceSettings,
            'output_format'  => $outputFormat,
        ];

        $request = new Request(
            HttpMethodEnum::POST(),
            ProviderForElevenLabs::url('text-to-speech/' . $voiceId),
            ['Content-Type' => 'application/json', 'Accept' => 'audio/mpeg'],
            $requestData,
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        $binaryData = $response->getBody();
        if ($binaryData === null || $binaryData === '') {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'text-to-speech/' . $voiceId,
                'The audio response body was empty.'
            );
        }

        $mimeType = $this->resolveMimeTypeFromFormat($outputFormat);
        $base64Data = base64_encode($binaryData);
        $audioFile = new File($base64Data, $mimeType);
        $parts = [new MessagePart($audioFile)];
        $message = new Message(MessageRoleEnum::model(), $parts);
        $candidate = new Candidate($message, FinishReasonEnum::stop());

        return new GenerativeAiResult(
            '',
            [$candidate],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            []
        );
    }

    /**
     * Extracts text content from the prompt messages.
     *
     * Concatenates all text parts from all user messages. Throws if no text is found.
     *
     * @since 0.1.0
     *
     * @param list<Message> $messages The prompt messages.
     * @return string The extracted text.
     * @throws InvalidArgumentException If no text content is found.
     */
    protected function extractTextFromPrompt(array $messages): string
    {
        $textParts = [];
        foreach ($messages as $message) {
            foreach ($message->getParts() as $part) {
                $text = $part->getText();
                if ($text !== null) {
                    $textParts[] = $text;
                }
            }
        }

        if ($textParts === []) {
            throw new InvalidArgumentException(
                'The prompt must contain at least one text message.'
            );
        }

        return implode(' ', $textParts);
    }

    /**
     * Gets the voice ID from the model configuration.
     *
     * @since 0.1.0
     *
     * @return string The voice ID.
     * @throws InvalidArgumentException If no voice ID is configured.
     */
    protected function getVoiceId(): string
    {
        $voiceId = $this->getConfig()->getOutputSpeechVoice();
        if ($voiceId === null || $voiceId === '') {
            throw new InvalidArgumentException(
                'The outputSpeechVoice option is required for ElevenLabs text-to-speech.'
            );
        }

        return $voiceId;
    }

    /**
     * Resolves the ElevenLabs output_format parameter.
     *
     * Uses outputMimeType from config if set, otherwise falls back to the default.
     *
     * @since 0.1.0
     *
     * @return string The ElevenLabs output_format value.
     */
    protected function resolveOutputFormat(): string
    {
        $customOptions = $this->getConfig()->getCustomOptions();
        if (isset($customOptions['output_format']) && is_string($customOptions['output_format'])) {
            return $customOptions['output_format'];
        }

        $mimeType = $this->getConfig()->getOutputMimeType();
        if ($mimeType !== null && isset(self::MIME_TYPE_TO_OUTPUT_FORMAT[$mimeType])) {
            return self::MIME_TYPE_TO_OUTPUT_FORMAT[$mimeType];
        }

        return self::DEFAULT_OUTPUT_FORMAT;
    }

    /**
     * Resolves voice settings by merging defaults with custom options.
     *
     * @since 0.1.0
     *
     * @return array<string, mixed> The voice settings.
     */
    protected function resolveVoiceSettings(): array
    {
        $customOptions = $this->getConfig()->getCustomOptions();

        $voiceSettings = self::DEFAULT_VOICE_SETTINGS;
        foreach (self::DEFAULT_VOICE_SETTINGS as $key => $default) {
            if (array_key_exists($key, $customOptions)) {
                $voiceSettings[$key] = $customOptions[$key];
            }
        }

        return $voiceSettings;
    }

    /**
     * Determines the MIME type from an ElevenLabs output_format string.
     *
     * @since 0.1.0
     *
     * @param string $outputFormat The ElevenLabs output_format value.
     * @return string The MIME type.
     */
    protected function resolveMimeTypeFromFormat(string $outputFormat): string
    {
        $prefix = explode('_', $outputFormat)[0];
        return self::OUTPUT_FORMAT_PREFIX_TO_MIME[$prefix] ?? 'audio/mpeg';
    }
}
