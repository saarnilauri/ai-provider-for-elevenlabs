# AI Provider for ElevenLabs

A third-party provider for [ElevenLabs](https://elevenlabs.io/) in the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

This project is independent and is not affiliated with, endorsed by, or sponsored by ElevenLabs.
`assets/images/elevenlabs.svg` is the official ElevenLabs symbol from their
[brand kit](https://elevenlabs.io/brand), used unmodified to identify the provider.
The ElevenLabs name and logo are trademarks of ElevenLabs.

## Features

- **Text-to-Speech** -- high-quality voice synthesis with many voices and models
- **Automatic voice selection** -- a prompt works without configuring a voice ID first
- **Long-form narration** -- text beyond the model's per-request limit is narrated across
  several requests and returned as one audio file ([caveats](#long-form-narration))
- **Sound Effects Generation** -- generate sound effects from text prompts
- **Voice Directory** -- list and discover available voices, including cloned voices, cached per API key
- Automatic provider registration in WordPress
- Dynamic model discovery from the ElevenLabs API

## Requirements

- PHP 7.4 or higher, with the `mbstring` extension (used when splitting long text for narration; present on virtually every WordPress host)
- The [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK, ^1.4, must be loadable:
    - **WordPress 7.0 and later** bundle it in core (`wp-includes/php-ai-client/`). Nothing to install.
    - **Earlier WordPress** does not. The SDK is a Composer package, not a plugin -- there is
      nothing to install from the plugin directory -- so it has to be provided by something
      else on the site that requires `wordpress/php-ai-client`.

If the SDK is not available, this plugin registers nothing and stays inert.

## Installation

### As a Composer Package

```bash
composer require saarnilauri/ai-provider-for-elevenlabs
```

The Composer distribution is intended for library usage and excludes `ai-provider-for-elevenlabs.php`.

### As a WordPress Plugin

1. Download `ai-provider-for-elevenlabs.zip` from [GitHub Releases](https://github.com/saarnilauri/ai-provider-for-elevenlabs/releases) (do not use GitHub "Source code" archives)
2. Upload the ZIP in WordPress admin via Plugins > Add New Plugin > Upload Plugin
3. Ensure the PHP AI Client plugin is installed and activated
4. Activate the plugin through the WordPress admin

## Configuration

Set your ElevenLabs API key via the `ELEVENLABS_API_KEY` environment variable:

```php
putenv('ELEVENLABS_API_KEY=your-api-key');
```

You can obtain an API key at [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys).

## API Key Permissions

ElevenLabs API keys can be scoped with specific permissions. The minimum permissions required depend on which features you use:

| Permission | Required for | Notes |
|---|---|---|
| Text-to-speech | Text-to-speech generation | Required for TTS functionality |
| Sound generation | Sound effects generation | Required for sound effects |
| Models | Dynamic model discovery | Optional -- the plugin falls back to a hardcoded model list when this permission is missing, and the connection check treats such a key as working rather than rejected |
| Voices | Listing available voices | Needed to browse voices via `VoiceDirectory`, and to pick a voice from your account automatically when `outputSpeechVoice` is not set |

For full functionality, grant **Text-to-speech**, **Sound generation**, **Models**, and **Voices** permissions. For a minimal TTS-only setup, **Text-to-speech** alone is sufficient: without the **Voices** permission the provider cannot discover a voice from your account, and a prompt that omits `outputSpeechVoice` uses the premade "George" voice instead.

You can manage API key permissions at [https://elevenlabs.io/app/settings/api-keys](https://elevenlabs.io/app/settings/api-keys).

### Connection checking

`ElevenLabsProviderAvailability::isConfigured()` verifies the key with ElevenLabs rather than
just checking that one is present, so Settings > Connectors reports whether the key actually
works. It resolves the key from the environment variable, the constant, the AI Client registry,
or the legacy credentials option, then issues one `GET /v1/models`:

| Response | Verdict |
| --- | --- |
| `200` | configured |
| `401` with `detail.status` of `missing_permissions` | configured -- the key is real, it just lacks `models_read` |
| `401` otherwise | not configured |
| `403`, `429`, `5xx`, or a transport failure | undetermined, reported as configured |

Only an outright rejection counts as a failure, so a rate limit or an outage never presents a
working setup as broken. Results are cached in a transient for 15 minutes, keyed by a hash of
the API key so that changing the key invalidates the cache on its own.

The probe builds its own [`ElevenLabsApiKeyAuthentication`](src/Provider/ElevenLabsApiKeyAuthentication.php)
rather than reusing whatever the registry holds. WordPress sets a generic `Authorization: Bearer`
authentication on the registry immediately before asking whether a key is valid, and ElevenLabs
only accepts `xi-api-key`, so reusing it would reject every key it was asked to check.

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply ensure both plugins are active and configure your API key.

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(ProviderForElevenLabs::class);

// Set your API key
putenv('ELEVENLABS_API_KEY=your-api-key');
```

### Text-to-Speech Generation

```php
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

// Simple TTS -- returns a File object with base64-encoded audio.
$audio = AiClient::prompt( 'Hello, this is a test of ElevenLabs text to speech.' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelConfig( ModelConfig::fromArray( [
        'outputSpeechVoice' => 'JBFqnCBsd6RMkjVDRZzb', // Voice ID (optional, defaults to "George")
    ] ) )
    ->convertTextToSpeech();

// Save the audio file.
file_put_contents( 'output.mp3', base64_decode( $audio->toAudioFile()->getBase64Data() ) );
```

### Default Voice

When no `outputSpeechVoice` is configured, the provider still works: it resolves a default voice rather than failing. This means TTS integrations that don't surface a voice setting (such as the WordPress AI plugin's Text to Speech experiment) work out of the box.

An explicitly configured `outputSpeechVoice` always wins. When none is set, the default is resolved in this order:

1. `ELEVENLABS_DEFAULT_VOICE_ID` environment variable
2. `ELEVENLABS_DEFAULT_VOICE_ID` PHP constant
3. `ai_provider_for_elevenlabs_default_voice_id` WordPress option (e.g. `wp option update ai_provider_for_elevenlabs_default_voice_id <voice-id>`)
4. A voice from your ElevenLabs account, preferring a premade one. The voice list is cached per API key, so this costs one extra API call at most. Requires the **Voices** permission on the key.
5. The hardcoded premade voice **"George"** (`JBFqnCBsd6RMkjVDRZzb`). Premade voice IDs are shared across all ElevenLabs accounts, so this final fallback always works.

The resolved default is then passed through the `ai_provider_for_elevenlabs_default_voice_id` filter:

```php
add_filter(
    'ai_provider_for_elevenlabs_default_voice_id',
    function ( string $voice_id ): string {
        return '21m00Tcm4TlvDq8ikWAM'; // "Rachel"
    }
);
```

### Long-form narration

ElevenLabs caps the characters accepted in one request, and the cap depends on the
model:

| Model | Characters per request |
|---|---|
| `eleven_v3` | 5,000 |
| `eleven_multilingual_v2` (default) | 10,000 |
| `eleven_turbo_v2`, `eleven_flash_v2` | 30,000 |
| `eleven_turbo_v2_5`, `eleven_flash_v2_5` | 40,000 |

Longer text is split on paragraph and sentence boundaries, narrated in several
requests that carry their neighbouring text so prosody survives the seams, and
returned as a single audio file. Nothing changes for text that already fits: it
still makes exactly one request.

Two constraints are worth knowing before relying on this.

**It is slow, and can exceed your PHP time limit.** A long text means several
sequential API calls inside one request. Synthesis runs at roughly **90 to 95
characters per second**, so against a PHP default `max_execution_time` of 30
seconds the ceiling for a synchronous call is roughly 2,500 characters, or about
400 words. Raising `max_execution_time` (and memory, since the audio is held in
memory before being returned) works on hosts where you control both.

This package deliberately stays a synchronous provider adapter and ships no
background-job system. The per-chunk seams are public --
`narrateChunk()`, `splitTextForRequests()`, `getVoiceId()`,
`resolveOutputFormat()`, and `resolveMimeTypeFromFormat()` on the
text-to-speech model -- precisely so that a separate plugin can queue narration
chunk by chunk (WP-Cron, Action Scheduler, a system queue) without this package
depending on any of them.

**It costs one request per chunk.** A long text is charged accordingly.

Chunking also requires an output format whose audio can be joined. MP3 and the raw
PCM and µ-law formats can be; Opus is carried in an Ogg container and cannot, and
AAC is excluded until confirmed to be ADTS-framed. Requesting an unjoinable format
for over-long text fails immediately, before any request is billed, rather than
returning audio that is subtly broken. Short text is unaffected in every format.

### Provider-specific options

The provider supports `customOptions`, which pass through to the ElevenLabs API.
This covers parameters the AI Client has no dedicated option for:

```php
$audio = AiClient::prompt( 'Bonjour tout le monde.' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelConfig( ModelConfig::fromArray( [
        'customOptions' => [
            'language_code'            => 'fr',   // force a language
            'speed'                    => 1.1,    // a voice setting
            'seed'                     => 42,     // deterministic output
            'apply_text_normalization' => 'on',
        ],
    ] ) )
    ->convertTextToSpeech();
```

Voice settings (`stability`, `similarity_boost`, `style`, `use_speaker_boost`,
`speed`) are nested under `voice_settings` automatically; everything else is sent
at the top level. An option that collides with a parameter the provider sets --
`text`, `model_id`, `voice_settings` -- is rejected rather than silently
overriding it. `previous_text` and `next_text` are reserved, because the provider
sets them when narrating long text.

### Text-to-Speech with Custom Voice Settings

```php
$audio = AiClient::prompt( 'Welcome to WordPress.' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelPreference( [ 'eleven_multilingual_v2', 'elevenlabs' ] )
    ->usingModelConfig( ModelConfig::fromArray( [
        'outputSpeechVoice' => 'JBFqnCBsd6RMkjVDRZzb',
        'customOptions'     => [
            'stability'         => 0.7,
            'similarity_boost'  => 0.8,
            'style'             => 0.2,
            'use_speaker_boost' => true,
        ],
    ] ) )
    ->convertTextToSpeech();
```

### Sound Effects Generation

```php
$audio = AiClient::prompt( 'A thunderstorm with heavy rain and distant rolling thunder' )
    ->usingProvider( 'elevenlabs' )
    ->usingModelPreference( [ 'elevenlabs-sound-generation', 'elevenlabs' ] )
    ->usingModelConfig( ModelConfig::fromArray( [
        'customOptions' => [
            'duration_seconds' => 5.0,
            'prompt_influence' => 0.3,
        ],
    ] ) )
    ->generateSpeech();

file_put_contents( 'thunder.mp3', base64_decode( $audio->toAudioFile()->getBase64Data() ) );
```

### Listing Available Voices

The plugin provides a `VoiceDirectory` for discovering available voices from the ElevenLabs
`/v2/voices` endpoint. Every page of results is fetched, and the list is cached per API key.

```php
use WordPress\AiClient\AiClient;

// Get the provider instance from the registry.
$provider = AiClient::defaultRegistry()->getProvider( 'elevenlabs' );

// Get the voice directory.
$voiceDirectory = $provider->getVoiceDirectory();

// List all available voices.
$voices = $voiceDirectory->getVoices();
foreach ( $voices as $voice ) {
    echo $voice['id'] . ': ' . $voice['name'] . ' (' . $voice['category'] . ')' . PHP_EOL;
}

// Filter by category (premade, cloned, professional).
$premadeVoices = $voiceDirectory->getVoicesByCategory( 'premade' );

// Get a specific voice by ID.
$voice = $voiceDirectory->getVoice( 'JBFqnCBsd6RMkjVDRZzb' );
if ( $voice ) {
    echo 'Voice: ' . $voice['name'] . PHP_EOL;
}
```

## Available Models

Models are dynamically discovered from the ElevenLabs `/models` API endpoint. Common models include:

| Model ID | Name | Use Case |
|---|---|---|
| `eleven_v3` | v3 | Most expressive TTS |
| `eleven_multilingual_v2` | Multilingual v2 | Best quality multilingual TTS |
| `eleven_turbo_v2_5` | Turbo v2.5 | Low-latency TTS |
| `eleven_turbo_v2` | Turbo v2 | Low-latency TTS (English) |
| `eleven_flash_v2_5` | Flash v2.5 | Fastest TTS |
| `eleven_flash_v2` | Flash v2 | Fast TTS |
| `eleven_monolingual_v1` | English v1 | Legacy English TTS |
| `eleven_multilingual_v1` | Multilingual v1 | Legacy multilingual TTS |
| `elevenlabs-sound-generation` | Sound Generation | Sound effects from text |

The sound generation model is a hardcoded entry (the `/sound-generation` endpoint does not require a model ID).

## Voice Settings Defaults

When no custom voice settings are provided, the following defaults are used:

| Setting | Default | Range |
|---|---|---|
| `stability` | 0.5 | 0.0 -- 1.0 |
| `similarity_boost` | 0.75 | 0.0 -- 1.0 |
| `style` | 0.0 | 0.0 -- 1.0 |
| `use_speaker_boost` | true | boolean |

Override any setting via `customOptions` in `ModelConfig`.

## Supported Output Formats

| Format | MIME Type |
|---|---|
| `mp3_44100_128` (default) | audio/mpeg |
| `mp3_22050_32` | audio/mpeg |
| `pcm_16000`, `pcm_22050`, `pcm_24000`, `pcm_44100` | audio/pcm |
| `ulaw_8000` | audio/basic |
| `opus_48000_32`, `opus_48000_64`, `opus_48000_128` | audio/opus |
| `aac_44100_48`, `aac_44100_64`, `aac_44100_96`, `aac_44100_128`, `aac_44100_192` | audio/aac |

Set the format via `customOptions['output_format']` or `outputMimeType` in `ModelConfig`.

## Building the Plugin ZIP

Build a distributable plugin archive locally:

```bash
make dist
# or:
./scripts/build-plugin-zip.sh
```

The ZIP is created at `dist/ai-provider-for-elevenlabs.zip` and includes `ai-provider-for-elevenlabs.php`.
What it leaves out is defined by [`.distignore`](.distignore); CI plants a canary secret in `.env`
and `.wp-env.override.json` on every run and fails if either reaches the archive.

## WordPress.org directory assets

`.wordpress-org/` holds the artwork and the WordPress Playground blueprint the plugin
directory shows, and maps onto the `assets/` directory of the plugin's Subversion
repository. It is excluded from the plugin ZIP. The banner and icon are generated
rather than hand-drawn, so they can be rebuilt from source:

```bash
./scripts/build-wporg-assets.sh
```

This requires ImageMagick. The artwork follows the [ElevenLabs brand guidelines](https://elevenlabs.io/brand):
their own wordmark and "11" symbol SVGs, scaled uniformly and never redrawn, in the
monochrome palette they specify for ElevenAPI, with the clear space their guidelines
ask for. The WordPress mark sits behind both so the result reads as a WordPress plugin
rather than as official ElevenLabs artwork; this plugin is not affiliated with ElevenLabs.

Note that `assets/images/elevenlabs.svg` is a different thing: that one ships inside
the plugin, because the connector UI reads it to show the provider logo. It is the
same official symbol, on a white holding square so it stays legible on any background.

## Development

Install development dependencies:

```bash
composer install
```

Run unit tests:

```bash
composer test
# or:
composer test:unit
```

Run linting:

```bash
composer lint
```

### Integration tests against the live API

The integration suite makes real calls to ElevenLabs and needs an API key. It does
not need WordPress. Copy the template and fill in your key:

```bash
cp .env.example .env
# then edit .env and set ELEVENLABS_API_KEY
composer test:integration
```

Individual tests skip themselves when the key is absent, so the suite is safe to
run without one. `.env` is both gitignored and excluded from the release ZIP.

Generated audio is written to `tests/Integration/audio/` for listening.

### Local WordPress environment

Some behaviour only exists inside WordPress -- provider registration on `init`,
the Settings > Connectors credential flow, and transient-backed voice caching --
and no amount of PHPUnit will exercise it. Use [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
(requires Docker):

```bash
npx @wordpress/env start
```

This boots current WordPress with the plugin mounted and activated, at
<http://localhost:8890> (admin/password). To provide an API key locally, copy
`.wp-env.override.json.example` to `.wp-env.override.json` and fill it in; the
override file is gitignored and excluded from the release ZIP.

Two AI dependencies are involved, and they arrive differently:

- **The PHP AI Client SDK** is what this provider plugs into. It is a Composer
  package with no `Plugin Name:` header, so it cannot be installed as a plugin;
  it ships inside core at `wp-includes/php-ai-client/`. That is why `"core"` is
  the dependency here rather than anything in `"plugins"`.
- **The [AI plugin](https://wordpress.org/plugins/ai)** is the WordPress.org
  reference implementation built on top of that SDK -- Connectors approvals, an
  abilities explorer, AI request logging, and editor features. It *is* a real
  plugin, and `.wp-env.json` installs it, because it is the thing that actually
  exercises a registered provider end to end.

With only ElevenLabs configured, the AI plugin warns that it needs a valid AI
Connector. That is expected, not a fault in this provider: the AI plugin treats
a connector as valid only when it can generate text, and ElevenLabs generates
speech. Add a text-generation connector alongside it to exercise the AI plugin's
own features.

## Credits

Created and maintained by [Lauri Saarni](https://profiles.wordpress.org/laurisaarni/).

Several 0.4.0 improvements were contributed by [Jake Spurlock](https://profiles.wordpress.org/whyisjake/)
in his fork, [whyisjake/ai-provider-for-elevenlabs](https://github.com/whyisjake/ai-provider-for-elevenlabs):
long-form narration and text chunking, the `/v2/voices` migration with pagination and
per-key caching, automatic voice selection from the account, custom option
passthrough, the connector metadata and logo treatment, the CI and packaging
leak checks, and the local `wp-env` environment.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).