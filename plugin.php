<?php

/**
 * Plugin Name: AI Provider for ElevenLabs
 * Plugin URI: https://github.com/saarnilauri/ai-provider-for-elevenlabs
 * Description: ElevenLabs provider for the WordPress AI API.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 0.1.0
 * Author: Lauri Saarni
 * Author URI: https://profiles.wordpress.org/laurisaarni/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-elevenlabs
 *
 * @package AiProviderForElevenLabs
 */

declare(strict_types=1);

namespace AiProviderForElevenLabs;

use AiProviderForElevenLabs\Provider\ElevenLabsApiKeyAuthentication;
use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    return;
}

/**
 * Loads all plugin class files.
 *
 * Since this plugin may be installed without Composer, classes
 * are loaded manually instead of relying on an autoloader.
 *
 * Load order: Metadata → Voices → Models → Provider
 *
 * @since 0.1.0
 *
 * @return void
 */
function load_classes(): void
{
    $plugin_dir = __DIR__ . '/src';

    require_once $plugin_dir . '/Metadata/ProviderForElevenLabsModelMetadataDirectory.php';
    require_once $plugin_dir . '/Voices/VoiceDirectory.php';
    require_once $plugin_dir . '/Models/ProviderForElevenLabsTextToSpeechModel.php';
    require_once $plugin_dir . '/Models/ProviderForElevenLabsSoundGenerationModel.php';
    require_once $plugin_dir . '/Provider/ElevenLabsApiKeyAuthentication.php';
    require_once $plugin_dir . '/Provider/ElevenLabsProviderAvailability.php';
    require_once $plugin_dir . '/Provider/ProviderForElevenLabs.php';
}

/**
 * Registers the WordPress AI Client provider for ElevenLabs.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    load_classes();

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(ProviderForElevenLabs::class)) {
        return;
    }

    $registry->registerProvider(ProviderForElevenLabs::class);

    // Override the default ApiKeyRequestAuthentication with ElevenLabs-specific
    // authentication that uses the xi-api-key header instead of Authorization: Bearer.
    $apiKey = getenv('ELEVENLABS_API_KEY');
    if ($apiKey === false && defined('ELEVENLABS_API_KEY')) {
        $apiKey = (string) constant('ELEVENLABS_API_KEY');
    }
    if ($apiKey !== false && $apiKey !== '') {
        $registry->setProviderRequestAuthentication(
            ProviderForElevenLabs::class,
            new ElevenLabsApiKeyAuthentication($apiKey)
        );
    }
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
