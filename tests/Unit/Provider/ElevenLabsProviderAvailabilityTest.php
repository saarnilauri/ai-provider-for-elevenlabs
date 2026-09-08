<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Provider;

use AiProviderForElevenLabs\Provider\ElevenLabsApiKeyAuthentication;
use AiProviderForElevenLabs\Provider\ElevenLabsProviderAvailability;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Unit tests for ElevenLabsProviderAvailability.
 *
 * These run without WordPress loaded, so the transient cache is inert and every
 * call performs its own verification. That is the standalone-package path, and
 * keeping the suite on it also keeps the tests independent of each other.
 *
 * @covers \AiProviderForElevenLabs\Provider\ElevenLabsProviderAvailability
 */
class ElevenLabsProviderAvailabilityTest extends TestCase
{
    private const API_KEY = 'sk_test_key';

    protected function setUp(): void
    {
        parent::setUp();

        // The class reads the environment first, and the integration suite puts
        // a real key there. Clear it so these tests exercise the registry path.
        putenv('ELEVENLABS_API_KEY');
    }

    /**
     * Builds an availability instance wired to a canned response.
     *
     * @param Response|null $response  The response the transporter returns.
     * @param string|null   $apiKey    The key held by the registry authentication.
     * @param \Throwable|null $throw   An error the transporter throws instead.
     * @return ElevenLabsProviderAvailability
     */
    private function createAvailability(
        ?Response $response,
        ?string $apiKey = self::API_KEY,
        ?\Throwable $throw = null
    ): ElevenLabsProviderAvailability {
        $availability = new ElevenLabsProviderAvailability();

        $transporter = $this->createMock(HttpTransporterInterface::class);
        if ($throw !== null) {
            $transporter->method('send')->willThrowException($throw);
        } elseif ($response !== null) {
            $transporter->method('send')->willReturn($response);
        }
        $availability->setHttpTransporter($transporter);

        if ($apiKey !== null) {
            $availability->setRequestAuthentication(new ApiKeyRequestAuthentication($apiKey));
        }

        return $availability;
    }

    /**
     * Builds an ElevenLabs-shaped error response.
     */
    private function errorResponse(int $statusCode, string $detailStatus): Response
    {
        return new Response(
            $statusCode,
            ['Content-Type' => ['application/json']],
            (string) json_encode([
                'detail' => [
                    'type' => 'authentication_error',
                    'code' => 'unauthorized',
                    'message' => 'Whatever ElevenLabs says here.',
                    'status' => $detailStatus,
                ],
            ])
        );
    }

    public function testReportsConfiguredWhenTheKeyIsAccepted(): void
    {
        $response = new Response(200, [], (string) json_encode(['models' => []]));

        $this->assertTrue($this->createAvailability($response)->isConfigured());
    }

    public function testReportsNotConfiguredWhenTheKeyIsRejected(): void
    {
        $response = $this->errorResponse(401, 'invalid_api_key');

        $this->assertFalse($this->createAvailability($response)->isConfigured());
    }

    /**
     * A key scoped without models_read still drives text-to-speech, and the
     * model metadata directory falls back to its built-in list for that case.
     * Reporting it as misconfigured would call a working setup broken.
     */
    public function testReportsConfiguredWhenTheKeyLacksTheModelsPermission(): void
    {
        $response = $this->errorResponse(401, 'missing_permissions');

        $this->assertTrue($this->createAvailability($response)->isConfigured());
    }

    public function testTreatsAnUnrecognisedUnauthorizedAsRejected(): void
    {
        $response = $this->errorResponse(401, 'something_new_from_elevenlabs');

        $this->assertFalse($this->createAvailability($response)->isConfigured());
    }

    public function testTreatsAnUnauthorizedWithNoBodyAsRejected(): void
    {
        $response = new Response(401, [], null);

        $this->assertFalse($this->createAvailability($response)->isConfigured());
    }

    /**
     * @dataProvider provideInconclusiveStatusCodes
     */
    public function testTreatsInconclusiveResponsesAsUsable(int $statusCode): void
    {
        $response = new Response($statusCode, [], (string) json_encode(['detail' => 'nope']));

        $this->assertTrue(
            $this->createAvailability($response)->isConfigured(),
            sprintf('HTTP %d says nothing reliable about the key and must not fail the check.', $statusCode)
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public function provideInconclusiveStatusCodes(): array
    {
        return [
            'ip allowlist rejection' => [403],
            'rate limited' => [429],
            'server error' => [500],
            'gateway timeout' => [504],
        ];
    }

    public function testTreatsATransportFailureAsUsable(): void
    {
        $availability = $this->createAvailability(null, self::API_KEY, new \RuntimeException('DNS is having a day'));

        $this->assertTrue($availability->isConfigured());
    }

    public function testReportsNotConfiguredWhenNoKeyIsAvailable(): void
    {
        $this->assertFalse($this->createAvailability(null, null)->isConfigured());
    }

    public function testDoesNotCallTheApiWhenNoKeyIsAvailable(): void
    {
        $availability = new ElevenLabsProviderAvailability();

        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->never())->method('send');
        $availability->setHttpTransporter($transporter);

        $this->assertFalse($availability->isConfigured());
    }

    public function testReportsNotConfiguredWhenTheRegistryKeyIsEmpty(): void
    {
        $availability = $this->createAvailability(null, '');

        $this->assertFalse($availability->isConfigured());
    }

    public function testUsesTheEnvironmentKeyBeforeTheRegistry(): void
    {
        putenv('ELEVENLABS_API_KEY=sk_from_env');

        try {
            $captured = null;
            $availability = new ElevenLabsProviderAvailability();

            $transporter = $this->createMock(HttpTransporterInterface::class);
            $transporter
                ->method('send')
                ->willReturnCallback(function (Request $request) use (&$captured): Response {
                    $captured = $request->getHeader('xi-api-key');
                    return new Response(200, [], (string) json_encode(['models' => []]));
                });
            $availability->setHttpTransporter($transporter);
            $availability->setRequestAuthentication(new ApiKeyRequestAuthentication('sk_from_registry'));

            $this->assertTrue($availability->isConfigured());
            $this->assertSame(['sk_from_env'], $captured);
        } finally {
            putenv('ELEVENLABS_API_KEY');
        }
    }

    /**
     * Core sets a generic Bearer authentication on the registry immediately
     * before asking whether a key is valid. ElevenLabs only accepts the
     * xi-api-key header, so the probe has to build its own authentication or it
     * would reject every key it was asked to check.
     */
    public function testProbesWithTheXiApiKeyHeaderNotTheRegistryAuthentication(): void
    {
        $captured = null;
        $availability = new ElevenLabsProviderAvailability();

        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter
            ->method('send')
            ->willReturnCallback(function (Request $request) use (&$captured): Response {
                $captured = $request->getHeaders();
                return new Response(200, [], (string) json_encode(['models' => []]));
            });
        $availability->setHttpTransporter($transporter);

        // Deliberately the generic class, exactly as core leaves it.
        $registryAuth = new ApiKeyRequestAuthentication(self::API_KEY);
        $this->assertNotInstanceOf(ElevenLabsApiKeyAuthentication::class, $registryAuth);
        $availability->setRequestAuthentication($registryAuth);

        $this->assertTrue($availability->isConfigured());

        $encoded = (string) json_encode($captured);
        $this->assertStringContainsString('xi-api-key', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('authorization', $encoded);
    }

    public function testTreatsAMissingTransporterAsUndeterminedRatherThanRejected(): void
    {
        $availability = new ElevenLabsProviderAvailability();
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication(self::API_KEY));

        // No transporter attached, so the probe cannot run. That is
        // undetermined rather than a bad key, so the key stays usable.
        $this->assertTrue($availability->isConfigured());
    }
}
