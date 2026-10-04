<?php

namespace App\Support\OpenApi;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;
use Stringable;

/**
 * Turns Laravel validation rules into a JSON Schema request body, including nested
 * `items.*.field` rules.
 */
class RequestSchema
{
    public bool $hasFiles = false;

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function fromRules(array $rules): array
    {
        $this->hasFiles = false;
        $root = ['type' => 'object', 'properties' => [], 'required' => []];

        uksort($rules, fn (string $a, string $b) => substr_count($a, '.') <=> substr_count($b, '.'));

        foreach ($rules as $key => $fieldRules) {
            $this->place($root, explode('.', $key), $this->fieldSchema($this->normalize($fieldRules)));
        }

        return $this->clean($root);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @param  array{schema: array<string, mixed>, required: bool}  $field
     */
    private function place(array &$node, array $segments, array $field): void
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            $node['type'] = 'array';
            $node['items'] ??= ['type' => 'object', 'properties' => [], 'required' => []];

            if ($segments === []) {
                $node['items'] = array_merge($node['items'], $field['schema']);

                return;
            }

            $this->place($node['items'], $segments, $field);

            return;
        }

        if (($node['type'] ?? null) === 'array' && ! isset($node['items'])) {
            $node['type'] = 'object';
        }

        $node['properties'] ??= [];
        $node['required'] ??= [];

        if ($segments === []) {
            $existing = $node['properties'][$segment] ?? [];
            $node['properties'][$segment] = array_merge($field['schema'], array_intersect_key($existing, array_flip(['items', 'properties', 'required'])));

            if ($field['required']) {
                $node['required'][] = $segment;
            }

            return;
        }

        $node['properties'][$segment] ??= ['type' => 'object', 'properties' => [], 'required' => []];
        $this->place($node['properties'][$segment], $segments, $field);
    }

    /**
     * @param  list<string|object>  $rules
     * @return array{schema: array<string, mixed>, required: bool}
     */
    private function fieldSchema(array $rules): array
    {
        $schema = [];
        $notes = [];
        $required = false;
        $nullable = false;
        $min = $max = null;

        foreach ($rules as $rule) {
            if ($rule instanceof In) {
                $rule = (string) $rule;
            }

            if ($rule instanceof File) {
                $schema['type'] = 'string';
                $schema['format'] = 'binary';
                $this->hasFiles = true;

                continue;
            }

            if ($rule instanceof Enum) {
                $notes[] = 'nilai enum yang valid';

                continue;
            }

            if (! is_string($rule)) {
                continue;
            }

            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
            $arguments = $parameter === null ? [] : str_getcsv($parameter, ',', '"', '\\');

            match ($name) {
                'required' => $required = true,
                'nullable' => $nullable = true,
                'integer' => $schema['type'] = 'integer',
                'numeric', 'decimal' => $schema['type'] ??= 'number',
                'boolean', 'accepted' => $schema['type'] = 'boolean',
                'array' => $schema['type'] = 'array',
                'string' => $schema['type'] ??= 'string',
                'date' => [$schema['type'], $schema['format']] = ['string', 'date'],
                'date_format' => [$schema['type'], $notes[]] = ['string', 'format '.$arguments[0]],
                'email' => [$schema['type'], $schema['format']] = ['string', 'email'],
                'url' => [$schema['type'], $schema['format']] = ['string', 'uri'],
                'file', 'image' => [$schema['type'], $schema['format'], $this->hasFiles] = ['string', 'binary', true],
                'mimes', 'extensions' => $notes[] = 'berkas: '.implode(', ', $arguments),
                'in' => $schema['enum'] = $arguments,
                'min' => $min = $arguments[0],
                'max' => $max = $arguments[0],
                'size' => $min = $max = $arguments[0],
                'digits' => [$schema['type'], $schema['pattern']] = ['string', '^\d{'.$arguments[0].'}$'],
                'exists' => $notes[] = 'ID dari tabel '.$arguments[0],
                'after_or_equal', 'after', 'before', 'before_or_equal' => $notes[] = str_replace('_', ' ', $name).' '.$arguments[0],
                'gt', 'gte', 'lt', 'lte' => $notes[] = "{$name} {$arguments[0]}",
                'required_if', 'required_with', 'required_unless', 'required_without', 'prohibited_if' => $notes[] = str_replace('_', ' ', $name).' '.implode(', ', $arguments),
                'confirmed' => $notes[] = 'wajib disertai field *_confirmation',
                'distinct' => $notes[] = 'tidak boleh duplikat',
                default => null,
            };
        }

        $type = $schema['type'] ?? 'string';

        if ($min !== null) {
            $schema[match ($type) {
                'integer', 'number' => 'minimum', 'array' => 'minItems', default => 'minLength'
            }] = +$min;
        }

        if ($max !== null) {
            if (($schema['format'] ?? null) === 'binary') {
                $notes[] = 'maks '.round($max / 1024, 1).' MB';
            } else {
                $schema[match ($type) {
                    'integer', 'number' => 'maximum', 'array' => 'maxItems', default => 'maxLength'
                }] = +$max;
            }
        }

        $schema['type'] = $nullable ? [$type, 'null'] : $type;

        if ($notes !== []) {
            $schema['description'] = ucfirst(implode('; ', $notes)).'.';
        }

        return ['schema' => $schema, 'required' => $required];
    }

    /**
     * @return list<string|object>
     */
    private function normalize(mixed $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : (array) $rules;

        return array_values(array_map(
            fn ($rule) => $rule instanceof Stringable && ! $rule instanceof ValidationRule && ! $rule instanceof In && ! $rule instanceof File ? (string) $rule : $rule,
            array_filter($rules, fn ($rule) => ! $rule instanceof Closure),
        ));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function clean(array $schema): array
    {
        if (isset($schema['properties'])) {
            $schema['properties'] = array_map(fn (array $child) => $this->clean($child), $schema['properties']);

            if ($schema['properties'] === []) {
                unset($schema['properties']);
            }
        }

        if (isset($schema['items'])) {
            $schema['items'] = $this->clean($schema['items']);
        } elseif (in_array('array', (array) ($schema['type'] ?? []), true)) {
            $schema['items'] = new \stdClass;
        }

        if (isset($schema['required'])) {
            $schema['required'] = array_values(array_unique($schema['required']));

            if ($schema['required'] === []) {
                unset($schema['required']);
            }
        }

        return $schema;
    }
}
