<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Voices;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Unit tests for VoiceDirectory.
 *
 * @covers \AiProviderForElevenLabs\Voices\VoiceDirectory
 */
class VoiceDirectoryTest extends TestCase
{
    private const BASE_URL = 'https://api.elevenlabs.io/v1';

    /**
     * Creates a MockVoiceDirectory with mocked transporter and authentication.
     *
     * @param Response $response The response the transporter should return.
     * @return MockVoiceDirectory
     */
    private function createDirectoryWithResponse(Response $response): MockVoiceDirectory
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter
            ->method('send')
            ->willReturn($response);

        $authentication = $this->createMock(RequestAuthenticationInterface::class);
        $authentication
            ->method('authenticateRequest')
            ->willReturnCallback(static function (Request $request): Request {
                return $request->withHeader('xi-api-key', 'test-key');
            });

        return new MockVoiceDirectory(self::BASE_URL, $transporter, $authentication);
    }

    /**
     * Creates a MockVoiceDirectory that returns the given responses in order.
     *
     * @param list<Response> $responses    Responses to return from consecutive send() calls.
     * @param list<string>   $capturedUris Populated with the URI of each request sent.
     * @return MockVoiceDirectory
     */
    private function createDirectoryWithResponses(array $responses, array &$capturedUris = []): MockVoiceDirectory
    {
        $capturedUris = [];
        $remaining = $responses;

        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter
            ->method('send')
            ->willReturnCallback(
                static function (Request $request) use (&$remaining, &$capturedUris, $responses): Response {
                    $capturedUris[] = $request->getUri();
                    $next = array_shift($remaining);

                    // Repeat the final response if more calls arrive than expected,
                    // so a runaway pagination loop surfaces as a failed assertion
                    // rather than an array-shift error.
                    return $next ?? $responses[count($responses) - 1];
                }
            );

        $authentication = $this->createMock(RequestAuthenticationInterface::class);
        $authentication
            ->method('authenticateRequest')
            ->willReturnCallback(static function (Request $request): Request {
                return $request->withHeader('xi-api-key', 'test-key');
            });

        return new MockVoiceDirectory(self::BASE_URL, $transporter, $authentication);
    }

    /**
     * Builds a JSON response body containing the given voice entries.
     *
     * @param list<array<string, mixed>> $voices Raw voice entries.
     * @return Response
     */
    private function buildVoicesResponse(array $voices): Response
    {
        $body = json_encode(['voices' => $voices], JSON_THROW_ON_ERROR);

        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * Builds a paginated JSON response body.
     *
     * @param list<array<string, mixed>> $voices        Raw voice entries.
     * @param bool                       $hasMore       Whether further pages follow.
     * @param string|null                $nextPageToken Cursor for the next page.
     * @return Response
     */
    private function buildPagedVoicesResponse(
        array $voices,
        bool $hasMore,
        ?string $nextPageToken = null
    ): Response {
        $payload = ['voices' => $voices, 'has_more' => $hasMore];
        if ($nextPageToken !== null) {
            $payload['next_page_token'] = $nextPageToken;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Returns a sample voice entry as the ElevenLabs API would return it.
     *
     * @return array<string, mixed>
     */
    private function sampleApiVoice(
        string $voiceId = 'abc123',
        string $name = 'Rachel',
        string $category = 'premade',
        array $labels = ['accent' => 'american', 'age' => 'young'],
        string $description = 'A warm and friendly voice.',
        string $previewUrl = 'https://example.com/preview.mp3'
    ): array {
        return [
            'voice_id'    => $voiceId,
            'name'        => $name,
            'category'    => $category,
            'labels'      => $labels,
            'description' => $description,
            'preview_url' => $previewUrl,
        ];
    }

    // ------------------------------------------------------------------
    // getVoices()
    // ------------------------------------------------------------------

    public function testGetVoicesReturnsMappedVoices(): void
    {
        $voice1 = $this->sampleApiVoice('id1', 'Rachel', 'premade');
        $voice2 = $this->sampleApiVoice('id2', 'Clyde', 'premade');
        $voice3 = $this->sampleApiVoice('id3', 'MyClone', 'cloned');

        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([$voice1, $voice2, $voice3])
        );

        $voices = $directory->getVoices();

        $this->assertCount(3, $voices);
        $this->assertArrayHasKey('id1', $voices);
        $this->assertArrayHasKey('id2', $voices);
        $this->assertArrayHasKey('id3', $voices);

        // Verify field mapping.
        $this->assertSame('id1', $voices['id1']['id']);
        $this->assertSame('Rachel', $voices['id1']['name']);
        $this->assertSame('premade', $voices['id1']['category']);
        $this->assertSame(['accent' => 'american', 'age' => 'young'], $voices['id1']['labels']);
        $this->assertSame('A warm and friendly voice.', $voices['id1']['description']);
        $this->assertSame('https://example.com/preview.mp3', $voices['id1']['preview_url']);
    }

    public function testGetVoicesReturnsEmptyArrayForEmptyVoiceList(): void
    {
        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([])
        );

        $voices = $directory->getVoices();

        $this->assertSame([], $voices);
    }

    public function testGetVoicesReturnsEmptyArrayWhenResponseHasNoVoicesKey(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => []]));

        $directory = $this->createDirectoryWithResponse($response);

        $voices = $directory->getVoices();

        $this->assertSame([], $voices);
    }

    public function testGetVoicesSkipsEntriesWithoutVoiceId(): void
    {
        $valid = $this->sampleApiVoice('id1', 'Rachel');
        $invalid = ['name' => 'Missing ID'];

        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([$valid, $invalid])
        );

        $voices = $directory->getVoices();

        $this->assertCount(1, $voices);
        $this->assertArrayHasKey('id1', $voices);
    }

    // ------------------------------------------------------------------
    // getVoice()
    // ------------------------------------------------------------------

    public function testGetVoiceReturnsSingleVoice(): void
    {
        $voice = $this->sampleApiVoice('target-id', 'Bella', 'premade');

        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([$voice])
        );

        $result = $directory->getVoice('target-id');

        $this->assertNotNull($result);
        $this->assertSame('target-id', $result['id']);
        $this->assertSame('Bella', $result['name']);
    }

    public function testGetVoiceReturnsNullForNonExistentId(): void
    {
        $voice = $this->sampleApiVoice('id1', 'Rachel');

        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([$voice])
        );

        $result = $directory->getVoice('non-existent-id');

        $this->assertNull($result);
    }

    public function testGetVoiceReturnsNullWhenNoVoicesExist(): void
    {
        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([])
        );

        $result = $directory->getVoice('any-id');

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // getVoicesByCategory()
    // ------------------------------------------------------------------

    public function testGetVoicesByCategoryFiltersPremade(): void
    {
        $premade1 = $this->sampleApiVoice('id1', 'Rachel', 'premade');
        $premade2 = $this->sampleApiVoice('id2', 'Clyde', 'premade');
        $cloned   = $this->sampleApiVoice('id3', 'MyClone', 'cloned');

        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([$premade1, $premade2, $cloned])
        );

        $premadeVoices = $directory->getVoicesByCategory('premade');

        $this->assertCount(2, $premadeVoices);
        $this->assertArrayHasKey('id1', $premadeVoices);
        $this->assertArrayHasKey('id2', $premadeVoices);
        $this->assertArrayNotHasKey('id3', $premadeVoices);
    }

    public function testGetVoicesByCategoryReturnsEmptyForNoMatch(): void
    {
        $premade = $this->sampleApiVoice('id1', 'Rachel', 'premade');

        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([$premade])
        );

        $clonedVoices = $directory->getVoicesByCategory('cloned');

        $this->assertSame([], $clonedVoices);
    }

    public function testGetVoicesByCategoryReturnsEmptyForEmptyList(): void
    {
        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([])
        );

        $voices = $directory->getVoicesByCategory('premade');

        $this->assertSame([], $voices);
    }

    // ------------------------------------------------------------------
    // Pagination and endpoint
    // ------------------------------------------------------------------

    public function testGetVoicesTargetsTheV2EndpointWithMaxPageSize(): void
    {
        $capturedUris = [];
        $directory = $this->createDirectoryWithResponses(
            [$this->buildPagedVoicesResponse([$this->sampleApiVoice('id1')], false)],
            $capturedUris
        );

        $directory->getVoices();

        $this->assertCount(1, $capturedUris);
        $this->assertStringStartsWith('https://api.elevenlabs.io/v2/voices?', $capturedUris[0]);
        $this->assertStringContainsString('page_size=100', $capturedUris[0]);
    }

    public function testGetVoicesWalksEveryPage(): void
    {
        $capturedUris = [];
        $directory = $this->createDirectoryWithResponses(
            [
                $this->buildPagedVoicesResponse([$this->sampleApiVoice('id1')], true, 'token-2'),
                $this->buildPagedVoicesResponse([$this->sampleApiVoice('id2')], true, 'token-3'),
                $this->buildPagedVoicesResponse([$this->sampleApiVoice('id3')], false),
            ],
            $capturedUris
        );

        $voices = $directory->getVoices();

        $this->assertCount(3, $voices);
        $this->assertSame(['id1', 'id2', 'id3'], array_keys($voices));

        $this->assertCount(3, $capturedUris);
        $this->assertStringNotContainsString('next_page_token', $capturedUris[0]);
        $this->assertStringContainsString('next_page_token=token-2', $capturedUris[1]);
        $this->assertStringContainsString('next_page_token=token-3', $capturedUris[2]);
    }

    public function testGetVoicesStopsWhenCursorDoesNotAdvance(): void
    {
        $capturedUris = [];

        // Every page claims has_more with the same cursor. Without the guard this
        // would spin until the MAX_PAGES ceiling.
        $directory = $this->createDirectoryWithResponses(
            [$this->buildPagedVoicesResponse([$this->sampleApiVoice('id1')], true, 'stuck-token')],
            $capturedUris
        );

        $voices = $directory->getVoices();

        $this->assertCount(1, $voices);
        $this->assertCount(2, $capturedUris);
    }

    public function testGetVoicesStopsWhenHasMoreLacksACursor(): void
    {
        $capturedUris = [];
        $directory = $this->createDirectoryWithResponses(
            [$this->buildPagedVoicesResponse([$this->sampleApiVoice('id1')], true)],
            $capturedUris
        );

        $voices = $directory->getVoices();

        $this->assertCount(1, $voices);
        $this->assertCount(1, $capturedUris);
    }

    public function testGetVoicesIsMemoizedAcrossCalls(): void
    {
        $capturedUris = [];
        $directory = $this->createDirectoryWithResponses(
            [$this->buildPagedVoicesResponse([$this->sampleApiVoice('id1')], false)],
            $capturedUris
        );

        $directory->getVoices();
        $directory->getVoices();
        $directory->getVoice('id1');
        $directory->getVoicesByCategory('premade');

        $this->assertCount(1, $capturedUris);
    }

    public function testGetVoicesForceRefreshBypassesMemoization(): void
    {
        $capturedUris = [];
        $directory = $this->createDirectoryWithResponses(
            [
                $this->buildPagedVoicesResponse([$this->sampleApiVoice('id1')], false),
                $this->buildPagedVoicesResponse([$this->sampleApiVoice('id2')], false),
            ],
            $capturedUris
        );

        $directory->getVoices();
        $refreshed = $directory->getVoices(true);

        $this->assertCount(2, $capturedUris);
        $this->assertArrayHasKey('id2', $refreshed);
    }

    // ------------------------------------------------------------------
    // getDefaultVoiceId()
    // ------------------------------------------------------------------

    public function testGetDefaultVoiceIdPrefersAPremadeVoice(): void
    {
        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([
                $this->sampleApiVoice('cloned-id', 'MyClone', 'cloned'),
                $this->sampleApiVoice('premade-id', 'Rachel', 'premade'),
            ])
        );

        $this->assertSame('premade-id', $directory->getDefaultVoiceId());
    }

    public function testGetDefaultVoiceIdFallsBackToFirstVoice(): void
    {
        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([
                $this->sampleApiVoice('cloned-1', 'MyClone', 'cloned'),
                $this->sampleApiVoice('cloned-2', 'OtherClone', 'cloned'),
            ])
        );

        $this->assertSame('cloned-1', $directory->getDefaultVoiceId());
    }

    public function testGetDefaultVoiceIdReturnsNullWhenAccountHasNoVoices(): void
    {
        $directory = $this->createDirectoryWithResponse(
            $this->buildVoicesResponse([])
        );

        $this->assertNull($directory->getDefaultVoiceId());
    }

    // ------------------------------------------------------------------
    // mapVoiceData()
    // ------------------------------------------------------------------

    public function testMapVoiceDataHandlesMissingOptionalFields(): void
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $authentication = $this->createMock(RequestAuthenticationInterface::class);

        $directory = new MockVoiceDirectory(self::BASE_URL, $transporter, $authentication);

        $mapped = $directory->exposeMapVoiceData([
            'voice_id' => 'minimal-id',
        ]);

        $this->assertSame('minimal-id', $mapped['id']);
        $this->assertSame('', $mapped['name']);
        $this->assertSame('', $mapped['category']);
        $this->assertSame([], $mapped['labels']);
        $this->assertSame('', $mapped['description']);
        $this->assertSame('', $mapped['preview_url']);
    }

    public function testMapVoiceDataHandlesNonArrayLabels(): void
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $authentication = $this->createMock(RequestAuthenticationInterface::class);

        $directory = new MockVoiceDirectory(self::BASE_URL, $transporter, $authentication);

        $mapped = $directory->exposeMapVoiceData([
            'voice_id' => 'id1',
            'name'     => 'Rachel',
            'labels'   => 'not-an-array',
        ]);

        $this->assertSame([], $mapped['labels']);
    }
}
