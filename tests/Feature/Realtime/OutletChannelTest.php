<?php

use App\Events\KitchenTicketCreated;
use App\Events\SaleRecorded;
use App\Livewire\Inventory\StockIndex;
use App\Livewire\Kitchen\KitchenBoard;
use App\Models\KitchenTicket;
use App\Models\Sale;
use App\Services\Pos\KitchenTicketService;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use App\Support\Features;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function outletChannelAllowed($user, int $tenantId, int $outletId): bool
{
    app()->forgetInstance(CurrentOutlet::class);

    $callback = Broadcast::driver()->getChannels()['tenant.{tenantId}.outlet.{outletId}'];

    return (bool) $callback($user, $tenantId, $outletId);
}

test('only users allowed to use an outlet may listen to its channel', function () {
    $admin = actingAsAdmin();
    $branch = makeOutlet(['name' => 'Cabang']);
    $kasir = actingAsRole('kasir');
    $tenantId = app(CurrentTenant::class)->id();
    $kasir->forceFill(['all_outlets' => false])->save();
    $kasir->outlets()->attach(primaryOutlet()->id, ['tenant_id' => $tenantId]);

    expect(outletChannelAllowed($admin, $tenantId, $branch->id))->toBeTrue()
        ->and(outletChannelAllowed($kasir, $tenantId, primaryOutlet()->id))->toBeTrue()
        ->and(outletChannelAllowed($kasir, $tenantId, $branch->id))->toBeFalse()
        ->and(outletChannelAllowed($admin, $tenantId + 999, $branch->id))->toBeFalse();
});

test('a sale is broadcast to its outlet and the shop dashboard, kitchen tickets only to the outlet', function () {
    actingAsAdmin();
    $tenantId = app(CurrentTenant::class)->id();
    $sale = Sale::factory()->create();
    $ticket = KitchenTicket::query()->create(['label' => 'Meja 1', 'items' => []]);

    $saleChannels = collect((new SaleRecorded($sale))->broadcastOn())->map(fn (PrivateChannel $channel) => $channel->name)->all();
    $ticketChannels = collect((new KitchenTicketCreated($ticket))->broadcastOn())->map(fn (PrivateChannel $channel) => $channel->name)->all();

    expect($saleChannels)->toBe(["private-tenant.{$tenantId}.dashboard", "private-tenant.{$tenantId}.outlet.{$sale->outlet_id}"])
        ->and($ticketChannels)->toBe(["private-tenant.{$tenantId}.outlet.{$ticket->outlet_id}"]);
});

test('outlet screens listen on the outlet channel', function () {
    Features::setEnabled(['business.order-type']);
    actingAsAdmin();
    $channel = app(CurrentOutlet::class)->channel();

    expect(Livewire::test(KitchenBoard::class)->instance()->getListeners())->toHaveKey("echo-private:{$channel},.kitchen.ticket")
        ->and(Livewire::test(StockIndex::class)->instance()->getListeners())->toHaveKey("echo-private:{$channel},.sale.recorded");
});

test('kitchen tickets are announced only after the checkout commits', function () {
    Event::fake([KitchenTicketCreated::class]);
    Features::setEnabled(['business.order-type']);
    actingAsAdmin();

    DB::transaction(function () {
        app(KitchenTicketService::class)->send([['product_id' => 1, 'name' => 'Latte', 'quantity' => 1]], [], 'Meja 2', 'dine_in', auth()->user());
        Event::assertNotDispatched(KitchenTicketCreated::class);
    });

    Event::assertDispatched(KitchenTicketCreated::class);
});
