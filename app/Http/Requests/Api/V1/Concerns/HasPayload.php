<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * The services were written for Livewire forms, which always send every field. validated() drops
 * optional fields the client left out, so payload() puts them back as null.
 */
trait HasPayload
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $topLevelFields = array_filter(array_keys($this->rules()), fn (string $key) => ! str_contains($key, '.'));

        return array_merge(array_fill_keys($topLevelFields, null), $this->validated());
    }
}
