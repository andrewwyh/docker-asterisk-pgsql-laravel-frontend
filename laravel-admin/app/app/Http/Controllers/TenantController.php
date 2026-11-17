<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenants.index', ['tenants' => $request->user()->visibleTenants()->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', Tenant::class);

        return view('tenants.create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $tenant = DB::connection('sqlite')->transaction(function () use ($request): Tenant {
            $tenant = Tenant::create($request->safe()->only(['name', 'context']));
            DB::connection('sqlite')->table('audit_events')->insert([
                'actor_id' => $request->user()->id, 'tenant_id' => $tenant->id,
                'action' => 'tenant.created', 'details' => json_encode(['context' => $tenant->context]), 'created_at' => now(),
            ]);

            return $tenant;
        });

        return redirect()->route('tenants.show', $tenant)->with('status', 'Tenant registered. PBX accounts and routing were not changed.');
    }

    public function show(Tenant $tenant): View
    {
        Gate::authorize('view', $tenant);

        return view('tenants.show', [
            'tenant' => $tenant,
            'members' => Gate::allows('manageMembers', $tenant) ? $tenant->members()->orderBy('name')->paginate(20) : null,
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        DB::connection('sqlite')->transaction(function () use ($request, $tenant): void {
            $tenant->update($request->safe()->only('name'));
            DB::connection('sqlite')->table('audit_events')->insert([
                'actor_id' => $request->user()->id, 'tenant_id' => $tenant->id,
                'action' => 'tenant.renamed', 'details' => json_encode(['name' => $tenant->name]), 'created_at' => now(),
            ]);
        });

        return redirect()->route('tenants.show', $tenant)->with('status', 'Tenant name updated.');
    }
}
