<?php

namespace App\Http\Controllers;

use App\Http\Requests\DialplanRequest;
use App\Models\Tenant;
use App\Services\DialplanManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DialplanController extends Controller
{
    public function index(Tenant $tenant, DialplanManager $manager): View
    {
        return view('dialplan.index', ['tenant' => $tenant, 'routes' => $manager->listing($tenant)]);
    }

    public function create(Tenant $tenant, DialplanManager $manager): View
    {
        $manager->authorize($tenant);

        return view('dialplan.form', ['tenant' => $tenant, 'extension' => null, 'steps' => [], 'token' => null, 'summary' => [], 'endpoints' => $manager->endpoints($tenant)]);
    }

    public function edit(Tenant $tenant, string $extension, DialplanManager $manager): View
    {
        return view('dialplan.form', ['tenant' => $tenant, 'extension' => $extension, 'endpoints' => $manager->endpoints($tenant)] + $manager->form($tenant, $extension));
    }

    public function submit(DialplanRequest $request, Tenant $tenant, DialplanManager $manager, ?string $extension = null): View|RedirectResponse
    {
        $creating = $extension === null;
        $extension = $extension ?? $request->validated('extension');
        $steps = array_values($request->validated('steps'));
        if ($request->validated('action') === 'preview') {
            return view('dialplan.preview', ['tenant' => $tenant, 'extension' => $extension, 'steps' => $steps, 'creating' => $creating] + $manager->preview($tenant, $extension, $steps, $request->validated('version_token'), $creating));
        }
        $manager->save($tenant, $extension, $steps, $request->validated('version_token'), $creating);

        return redirect()->route('dialplan.edit', [$tenant, $extension])->with('status', 'Dialplan route saved.');
    }

    public function delete(Tenant $tenant, string $extension, DialplanManager $manager): View
    {
        return view('dialplan.delete', ['tenant' => $tenant, 'extension' => $extension] + $manager->form($tenant, $extension));
    }

    public function destroy(DialplanRequest $request, Tenant $tenant, string $extension, DialplanManager $manager): RedirectResponse
    {
        $manager->destroy($tenant, $extension, $request->validated('version_token'));

        return redirect()->route('dialplan.index', $tenant)->with('status', 'Reviewed dialplan route deleted.');
    }

    public function provision(Request $request, Tenant $tenant, DialplanManager $manager): RedirectResponse
    {
        $manager->authorize($tenant);
        $request->validate(['confirm_provision' => ['required', 'accepted']]);
        $result = $manager->provision($tenant);

        return redirect()->route('dialplan.index', $tenant)->with('status', $result['created'].' routes created; '.$result['skipped'].' existing routes preserved.');
    }
}
