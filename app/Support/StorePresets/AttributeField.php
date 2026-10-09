<?php

namespace App\Support\StorePresets;

/**
 * Satu isian atribut produk (disimpan di products.attributes). Isian yang memengaruhi logika kasir
 * (golongan obat, wajib resep) sengaja tidak di sini, melainkan kolom produk sendiri.
 */
final class AttributeField
{
    public const TYPES = ['text', 'select', 'number', 'date', 'bool'];

    /**
     * @param  'text'|'select'|'number'|'date'|'bool'  $type
     * @param  array<string, string>  $options  nilai => label, untuk tipe select
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly array $options = [],
        public readonly bool $required = false,
        public readonly bool $onReceipt = false,
        public readonly bool $searchable = false,
        public readonly ?string $placeholder = null,
    ) {}

    /**
     * @return array{key: string, label: string, type: string, options: list<array{value: string, label: string}>, required: bool, on_receipt: bool, searchable: bool, placeholder: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => collect($this->options)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'required' => $this->required,
            'on_receipt' => $this->onReceipt,
            'searchable' => $this->searchable,
            'placeholder' => $this->placeholder,
        ];
    }

    /**
     * @return list<mixed>
     */
    public function rules(): array
    {
        $presence = $this->required ? 'required' : 'nullable';

        return match ($this->type) {
            'select' => [$presence, 'string', 'in:'.implode(',', array_keys($this->options))],
            'number' => [$presence, 'numeric', 'min:0', 'max:999999999'],
            'date' => [$presence, 'date'],
            'bool' => ['nullable', 'boolean'],
            default => [$presence, 'string', 'max:150'],
        };
    }

    public function display(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'select' => $this->options[$value] ?? (string) $value,
            'bool' => $value ? 'Ya' : 'Tidak',
            default => (string) $value,
        };
    }
}
