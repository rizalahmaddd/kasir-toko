<?php

use Illuminate\Pagination\LengthAwarePaginator;

test('pagination component renders windowed page numbers when there are many pages', function () {
    $paginator = new LengthAwarePaginator(range(1, 10), total: 500, perPage: 10, currentPage: 1);

    $this->blade('<x-table.pagination :paginator="$paginator" />', ['paginator' => $paginator])
        ->assertSee('wire:click="gotoPage(2)"', false)
        ->assertSee('wire:click="gotoPage(50)"', false)
        ->assertDontSee('wire:click="gotoPage(25)"', false)
        ->assertSee('...');
});
