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
     * Allowed model IDs standing in for the WordPress filter, or null to use the filter.
     *
     * @var list<string>|null
     */
    public ?array $allowedModelIds = null;

    /**
     * Alpha model setting standing in for the WordPress filter, or null to use the filter.
     *
     * @var bool|null
     */
    public ?bool $alphaModelsIncluded = null;

    /**
     * {@inheritDoc}
     */
    protected function resolveAllowedModelIds(): array
    {
        return $this->allowedModelIds ?? parent::resolveAllowedModelIds();
    }

    /**
     * {@inheritDoc}
     */
    protected function includeAlphaModels(): bool
    {
        return $this->alphaModelsIncluded ?? parent::includeAlphaModels();
    }

    /**
     * Exposes resolveDefaultModelId for testing.
     *
     * @return string
     */
    public function exposeResolveDefaultModelId(): string
    {
        return $this->resolveDefaultModelId();
    }

    /**
     * Exposes resolveAllowedModelIds for testing.
     *
     * @return list<string>
     */
    public function exposeResolveAllowedModelIds(): array
    {
        return $this->resolveAllowedModelIds();
    }

    /**
     * Exposes includeAlphaModels for testing.
     *
     * @return bool
     */
    public function exposeIncludeAlphaModels(): bool
    {
        return $this->includeAlphaModels();
    }

    /**
     * Exposes getBaseCacheKey for testing.
     *
     * @return string
     */
    public function exposeGetBaseCacheKey(): string
    {
        return $this->getBaseCacheKey();
    }

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
