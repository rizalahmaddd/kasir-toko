<?php

function selectMarkup(int $realOptions, string $props = ''): string
{
    $options = collect(range(1, $realOptions))
        ->map(fn (int $number) => "<option value=\"{$number}\">Opsi {$number}</option>")
        ->implode('');

    return "<x-select wire:model=\"choice\" id=\"choice\" {$props}><option value=\"\">-- Pilih --</option>{$options}</x-select>";
}

test('select becomes searchable when it has more than six real options', function () {
    $this->blade(selectMarkup(7))
        ->assertSee('searchableSelect', false)
        ->assertSee('<button', false);
});

test('select stays a native dropdown with six options, not counting the empty placeholder', function () {
    $this->blade(selectMarkup(6))
        ->assertDontSee('searchableSelect', false)
        ->assertSee('<select', false);
});

test('searchable prop overrides the option count', function () {
    $this->blade(selectMarkup(3, ':searchable="true"'))
        ->assertSee('searchableSelect', false);

    $this->blade(selectMarkup(20, ':searchable="false"'))
        ->assertDontSee('searchableSelect', false);
});

test('searchable select keeps the binding on the native select and moves the id to the button', function () {
    $html = (string) $this->blade(selectMarkup(7));

    expect($html)
        ->toMatch('/<select[^>]*wire:model="choice"/')
        ->toMatch('/<button[^>]*id="choice"/')
        ->not->toMatch('/<select[^>]*id="choice"/');
});
