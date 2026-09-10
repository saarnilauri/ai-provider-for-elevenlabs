=== LS AI Provider for ElevenLabs ===
Contributors: laurisaarni, whyisjake
Tags: ai, elevenlabs, text-to-speech, tts, connector
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.0.2
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Independent WordPress AI Client provider for ElevenLabs text-to-speech and sound effects generation.

== Description ==

This plugin provides a third-party ElevenLabs integration for the PHP AI Client SDK. It enables WordPress sites to use ElevenLabs models for text-to-speech conversion and sound effects generation.
It is not affiliated with, endorsed by, or sponsored by ElevenLabs.
"LS" is the author's initials, Lauri Saarni, because the WordPress.org plugin directory requires a plugin name to begin with a distinctive identifier rather than a generic description.

The plugin has no admin screens of its own. It registers ElevenLabs with the AI Client so that WordPress core and any other plugin built on the AI Client can use it, and it never contacts ElevenLabs until such a request is actually made. See "External services" below.

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

* PHP 7.4 or higher
* The PHP AI Client SDK must be loadable. WordPress 7.0 and later bundle it in core; earlier WordPress needs it provided via Composer (it is an SDK, not a plugin)
* An ElevenLabs account and API key

== Installation ==

1. Ensure the PHP AI Client SDK is available (bundled in WordPress 7.0+)
2. Upload the plugin files to `/wp-content/plugins/ls-ai-provider-for-elevenlabs/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure your ElevenLabs API key in Settings > Connectors (WordPress 7.0+), or via the `ELEVENLABS_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get an ElevenLabs API key? =

Visit [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys) to create an account and generate an API key. Using this plugin requires an ElevenLabs account, and ElevenLabs bills you for the requests it makes on your behalf.

= Does this plugin work without the PHP AI Client SDK? =

No. This plugin contains only the ElevenLabs-specific implementation of the AI Client's provider interfaces, so the SDK has to be loadable for it to do anything. WordPress 7.0 and later bundle the SDK in core. On earlier WordPress, the SDK has to be provided by your project (for example through Composer); it is a library, not a plugin. When the SDK is missing, this plugin registers nothing and stays inert instead of erroring.

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

= Does the plugin send anything anywhere on its own? =

No. Nothing leaves your site until code on your site asks the AI Client for speech, sound, a model list, or a voice list. There is no telemetry, no analytics, and no phone-home. The "External services" section below documents every request the plugin can make.

== External services ==

This plugin relies on the ElevenLabs API (https://api.elevenlabs.io) to generate speech and sound. It is the service the plugin exists to integrate with, so the plugin cannot function without it. No request is ever made automatically: every one is the direct result of code on your site asking the AI Client for a generation or for provider metadata, and no request is made at all until an ElevenLabs API key is configured.

The following requests can be made, always over HTTPS and always with your ElevenLabs API key in the `xi-api-key` request header:

* `POST https://api.elevenlabs.io/v1/text-to-speech/{voice_id}` -- sent when text-to-speech conversion is requested. The text to be narrated, the selected voice ID and model ID, and any voice or output settings you configured are sent. Text longer than the model's per-request character limit is sent as several requests, each also carrying the neighbouring text so the voice keeps its intonation across the split.
* `POST https://api.elevenlabs.io/v1/sound-generation` -- sent when sound effect generation is requested. The text prompt describing the sound and any generation settings you configured are sent.
* `GET https://api.elevenlabs.io/v1/models` -- sent when the list of available models is requested, to discover which models your account can use, and when your API key is verified so that the Connectors screen can report whether it works. Only your API key is sent. When the model listing fails, the plugin falls back to a built-in model list; the result of a key verification is cached for 15 minutes so the request is not repeated on every page load.
* `GET https://api.elevenlabs.io/v2/voices` -- sent when the list of available voices is requested, including when a default voice has to be resolved automatically. Only your API key and pagination parameters are sent. The response is cached in a transient for 15 minutes per API key.

The service is provided by ElevenLabs (https://elevenlabs.io/). Your use of it is governed by their terms and privacy policy:

* Terms of Service: https://elevenlabs.io/terms-of-use
* Privacy Policy: https://elevenlabs.io/privacy-policy

== Changelog ==

= 1.0.2 =
* Rename the plugin to "LS AI Provider for ElevenLabs", with the permalink `ls-ai-provider-for-elevenlabs`. The WordPress.org plugin directory requires a plugin name to begin with a distinctive identifier, and "AI Provider" on its own is a generic description; "LS" is the author's initials
* Rename the bootstrap to `ls-ai-provider-for-elevenlabs.php` and the text domain to `ls-ai-provider-for-elevenlabs`, both of which have to match the permalink
* Keep the filter, option and transient names (`ai_provider_for_elevenlabs_*`) and the Composer package name (`saarnilauri/ai-provider-for-elevenlabs`) unchanged, so existing integrations and Composer installs are unaffected

= 1.0.1 =
* Verify the API key against ElevenLabs instead of only checking that one was entered, so Settings > Connectors reports whether the key actually works. A key rejected by ElevenLabs now shows as not connected, where any non-empty value previously showed as connected
* Treat a key scoped without the Models permission as working rather than broken. Such a key still drives text-to-speech, and the plugin already falls back to its built-in model list, so the connection check accepts it. The verification result is cached for 15 minutes, under a name derived from a hash of the key rather than the key itself
* Stop reading the core Connectors option (`connectors_ai_elevenlabs_api_key`) directly. WordPress passes the key to the AI Client itself, and the plugin already re-wraps it for the `xi-api-key` header ElevenLabs requires, so the direct read was redundant. This resolves a WordPress.org review finding about plugins handling AI Client credentials

= 1.0.0 =
* First release from the WordPress.org plugin directory
* Rename the plugin bootstrap from `plugin.php` to `ai-provider-for-elevenlabs.php`, so the plugin headers live in the file matching the plugin slug as the WordPress.org directory expects. Sites that installed an earlier build from the GitHub ZIP have to reactivate the plugin once after updating
* Document every ElevenLabs endpoint the plugin can call, what is sent to it and when, in a new "External services" section of the readme
* Correct the readme's claim that a separate "PHP AI Client plugin" has to be installed: the AI Client is an SDK, bundled in WordPress 7.0 and later
* Redraw the provider logo shown on the Connectors screen from the official ElevenLabs symbol, at the proportions and clear space their brand guidelines specify
* Add the WordPress.org directory banner and icon, built from the official ElevenLabs artwork in their monochrome palette, a WordPress Playground blueprint for the directory preview, and a Plugin Check run in continuous integration

= 0.4.0 =
* Long-form narration: text beyond the model's per-request character limit is split on paragraph and sentence boundaries, narrated across several requests carrying neighbouring text for prosody, and returned as one audio file (contributed by Jake Spurlock)
* Automatic voice selection: when no `outputSpeechVoice` and no explicit default are configured, a voice is discovered from the account's own voices, preferring premade ones, before falling back to "George" (contributed by Jake Spurlock)
* Voice directory: migrate from the deprecated `/v1/voices` endpoint to `/v2/voices` with full pagination, and cache the voice list per API key for 15 minutes (contributed by Jake Spurlock)
* Honour all `customOptions` in both the text-to-speech and sound generation models (e.g. `language_code`, `seed`, `speed`, `apply_text_normalization`); previously most keys were silently dropped (contributed by Jake Spurlock)
* Read each model's real per-request character limit from the live `/models` response, seeded from measured values, and add `eleven_v3` to the fallback model list (contributed by Jake Spurlock)
* Fix the voice directory staying unusable for the rest of the request when built before credentials were available (contributed by Jake Spurlock)
* Show the provider in the connector UI as "ElevenLabs" with a description and the official logo (contributed by Jake Spurlock)
* Exclude local credentials (`.env`, `.wp-env.override.json`) and dev artifacts from the release ZIP, verified by a CI canary check (contributed by Jake Spurlock)
* Add continuous integration (unit tests on PHP 7.4-8.4, phpcs, PHPStan, packaging leak check), a local `wp-env` environment, the GPL-2.0 license text, and fix test-suite autoloading on case-sensitive filesystems

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

= 1.0.2 =
Renamed to "LS AI Provider for ElevenLabs" to meet the directory's naming requirements. Filter, option and Composer package names are unchanged, so existing integrations keep working.

= 1.0.1 =
Settings > Connectors now verifies your ElevenLabs key with the service instead of only checking that one was entered, so a mistyped key is reported instead of appearing to work.

= 1.0.0 =
First WordPress.org release. The plugin bootstrap file was renamed to match the plugin slug, so if you installed an earlier build from GitHub, reactivate the plugin once after updating.

= 0.1.0 =
Initial release.
