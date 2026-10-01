<?php

namespace App\Support\OpenApi;

use InvalidArgumentException;

/**
 * Parses the PHPDoc types used on API resources (array shapes, list<>, unions, resource class
 * names) into an intermediate tree that SchemaBuilder turns into JSON Schema.
 *
 * Supported: array{a: int, b?: ?string}, list<T>, array<K, V>, T|null, ?T, identifiers.
 */
class TypeParser
{
    private string $input;

    private int $position = 0;

    /**
     * @return array{kind: string, name?: string, fields?: array<string, array{type: array<string, mixed>, optional: bool}>, of?: array<string, mixed>, types?: list<array<string, mixed>>}
     */
    public function parse(string $type): array
    {
        $this->input = $type;
        $this->position = 0;

        $result = $this->union();
        $this->skipWhitespace();

        if ($this->position < strlen($this->input)) {
            throw new InvalidArgumentException("Unexpected '{$this->input[$this->position]}' at {$this->position} in type: {$type}");
        }

        return $result;
    }

    private function union(): array
    {
        $types = [$this->atom()];

        while ($this->consume('|')) {
            $types[] = $this->atom();
        }

        return count($types) === 1 ? $types[0] : ['kind' => 'union', 'types' => $types];
    }

    private function atom(): array
    {
        if ($this->consume('?')) {
            return ['kind' => 'union', 'types' => [$this->atom(), ['kind' => 'scalar', 'name' => 'null']]];
        }

        $name = $this->identifier();
        $lower = strtolower($name);

        if (in_array($lower, ['array', 'list', 'non-empty-list', 'non-empty-array'], true) && $this->consume('{')) {
            return ['kind' => 'shape', 'fields' => $this->fields()];
        }

        if (in_array($lower, ['array', 'list', 'non-empty-list', 'non-empty-array', 'iterable', 'collection'], true) && $this->consume('<')) {
            $value = $this->union();
            $isMap = false;

            // array<string, X> is a map; list<X> and array<int, X> are arrays.
            if ($this->consume(',')) {
                $isMap = ($value['name'] ?? null) === 'string';
                $value = $this->union();
            }

            $this->expect('>');

            return ['kind' => $isMap ? 'map' : 'list', 'of' => $value];
        }

        return ['kind' => 'scalar', 'name' => $name];
    }

    /**
     * @return array<string, array{type: array<string, mixed>, optional: bool}>
     */
    private function fields(): array
    {
        $fields = [];

        while (! $this->consume('}')) {
            $key = $this->key();
            $optional = $this->consume('?');
            $this->expect(':');
            $fields[$key] = ['type' => $this->union(), 'optional' => $optional];

            if (! $this->consume(',')) {
                $this->expect('}');

                break;
            }
        }

        return $fields;
    }

    private function key(): string
    {
        $this->skipWhitespace();
        $char = $this->input[$this->position] ?? '';

        if ($char === "'" || $char === '"') {
            $end = strpos($this->input, $char, $this->position + 1);
            $key = substr($this->input, $this->position + 1, $end - $this->position - 1);
            $this->position = $end + 1;

            return $key;
        }

        return $this->identifier();
    }

    private function identifier(): string
    {
        $this->skipWhitespace();

        if (! preg_match('/\G[A-Za-z_\\\\][A-Za-z0-9_\\\\\-]*/', $this->input, $match, 0, $this->position)) {
            throw new InvalidArgumentException("Expected identifier at {$this->position} in type: {$this->input}");
        }

        $this->position += strlen($match[0]);

        return $match[0];
    }

    private function consume(string $token): bool
    {
        $this->skipWhitespace();

        if (substr($this->input, $this->position, strlen($token)) === $token) {
            $this->position += strlen($token);

            return true;
        }

        return false;
    }

    private function expect(string $token): void
    {
        if (! $this->consume($token)) {
            throw new InvalidArgumentException("Expected '{$token}' at {$this->position} in type: {$this->input}");
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->position < strlen($this->input) && ctype_space($this->input[$this->position])) {
            $this->position++;
        }
    }
}
