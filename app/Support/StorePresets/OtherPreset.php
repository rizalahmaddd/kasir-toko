<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class OtherPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Other;
    }

    protected function rows(): array
    {
        return [
            'Umum' => [],
            'Jasa' => [],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.allow_credit' => '1',
        ];
    }
}
