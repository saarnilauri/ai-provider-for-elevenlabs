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
use WordPress\AiClient\Providers\Models\SpeechGeneration\Contracts\SpeechGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Sound generation model for ElevenLabs.
 *
 * Generates sound effects from a text description using the
 * ElevenLabs `/sound-generation` endpoint.
 *
 * @since 0.1.0
 */
class ProviderForElevenLabsSoundGenerationModel extends AbstractApiBasedModel implements SpeechGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateSpeechResult(array $prompt): GenerativeAiResult
    {
        $promptText = $this->extractPromptText($prompt);

        $params = $this->prepareSoundGenerationParams($promptText);

        $request = new Request(
            HttpMethodEnum::POST(),
            ProviderForElevenLabs::url('sound-generation'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);

        ResponseUtil::throwIfNotSuccessful($response);

        $binaryData = $response->getBody();
        if ($binaryData === null || $binaryData === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'sound-generation',
                'The audio response body was empty.'
            );
        }

        $base64Data = base64_encode($binaryData);
        $audioFile = new File($base64Data, 'audio/mpeg');
        $part = new MessagePart($audioFile);
        $message = new Message(MessageRoleEnum::model(), [$part]);
        $candidate = new Candidate($message, FinishReasonEnum::stop());

        return new GenerativeAiResult(
            'sound-generation',
            [$candidate],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Extracts the text prompt from the prompt messages.
     *
     * Expects a single user message containing a text part that describes
     * the desired sound effect.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The extracted text prompt.
     * @throws InvalidArgumentException If the prompt is empty or does not contain text.
     */
    protected function extractPromptText(array $prompt): string
    {
        if ($prompt === []) {
            throw new InvalidArgumentException(
                'The sound generation prompt cannot be empty.'
            );
        }

        $message = $prompt[0];
        $text = null;
        foreach ($message->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null) {
                break;
            }
        }

        if ($text === null || $text === '') {
            throw new InvalidArgumentException(
                'The sound generation prompt must contain a text description.'
            );
        }

        return $text;
    }

    /**
     * Builds the request parameters for the sound generation API.
     *
     * Merges the required `text` parameter with optional `duration_seconds`
     * and `prompt_influence` values from custom options.
     *
     * @since 0.1.0
     *
     * @param string $text The text description of the desired sound effect.
     * @return array<string, mixed> The request parameters.
     */
    protected function prepareSoundGenerationParams(string $text): array
    {
        $params = ['text' => $text];

        $customOptions = $this->getConfig()->getCustomOptions();

        if (isset($customOptions['duration_seconds']) && is_numeric($customOptions['duration_seconds'])) {
            $params['duration_seconds'] = (float) $customOptions['duration_seconds'];
        }

        if (isset($customOptions['prompt_influence']) && is_numeric($customOptions['prompt_influence'])) {
            $params['prompt_influence'] = (float) $customOptions['prompt_influence'];
        }

        return $params;
    }
}
