=== AI Provider for ElevenLabs ===
Contributors: laurisaarni, whyisjake
Tags: ai, elevenlabs, text-to-speech, tts, connector
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 0.4.0
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WordPress AI Client provider for ElevenLabs text-to-speech and sound effects generation.

== Description ==

This plugin provides a third-party ElevenLabs integration for the PHP AI Client SDK. It enables WordPress sites to use ElevenLabs models for text-to-speech conversion and sound effects generation.
It is not affiliated with, endorsed by, or sponsored by ElevenLabs.

**Features:**

* Text-to-speech conversion with high-quality ElevenLabs voices
* Automatic voice selection -- a prompt works without configuring a voice ID first
* Long-form narration -- text beyond the model's per-request limit is narrated across several requests and returned as one audio file
* Sound effects generation from text descriptions
* Voice directory for discovering available voices (including cloned voices), cached per API key
* Dynamic model discovery from the ElevenLabs API
* Automatic provider registration

**Available Capabilities:**

* `TEXT_TO_SPEECH_CONVERSION` -- convert text to speech using any ElevenLabs TTS model
* `SPEECH_GENERATION` -- generate sound effects from text prompts

**Requirements:**

* PHP 8.1 or higher. WordPress itself allows 7.4, but classifies it as insecure and unsupported, and 7.4 has had no security support since November 2022.
* The PHP AI Client SDK must be loadable. WordPress 7.0 and later bundle it in core; earlier WordPress needs it provided via Composer (it is an SDK, not a plugin)
* ElevenLabs API key

== Installation ==

1. Ensure the PHP AI Client SDK is available (bundled in WordPress 7.0+)
2. Upload the plugin files to `/wp-content/plugins/ai-provider-for-elevenlabs/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure your ElevenLabs API key in Settings > Connectors (WordPress 7.0+), or via the `ELEVENLABS_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get an ElevenLabs API key? =

Visit [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It provides the ElevenLabs-specific implementation that the PHP AI Client uses.

= How do I specify which voice to use? =

Set the `outputSpeechVoice` option in your `ModelConfig` to the voice ID. You can discover available voices using the `VoiceDirectory` class or the ElevenLabs voice library.

= What happens if I don't specify a voice? =

The provider resolves a default: the `ELEVENLABS_DEFAULT_VOICE_ID` environment variable or constant, the `ai_provider_for_elevenlabs_default_voice_id` option, a voice discovered from your own ElevenLabs account (preferring premade voices; requires the Voices permission on the API key), and finally the premade voice "George" (`JBFqnCBsd6RMkjVDRZzb`), which is available on every account. The result passes through the `ai_provider_for_elevenlabs_default_voice_id` filter. An explicitly configured `outputSpeechVoice` always takes precedence.

= Can it narrate a whole post? =

Text longer than the model's per-request character limit is split on paragraph and sentence boundaries, narrated in several requests, and returned as one audio file. Note that narration is slow (roughly 90 to 95 characters per second) and runs inside one PHP request, so very long text can exceed the PHP execution time limit. Each chunk is billed as its own API request. See the README for details and for the public per-chunk methods a background-processing plugin can build on.

= Why does the AI plugin say there is no valid AI Connector? =

The WordPress.org AI plugin treats a connector as valid only when it can generate text, and ElevenLabs generates speech and sound. The warning is expected and does not affect this provider; add a text-generation connector alongside it to satisfy the AI plugin's own features.

= What audio formats are supported? =

The default output format is MP3 (mp3_44100_128). Other supported formats include PCM, ulaw, Opus, and AAC at various sample rates and bitrates. When long text has to be split across requests, only formats whose audio can be joined are allowed (MP3, PCM, ulaw, alaw).

== Changelog ==

= Unreleased =
* **Breaking:** raise the minimum PHP version to 8.1. WordPress core still allows 7.4, but reports it as insecure and unsupported and recommends 8.3, and 7.4 has had no security support since November 2022. Sites on PHP below 8.1 will not be offered this update
* Test on PHP 8.1 through 8.5, replacing the 7.4-8.4 matrix, and drop the PHPCompatibility exclusions for `array_is_list`, `str_contains`, `str_starts_with` and `str_ends_with`, which are all native as of 8.1
* Move the PHPUnit configuration to the 10.5 schema (`<source>` instead of `<coverage processUncoveredFiles>`, `cacheDirectory` instead of `cacheResultFile`), so the suite runs without deprecation warnings on the PHPUnit 10 already allowed by composer.json

= 0.4.0 =
* Long-form narration: text beyond the model's per-request character limit is split on paragraph and sentence boundaries, narrated across several requests carrying neighbouring text for prosody, and returned as one audio file (contributed by Jake Spurlock)
* Automatic voice selection: when no `outputSpeechVoice` and no explicit default are configured, a voice is discovered from the account's own voices, preferring premade ones, before falling back to "George" (contributed by Jake Spurlock)
* Voice directory: migrate from the deprecated `/v1/voices` endpoint to `/v2/voices` with full pagination, and cache the voice list per API key for 15 minutes (contributed by Jake Spurlock)
* Honour all `customOptions` in both the text-to-speech and sound generation models (e.g. `language_code`, `seed`, `speed`, `apply_text_normalization`); previously most keys were silently dropped (contributed by Jake Spurlock)
* Read each model's real per-request character limit from the live `/models` response, seeded from measured values, and add `eleven_v3` to the fallback model list (contributed by Jake Spurlock)
* Fix the voice directory staying unusable for the rest of the request when built before credentials were available (contributed by Jake Spurlock)
* Show the provider in the connector UI as "ElevenLabs" with a description and the official logo (contributed by Jake Spurlock)
* Exclude local credentials (`.env`, `.wp-env.override.json`) and dev artifacts from the release ZIP, verified by a CI canary check (contributed by Jake Spurlock)
* Add continuous integration (unit tests on PHP 8.1-8.5, phpcs, PHPStan, packaging leak check), a local `wp-env` environment, the GPL-2.0 license text, and fix test-suite autoloading on case-sensitive filesystems

= 0.3.0 =
* Declare inline `outputFileType` support for text-to-speech and sound generation models, so support checks like `isSupportedForTextToSpeechConversion()` pass when callers request inline output (fixes compatibility with the WordPress AI plugin's Text to Speech experiment)
* Fall back to the premade "George" voice when no `outputSpeechVoice` is configured, instead of throwing an exception; the default can be overridden via the `ELEVENLABS_DEFAULT_VOICE_ID` environment variable or constant, the `ai_provider_for_elevenlabs_default_voice_id` option, or the `ai_provider_for_elevenlabs_default_voice_id` filter

= 0.2.0 =
* WordPress 7.0 compatibility: read the API key from the core Connectors option (`connectors_ai_elevenlabs_api_key`, Settings > Connectors)
* WordPress 7.0 compatibility: move the xi-api-key authentication restore hook to init priority 21, after core's Connectors credential pass (init 20) which overwrites provider auth with generic Bearer authentication
* Fix API key validation on the WordPress 7.0 Connectors page: the availability check now also considers the registry request authentication and the Connectors/legacy credential options, instead of only the ELEVENLABS_API_KEY environment variable or constant

= 0.1.2 =
* Fix issue if API key not yet set in options table

= 0.1.1 =
* Fix API key registration, if the API key is not set in constant, but in the connectors page in WordPress admin
* Add a workaround to restore ElevenLabs authentication when the AI client initiation overwrites it with default auth.

= 0.1.0 =
* Initial release
* Text-to-speech conversion with ElevenLabs TTS models
* Sound effects generation from text prompts
* Voice directory for listing and discovering available voices
* Dynamic model discovery from the ElevenLabs API
* Custom voice settings support (stability, similarity_boost, style, use_speaker_boost)
* Multiple output format support (MP3, PCM, Opus, AAC, ulaw)

== Upgrade Notice ==

= 0.1.0 =
Initial release.