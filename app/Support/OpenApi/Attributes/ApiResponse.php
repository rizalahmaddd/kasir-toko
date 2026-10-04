<?php

namespace App\Support\OpenApi\Attributes;

use Attribute;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only needed when the return type does not already name the resource, e.g. collections
 * (AnonymousResourceCollection) or endpoints returning 201 with a different resource.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiResponse
{
    /**
     * @param  class-string<JsonResource>|null  $resource
     */
    public function __construct(
        public ?string $resource = null,
        public bool $collection = false,
        public bool $paginated = false,
        public int $status = 200,
        public ?string $description = null,
        public ?string $mediaType = null,
    ) {}
}
