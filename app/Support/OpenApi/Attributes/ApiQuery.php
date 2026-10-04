<?php

namespace App\Support\OpenApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiQuery
{
    /**
     * @param  'string'|'integer'|'number'|'boolean'|'date'  $type
     * @param  list<string>|null  $enum
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public string $description = '',
        public bool $required = false,
        public ?array $enum = null,
    ) {}
}
