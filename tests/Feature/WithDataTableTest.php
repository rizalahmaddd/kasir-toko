<?php

use App\Livewire\Concerns\WithDataTable;
use App\Models\Customer;
use Livewire\Component;

class DummyTableComponent extends Component
{
    use WithDataTable;

    public function render()
    {
        return '<div>Dummy</div>';
    }

    public function testExport()
    {
        return $this->exportCsvResponse('test.csv', ['Nama', 'Total'], [
            ['PT Contoh', 'Rp100.000'],
            ['=MALICIOUS_FORMULA', 'Rp50.000'],
        ]);
    }

    public function testApplySorting($query, array $sortMap = [])
    {
        return $this->applySorting($query, $sortMap);
    }
}

test('sortBy toggles direction and sets new field', function () {
    $component = new DummyTableComponent;
    $component->sortField = 'invoice_number';
    $component->sortDirection = 'asc';

    // Toggle same field
    $component->sortBy('invoice_number');
    expect($component->sortDirection)->toBe('desc');

    // Switch to date field -> defaults to desc
    $component->sortBy('invoice_date');
    expect($component->sortField)->toBe('invoice_date')
        ->and($component->sortDirection)->toBe('desc');

    // Switch to string field -> defaults to asc
    $component->sortBy('customer_name');
    expect($component->sortField)->toBe('customer_name')
        ->and($component->sortDirection)->toBe('asc');
});

test('exportCsvResponse outputs UTF-8 BOM and sanitizes formula injection', function () {
    $component = new DummyTableComponent;
    $response = $component->testExport();

    expect($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))->toContain('filename=test.csv');

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    // Contains UTF-8 BOM
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue();
    // Formula starting with = is escaped with single quote
    expect($content)->toContain("'=MALICIOUS_FORMULA");
    expect($content)->toContain('PT Contoh');
});

test('applySorting casts numeric fields to real to prevent lexicographical sort bugs', function () {
    $component = new DummyTableComponent;
    $component->sortField = 'total_amount';
    $component->sortDirection = 'asc';

    $query = Customer::query();
    $sortedQuery = $component->testApplySorting($query, [
        'total_amount' => 'numeric',
    ]);

    $sql = $sortedQuery->toSql();
    expect($sql)->toContain('CAST(total_amount AS DECIMAL(16,4))');
});

test('applySorting handles custom mathematical expressions', function () {
    $component = new DummyTableComponent;
    $component->sortField = 'remaining_amount';
    $component->sortDirection = 'desc';

    $query = Customer::query();
    $sortedQuery = $component->testApplySorting($query, [
        'remaining_amount' => '(CAST(total_amount AS DECIMAL(16,4)) - CAST(paid_amount AS DECIMAL(16,4)))',
    ]);

    $sql = $sortedQuery->toSql();
    expect($sql)->toContain('(CAST(total_amount AS DECIMAL(16,4)) - CAST(paid_amount AS DECIMAL(16,4))) desc');
});

test('applySorting replaces legacy AS REAL with AS DECIMAL(16,4) for MariaDB/MySQL compatibility', function () {
    $component = new DummyTableComponent;
    $component->sortField = 'remaining_amount';
    $component->sortDirection = 'desc';

    $query = Customer::query();
    $sortedQuery = $component->testApplySorting($query, [
        'remaining_amount' => '(CAST(total_amount AS REAL) - CAST(paid_amount AS REAL))',
    ]);

    $sql = $sortedQuery->toSql();
    expect($sql)->toContain('(CAST(total_amount AS DECIMAL(16,4)) - CAST(paid_amount AS DECIMAL(16,4))) desc');
});
