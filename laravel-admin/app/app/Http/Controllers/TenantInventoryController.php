<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantInventory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use PDOException;

class TenantInventoryController extends Controller
{
    public function __invoke(Tenant $tenant, string $resource, TenantInventory $inventory): View
    {
        Gate::authorize('view', $tenant);
        $records = null;
        $status = 'disabled';

        if (config('realtime.enabled')) {
            try {
                $records = $inventory->paginate($tenant, $resource);
                $status = 'available';
            } catch (QueryException|PDOException) {
                $status = 'unavailable';
            }
        }

        return view('tenants.inventory', [
            'tenant' => $tenant, 'resource' => $resource, 'records' => $records,
            'columns' => TenantInventory::COLUMNS[$resource], 'inventoryStatus' => $status,
        ]);
    }
}
