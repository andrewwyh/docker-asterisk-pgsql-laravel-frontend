<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantMemberRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TenantMemberController extends Controller
{
    public function store(StoreTenantMemberRequest $request, Tenant $tenant): RedirectResponse
    {
        $user = User::where('email', $request->validated('email'))->firstOrFail();
        DB::connection('sqlite')->transaction(function () use ($request, $tenant, $user): void {
            $tenant->members()->syncWithoutDetaching([$user->id => ['role' => $request->validated('role')]]);
            DB::connection('sqlite')->table('audit_events')->insert([
                'actor_id' => $request->user()->id, 'tenant_id' => $tenant->id,
                'action' => 'tenant.member_assigned', 'details' => json_encode(['user_id' => $user->id, 'role' => $request->validated('role')]), 'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Tenant access updated.');
    }

    public function destroy(Request $request, Tenant $tenant, User $user): RedirectResponse
    {
        Gate::authorize('manageMembers', $tenant);
        abort_unless($tenant->members()->whereKey($user->id)->exists(), 404);
        DB::connection('sqlite')->transaction(function () use ($request, $tenant, $user): void {
            $tenant->members()->detach($user->id);
            DB::connection('sqlite')->table('audit_events')->insert([
                'actor_id' => $request->user()->id, 'tenant_id' => $tenant->id,
                'action' => 'tenant.member_removed', 'details' => json_encode(['user_id' => $user->id]), 'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Tenant access removed.');
    }
}
