<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Models;

use AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use AiProviderForElevenLabs\Text\TextChunker;
use AiProviderForElevenLabs\Voices\VoiceDirectory;
use Exception;
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
 * Text longer than the model's per-request character limit is narrated across
 * several requests and joined into one audio file. The per-chunk seams
 * ({@see self::narrateChunk()}, {@see self::splitTextForRequests()}) are public
 * so that a separate plugin can drive narration chunk by chunk in background
 * jobs without this package depending on any job infrastructure.
 *
 * Long-form narration and custom option handling were contributed by
 * Jake Spurlock (https://github.com/whyisjake/ai-provider-for-elevenlabs).
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
     * Custom option keys that belong inside `voice_settings` rather than at the
     * top level of the request body.
     *
     * `speed` is accepted but deliberately has no entry in
     * {@see self::DEFAULT_VOICE_SETTINGS}, so it is sent only when a caller asks
     * for it rather than pinning a value the API would otherwise choose.
     *
     * @since 0.4.0
     *
     * @var list<string>
     */
    private const VOICE_SETTING_KEYS = [
        'stability',
        'similarity_boost',
        'style',
        'use_speaker_boost',
        'speed',
    ];

    /**
     * Body parameters the provider manages and a caller may not set.
     *
     * These carry the neighbouring text across chunk boundaries when long input
     * is narrated in several requests. They are reserved unconditionally rather
     * than only while chunking, so the contract does not change shape with the
     * length of the input: allowing them for short text and rejecting them for
     * long text would be surprising in exactly the case that is hardest to
     * reproduce.
     *
     * @since 0.4.0
     *
     * @var list<string>
     */
    private const PROVIDER_MANAGED_KEYS = [
        'previous_text',
        'next_text',
    ];

    /**
     * Output format prefixes whose audio can be concatenated into one file.
     *
     * MP3 is frame-based and the raw formats are headerless, so joining their
     * bytes yields a playable file. Opus is carried in an Ogg container with
     * per-stream headers, and AAC has not been confirmed to be ADTS-framed
     * rather than MP4-contained, so both are excluded: emitting audio that is
     * subtly broken is worse than refusing.
     *
     * @since 0.4.0
     *
     * @var list<string>
     */
    private const JOINABLE_FORMAT_PREFIXES = [
        'mp3',
        'pcm',
        'ulaw',
        'alaw',
    ];

    /**
     * Default voice ID used when no other default can be resolved.
     *
     * This is "George", one of the ElevenLabs premade voices. Premade voice
     * IDs are shared across all ElevenLabs accounts, so this default is
     * always available.
     *
     * @since 0.3.0
     *
     * @var string
     */
    public const DEFAULT_VOICE_ID = 'JBFqnCBsd6RMkjVDRZzb';

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
     * Lazily built voice directory used to resolve a default voice.
     *
     * @since 0.4.0
     *
     * @var VoiceDirectory|null
     */
    private ?VoiceDirectory $voiceDirectory = null;

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

        $chunks = $this->splitTextForRequests($text, $outputFormat);
        $chunkCount = count($chunks);

        $binaryData = '';
        foreach ($chunks as $index => $chunk) {
            /*
             * Give the model the neighbouring text so prosody carries across a
             * seam. Only meaningful when the text was actually split, so a
             * request that fits stays byte-identical to before.
             */
            $binaryData .= $this->narrateChunk(
                $voiceId,
                $chunk,
                $chunkCount > 1 && $index > 0 ? $chunks[$index - 1] : null,
                $chunkCount > 1 && $index < $chunkCount - 1 ? $chunks[$index + 1] : null,
                $outputFormat
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
     * Narrates one piece of text and returns its audio bytes.
     *
     * This is the seam all narration paths share. The synchronous path walks
     * the chunk list and calls it once per chunk; a background-processing
     * plugin can call it for a single chunk, having persisted the voice and
     * format when the work was queued. Sharing one implementation is what
     * stops such paths drifting apart, which is why it is public.
     *
     * @since 0.4.0
     *
     * @param string      $voiceId      The voice to synthesise with.
     * @param string      $chunk        The text for this request.
     * @param string|null $previousText Text immediately before, for prosody.
     * @param string|null $nextText     Text immediately after, for prosody.
     * @param string|null $outputFormat Resolved format, or null to resolve now.
     * @return string The raw audio bytes.
     * @throws ResponseException If the response carries no audio.
     */
    public function narrateChunk(
        string $voiceId,
        string $chunk,
        ?string $previousText = null,
        ?string $nextText = null,
        ?string $outputFormat = null
    ): string {
        $outputFormat = $outputFormat ?? $this->resolveOutputFormat();

        $params = $this->buildRequestParams($chunk, $outputFormat);

        if ($previousText !== null && $previousText !== '') {
            $params['previous_text'] = $previousText;
        }

        if ($nextText !== null && $nextText !== '') {
            $params['next_text'] = $nextText;
        }

        return $this->sendSpeechRequest($voiceId, $params);
    }

    /**
     * Builds the request body for one piece of text.
     *
     * @since 0.4.0
     *
     * @param string $text         The text for this request.
     * @param string $outputFormat The resolved ElevenLabs output format.
     * @return array<string, mixed> The request body.
     * @throws InvalidArgumentException If a custom option collides with a provider-set key.
     */
    protected function buildRequestParams(string $text, string $outputFormat): array
    {
        return $this->applyCustomOptions([
            'text'           => $text,
            'model_id'       => $this->metadata()->getId(),
            'voice_settings' => $this->resolveVoiceSettings(),
            'output_format'  => $outputFormat,
        ]);
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
     * Gets the voice ID to synthesise with.
     *
     * ElevenLabs requires a voice ID in the request path, but the AI Client's
     * `outputSpeechVoice` option is optional, so a caller that simply prompts
     * the provider supplies no voice at all. Rather than fail that case, fall
     * back to a default via {@see self::resolveDefaultVoiceId()}.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can resolve the voice once and persist it alongside queued work.
     *
     * @since 0.1.0
     * @since 0.3.0 Falls back to a default voice instead of throwing an exception.
     * @since 0.4.0 Discovers a default voice from the account's own voices.
     *
     * @return string The voice ID.
     */
    public function getVoiceId(): string
    {
        $voiceId = $this->getConfig()->getOutputSpeechVoice();
        if ($voiceId !== null && trim($voiceId) !== '') {
            return trim($voiceId);
        }

        return $this->resolveDefaultVoiceId();
    }

    /**
     * Resolves the default voice ID when none is configured.
     *
     * The default is resolved from (in order): the
     * `ELEVENLABS_DEFAULT_VOICE_ID` environment variable, the
     * `ELEVENLABS_DEFAULT_VOICE_ID` constant, the
     * `ai_provider_for_elevenlabs_default_voice_id` WordPress option, a voice
     * discovered from the account's own voices (preferring premade voices, via
     * {@see VoiceDirectory::getDefaultVoiceId()}), and finally the hardcoded
     * {@see self::DEFAULT_VOICE_ID}. The resolved default is then passed
     * through the `ai_provider_for_elevenlabs_default_voice_id` WordPress
     * filter. The WordPress option and filter only apply when WordPress is
     * loaded; as a plain Composer package the remaining steps still resolve.
     *
     * The account-voice discovery step was contributed by Jake Spurlock
     * (https://github.com/whyisjake/ai-provider-for-elevenlabs).
     *
     * @since 0.3.0
     * @since 0.4.0 Discovers a default voice from the account's own voices.
     *
     * @return string The default voice ID.
     */
    protected function resolveDefaultVoiceId(): string
    {
        $voiceId = '';

        $envVoiceId = getenv('ELEVENLABS_DEFAULT_VOICE_ID');
        if (is_string($envVoiceId) && trim($envVoiceId) !== '') {
            $voiceId = trim($envVoiceId);
        }

        if ($voiceId === '' && defined('ELEVENLABS_DEFAULT_VOICE_ID')) {
            $constantVoiceId = constant('ELEVENLABS_DEFAULT_VOICE_ID');
            if (is_string($constantVoiceId) && trim($constantVoiceId) !== '') {
                $voiceId = trim($constantVoiceId);
            }
        }

        if ($voiceId === '' && function_exists('get_option')) {
            $optionVoiceId = get_option('ai_provider_for_elevenlabs_default_voice_id', '');
            if (is_string($optionVoiceId) && trim($optionVoiceId) !== '') {
                $voiceId = trim($optionVoiceId);
            }
        }

        if ($voiceId === '') {
            try {
                $accountVoiceId = $this->voiceDirectory()->getDefaultVoiceId();
            } catch (Exception $e) {
                // The account lookup is best-effort; the hardcoded premade
                // voice below is always available.
                $accountVoiceId = null;
            }

            if ($accountVoiceId !== null && $accountVoiceId !== '') {
                $voiceId = $accountVoiceId;
            }
        }

        if ($voiceId === '') {
            $voiceId = self::DEFAULT_VOICE_ID;
        }

        if (function_exists('apply_filters')) {
            /**
             * Filters the default voice ID used when no outputSpeechVoice is configured.
             *
             * @since 0.3.0
             *
             * @param string $voiceId The resolved default voice ID.
             */
            $filteredVoiceId = apply_filters('ai_provider_for_elevenlabs_default_voice_id', $voiceId);
            if (is_string($filteredVoiceId) && trim($filteredVoiceId) !== '') {
                $voiceId = trim($filteredVoiceId);
            }
        }

        return $voiceId;
    }

    /**
     * Splits the prompt text into the pieces each request will carry.
     *
     * Text that already fits produces a single piece, so the common case issues
     * exactly one request with the body it always had.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can chunk text up front and queue each piece as its own unit of work.
     *
     * @since 0.4.0
     *
     * @param string   $text         The full prompt text.
     * @param string   $outputFormat The resolved ElevenLabs output format.
     * @param int|null $limit        Chunk size to use, or null for the model's own limit.
     * @return list<string> The text for each request, in order.
     * @throws InvalidArgumentException If splitting is required but the format cannot be joined.
     */
    public function splitTextForRequests(string $text, string $outputFormat, ?int $limit = null): array
    {
        $modelLimit = $this->maxTextLength();

        /*
         * A caller may ask for smaller pieces than the model allows: a
         * background job sizes chunks by how long one request takes, not by
         * what the API will accept. Asking for larger is never valid, so clamp
         * rather than trust the caller to have checked.
         */
        $limit = $limit === null ? $modelLimit : min(max($limit, 1), $modelLimit);

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        /*
         * Checked before any request is sent. Discovering mid-narration that the
         * pieces cannot be reassembled would waste the credits already spent.
         */
        $this->assertFormatIsJoinable($outputFormat, $limit);

        $chunks = TextChunker::split($text, $limit);

        return $chunks === [] ? [$text] : $chunks;
    }

    /**
     * Returns the character limit for the model in use.
     *
     * @since 0.4.0
     *
     * @return int The maximum characters accepted in a single request.
     */
    protected function maxTextLength(): int
    {
        $directory = ProviderForElevenLabs::modelMetadataDirectory();

        if ($directory instanceof ProviderForElevenLabsModelMetadataDirectory) {
            return $directory->getMaxTextLength($this->metadata()->getId());
        }

        return ProviderForElevenLabsModelMetadataDirectory::DEFAULT_MAX_TEXT_LENGTH;
    }

    /**
     * Fails when audio in the given format could not be reassembled.
     *
     * @since 0.4.0
     *
     * @param string $outputFormat The resolved ElevenLabs output format.
     * @param int    $limit        The character limit that forced the split.
     * @return void
     * @throws InvalidArgumentException If the format cannot be safely joined.
     */
    protected function assertFormatIsJoinable(string $outputFormat, int $limit): void
    {
        $prefix = explode('_', $outputFormat)[0];

        if (in_array($prefix, self::JOINABLE_FORMAT_PREFIXES, true)) {
            return;
        }

        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message, never echoed.
        throw new InvalidArgumentException(
            sprintf(
                'Text longer than %d characters is narrated in several requests, but "%s" audio '
                . 'cannot be joined back into a single file. Choose an MP3 or PCM output format, '
                . 'or shorten the text.',
                $limit,
                $outputFormat
            )
        );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    /**
     * Sends one speech request and returns its audio bytes.
     *
     * @since 0.4.0
     *
     * @param string               $voiceId The voice to synthesise with.
     * @param array<string, mixed> $params  The request body.
     * @return string The raw audio bytes.
     * @throws ResponseException If the response carries no audio.
     */
    protected function sendSpeechRequest(string $voiceId, array $params): string
    {
        $request = new Request(
            HttpMethodEnum::POST(),
            ProviderForElevenLabs::url('text-to-speech/' . $voiceId),
            ['Content-Type' => 'application/json', 'Accept' => 'audio/mpeg'],
            $params,
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);

        ResponseUtil::throwIfNotSuccessful($response);

        $binaryData = $response->getBody();
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message, never echoed.
        if ($binaryData === null || $binaryData === '') {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'text-to-speech/' . $voiceId,
                'The audio response body was empty.'
            );
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

        return $binaryData;
    }

    /**
     * Gets a voice directory backed by this model's transport and credentials.
     *
     * Built from the model's own transporter and authentication rather than the
     * provider's shared instance, so it is always configured whenever the model
     * itself is able to make requests.
     *
     * @since 0.4.0
     *
     * @return VoiceDirectory The voice directory.
     */
    protected function voiceDirectory(): VoiceDirectory
    {
        if ($this->voiceDirectory === null) {
            $voiceDirectory = new VoiceDirectory();
            $voiceDirectory->setHttpTransporter($this->getHttpTransporter());
            $voiceDirectory->setRequestAuthentication($this->getRequestAuthentication());

            $this->voiceDirectory = $voiceDirectory;
        }

        return $this->voiceDirectory;
    }

    /**
     * Resolves the ElevenLabs output_format parameter.
     *
     * Uses outputMimeType from config if set, otherwise falls back to the default.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can resolve the format once and persist it alongside queued work.
     *
     * @since 0.1.0
     *
     * @return string The ElevenLabs output_format value.
     */
    public function resolveOutputFormat(): string
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
        foreach (self::VOICE_SETTING_KEYS as $key) {
            if (array_key_exists($key, $customOptions)) {
                $voiceSettings[$key] = $customOptions[$key];
            }
        }

        return $voiceSettings;
    }

    /**
     * Merges caller-supplied custom options into the request body.
     *
     * The model advertises `OptionEnum::customOptions()`, which promises callers
     * that provider-specific parameters reach the API. Only a handful were
     * honoured previously and the rest were dropped silently, so options such as
     * `language_code`, `seed`, and `apply_text_normalization` had no way through.
     *
     * Voice settings and `output_format` are excluded here because they are
     * consumed elsewhere: the former nests under `voice_settings`, the latter
     * selects the audio encoding.
     *
     * @since 0.4.0
     *
     * @param array<string, mixed> $params Request parameters the provider has already set.
     * @return array<string, mixed> The parameters with custom options merged in.
     * @throws InvalidArgumentException If a custom option collides with a provider-set parameter.
     */
    protected function applyCustomOptions(array $params): array
    {
        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (in_array($key, self::VOICE_SETTING_KEYS, true) || $key === 'output_format') {
                continue;
            }

            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception messages, never echoed.
            if (in_array($key, self::PROVIDER_MANAGED_KEYS, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" is managed by the provider, which sets it when long '
                        . 'text is narrated across several requests.',
                        $key
                    )
                );
            }

            if (array_key_exists($key, $params)) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with a parameter set by the provider.', $key)
                );
            }
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Determines the MIME type from an ElevenLabs output_format string.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can label persisted audio with the correct MIME type.
     *
     * @since 0.1.0
     *
     * @param string $outputFormat The ElevenLabs output_format value.
     * @return string The MIME type.
     */
    public function resolveMimeTypeFromFormat(string $outputFormat): string
    {
        $prefix = explode('_', $outputFormat)[0];
        return self::OUTPUT_FORMAT_PREFIX_TO_MIME[$prefix] ?? 'audio/mpeg';
    }
}
