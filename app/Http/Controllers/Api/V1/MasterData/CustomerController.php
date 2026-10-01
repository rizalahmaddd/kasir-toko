<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\MasterData\CustomerRequest;
use App\Http\Resources\V1\MasterData\CustomerResource;
use App\Models\Customer;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

#[ApiTag('Pelanggan', 'Master Data')]
class CustomerController extends Controller
{
    /**
     * Daftar pelanggan.
     */
    #[ApiQuery('search', description: 'Cari kode atau nama.')]
    #[ApiQuery('is_active', 'boolean', 'Hanya yang aktif (true) / nonaktif (false).')]
    #[ApiResponse(CustomerResource::class, paginated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('view-master-data');

        $records = Customer::query()
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$request->search}%")
                ->orWhere('code', 'like', "%{$request->search}%")))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return CustomerResource::collection($records);
    }

    /**
     * Detail pelanggan.
     */
    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view-master-data');

        return new CustomerResource($customer);
    }

    /**
     * Tambah pelanggan.
     */
    #[ApiResponse(CustomerResource::class, status: 201)]
    public function store(CustomerRequest $request): CustomerResource
    {
        return new CustomerResource(Customer::create($request->validated()));
    }

    /**
     * Ubah pelanggan.
     */
    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    /**
     * Hapus pelanggan.
     *
     * Ditolak (422) bila datanya masih dipakai transaksi lain.
     */
    public function destroy(Customer $customer): Response
    {
        Gate::authorize('manage-master-data');

        return $this->deleteRecord($customer);
    }
}
