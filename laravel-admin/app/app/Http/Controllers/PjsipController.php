<?php

namespace App\Http\Controllers;

use App\Http\Requests\PjsipRequest;
use App\Models\Tenant;
use App\Services\PjsipManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDOException;

class PjsipController extends Controller
{
    public function create(Tenant $tenant, string $resource, PjsipManager $manager): View
    {
        $manager->authorize($tenant);

        return view('pjsip.form', [
            'tenant' => $tenant, 'resource' => $resource, 'record' => null, 'token' => null,
            'endpoints' => $resource === 'endpoints' ? [] : $manager->endpoints($tenant),
        ]);
    }

    public function store(PjsipRequest $request, Tenant $tenant, string $resource, PjsipManager $manager): RedirectResponse
    {
        $id = $manager->create($tenant, $resource, $request->validated());

        return redirect()->route('pjsip.edit', [$tenant, $resource, $id])->with('status', 'PBX records created. No dialplan routes were added.');
    }

    public function edit(Tenant $tenant, string $resource, string $record, PjsipManager $manager): View|Response
    {
        $entity = $manager->record($tenant, $resource, $record, includePassword: true);

        $form = view('pjsip.form', ['tenant' => $tenant, 'resource' => $resource, 'record' => $entity, 'token' => $manager->token($tenant, $resource, $entity), 'endpoints' => []]);

        return $resource === 'auths' ? response($form)->header('Cache-Control', 'private, no-store') : $form;
    }

    public function update(PjsipRequest $request, Tenant $tenant, string $resource, string $record, PjsipManager $manager): RedirectResponse
    {
        $manager->update($tenant, $resource, $record, $request->validated());

        return redirect()->route('pjsip.edit', [$tenant, $resource, $record])->with('status', 'PBX record saved.');
    }

    public function delete(Tenant $tenant, string $resource, string $record, PjsipManager $manager): View
    {
        try {
            $preview = $manager->deletion($tenant, $resource, $record);
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['operation' => 'Dependency checks are currently unavailable. No records were deleted.']);
        }

        return view('pjsip.delete', ['tenant' => $tenant, 'resource' => $resource, 'preview' => $preview, 'token' => $manager->token($tenant, $resource, $preview['record'], $preview['related'])]);
    }

    public function destroy(PjsipRequest $request, Tenant $tenant, string $resource, string $record, PjsipManager $manager): RedirectResponse
    {
        $manager->destroy($tenant, $resource, $record, $request->validated());

        return redirect()->route('tenants.inventory', [$tenant, $resource])->with('status', 'Reviewed PBX records deleted.');
    }
}
