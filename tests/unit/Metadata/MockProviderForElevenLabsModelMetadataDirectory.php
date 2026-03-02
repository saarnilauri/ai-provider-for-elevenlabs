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
}
