<?php

namespace App\Support\OpenApi\Attributes;

use Attribute;

/**
 * Groups a controller's endpoints in the API reference. `group` becomes the sidebar section
 * (x-tagGroups), `name` the tag inside it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiTag
{
    public function __construct(
        public string $name,
        public string $group,
        public ?string $description = null,
    ) {}
}
