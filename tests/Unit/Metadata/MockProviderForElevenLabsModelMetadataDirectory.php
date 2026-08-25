<?php

declare(strict_types=1);

namespace AiProviderForElevenLabs\Tests\Unit\Metadata;

use AiProviderForElevenLabs\Metadata\ProviderForElevenLabsModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForElevenLabsModelMetadataDirectory.
 */
class MockProviderForElevenLabsModelMetadataDirectory extends ProviderForElevenLabsModelMetadataDirectory
{
    /**
     * Exposes parseResponseToModelMetadataList for testing.
     *
     * @param Response $response
     * @return list<ModelMetadata>
     */
    public function exposeParseResponseToModelMetadataList(Response $response): array
    {
        return $this->parseResponseToModelMetadataList($response);
    }

    /**
     * Exposes sendListModelsRequest for testing.
     *
     * When no HTTP transporter is set, the parent request fails and the
     * hardcoded fallback models map is returned, so this can be used to test
     * the fallback path without any HTTP mocking.
     *
     * @return array<string, ModelMetadata>
     */
    public function exposeSendListModelsRequest(): array
    {
        return $this->sendListModelsRequest();
    }
}
