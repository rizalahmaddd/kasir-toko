<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class LegalLayout extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $title = null
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.legal');
    }
}
