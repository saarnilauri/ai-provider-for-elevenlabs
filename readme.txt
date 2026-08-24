=== AI Provider for ElevenLabs ===
Contributors: laurisaarni
Tags: ai, elevenlabs, text-to-speech, tts, connector
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 0.3.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent WordPress AI Client provider for ElevenLabs text-to-speech and sound effects generation.

== Description ==

This plugin provides a third-party ElevenLabs integration for the PHP AI Client SDK. It enables WordPress sites to use ElevenLabs models for text-to-speech conversion and sound effects generation.
It is not affiliated with, endorsed by, or sponsored by ElevenLabs.

**Features:**

* Text-to-speech conversion with high-quality ElevenLabs voices
* Sound effects generation from text descriptions
* Voice directory for discovering available voices (including cloned voices)
* Dynamic model discovery from the ElevenLabs API
* Automatic provider registration

**Available Capabilities:**

* `TEXT_TO_SPEECH_CONVERSION` -- convert text to speech using any ElevenLabs TTS model
* `SPEECH_GENERATION` -- generate sound effects from text prompts

**Requirements:**

* PHP 7.4 or higher
* PHP AI Client plugin must be installed and activated
* ElevenLabs API key

== Installation ==

1. Ensure the PHP AI Client plugin is installed and activated
2. Upload the plugin files to `/wp-content/plugins/ai-provider-for-elevenlabs/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure your ElevenLabs API key via the `ELEVENLABS_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get an ElevenLabs API key? =

Visit [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It provides the ElevenLabs-specific implementation that the PHP AI Client uses.

= How do I specify which voice to use? =

Set the `outputSpeechVoice` option in your `ModelConfig` to the voice ID. You can discover available voices using the `VoiceDirectory` class or the ElevenLabs voice library.

= What happens if I don't specify a voice? =

The provider falls back to the ElevenLabs premade voice "George" (`JBFqnCBsd6RMkjVDRZzb`), which is available on every account. You can change the default via the `ELEVENLABS_DEFAULT_VOICE_ID` environment variable or constant, the `ai_provider_for_elevenlabs_default_voice_id` option, or the `ai_provider_for_elevenlabs_default_voice_id` filter. An explicitly configured `outputSpeechVoice` always takes precedence.

= What audio formats are supported? =

The default output format is MP3 (mp3_44100_128). Other supported formats include PCM, ulaw, Opus, and AAC at various sample rates and bitrates.

== Changelog ==

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