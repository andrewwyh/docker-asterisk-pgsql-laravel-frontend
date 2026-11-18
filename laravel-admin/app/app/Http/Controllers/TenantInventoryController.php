<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantInventory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use PDOException;

class TenantInventoryController extends Controller
{
    public function __invoke(Tenant $tenant, string $resource, TenantInventory $inventory): View|Response
    {
        Gate::authorize('view', $tenant);
        $records = null;
        $status = 'disabled';
        $columns = TenantInventory::COLUMNS[$resource];
        $showPasswords = $resource === 'auths' && config('realtime.enabled')
            && config('realtime.writes_enabled') && Gate::allows('managePjsip', $tenant);

        if ($showPasswords) {
            array_splice($columns, 1, 0, ['password']);
        }

        if (config('realtime.enabled')) {
            try {
                $records = $inventory->paginate($tenant, $resource, $showPasswords);
                $status = 'available';
            } catch (QueryException|PDOException) {
                $status = 'unavailable';
            }
        }

        $page = view('tenants.inventory', [
            'tenant' => $tenant, 'resource' => $resource, 'records' => $records,
            'columns' => $columns, 'inventoryStatus' => $status,
        ]);

        return $showPasswords ? response($page)->header('Cache-Control', 'private, no-store') : $page;
    }
}
