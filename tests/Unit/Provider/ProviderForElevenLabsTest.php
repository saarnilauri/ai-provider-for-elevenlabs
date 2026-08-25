<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Provider;

use AiProviderForElevenLabs\Provider\ProviderForElevenLabs;
use AiProviderForElevenLabs\Voices\VoiceDirectory;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;

/**
 * @covers \AiProviderForElevenLabs\Provider\ProviderForElevenLabs
 */
class ProviderForElevenLabsTest extends TestCase
{
    /**
     * Tests that the provider reports the service name and a description.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProviderMetadataNamesTheService(): void
    {
        $metadata = ProviderForElevenLabs::metadata();

        $this->assertSame('elevenlabs', $metadata->getId());
        $this->assertSame('ElevenLabs', $metadata->getName());
        $this->assertNotSame('', (string) $metadata->getDescription());
    }

    /**
     * Tests that credentials arriving after the voice directory is first built
     * are still applied to it.
     *
     * The directory is frequently requested before the registry has wired up
     * credentials. An instance built without them cannot recover on its own, so
     * every later voice lookup would throw for the rest of the request.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testVoiceDirectoryPicksUpCredentialsThatArriveLater(): void
    {
        $directoryBefore = ProviderForElevenLabs::getVoiceDirectory();
        $this->assertInstanceOf(VoiceDirectory::class, $directoryBefore);

        // Nothing is wired up yet, so the directory cannot authenticate.
        try {
            $directoryBefore->getRequestAuthentication();
            $this->fail('Expected the voice directory to have no authentication yet.');
        } catch (RuntimeException $e) {
            $this->addToAssertionCount(1);
        }

        $authentication = $this->createMock(RequestAuthenticationInterface::class);
        $transporter = $this->createMock(HttpTransporterInterface::class);

        $metadataDirectory = ProviderForElevenLabs::modelMetadataDirectory();
        $this->assertInstanceOf(WithRequestAuthenticationInterface::class, $metadataDirectory);
        $this->assertInstanceOf(WithHttpTransporterInterface::class, $metadataDirectory);

        $metadataDirectory->setRequestAuthentication($authentication);
        $metadataDirectory->setHttpTransporter($transporter);

        $directoryAfter = ProviderForElevenLabs::getVoiceDirectory();

        $this->assertSame($directoryBefore, $directoryAfter, 'The directory should be reused, not rebuilt.');
        $this->assertSame($authentication, $directoryAfter->getRequestAuthentication());
        $this->assertSame($transporter, $directoryAfter->getHttpTransporter());
    }
}
