=== AI Provider for ElevenLabs ===
Contributors: laurisaarni
Tags: ai, elevenlabs, text-to-speech, tts, sound-effects
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 0.1.0
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

= What audio formats are supported? =

The default output format is MP3 (mp3_44100_128). Other supported formats include PCM, ulaw, Opus, and AAC at various sample rates and bitrates.

== Changelog ==

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