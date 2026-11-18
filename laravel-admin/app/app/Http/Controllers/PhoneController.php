<?php

namespace App\Http\Controllers;

use App\Models\Phone;
use App\Models\Tenant;
use App\Services\PhoneReboot;
use App\Services\TenantInventory;
use DOMDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDOException;

class PhoneController extends Controller
{
    public function index(Tenant $tenant): View
    {
        Gate::authorize('view', $tenant);

        return view('phones.index', [
            'tenant' => $tenant,
            'phones' => $tenant->phones()->orderBy('name')->paginate(25),
        ]);
    }

    public function create(Tenant $tenant): View
    {
        Gate::authorize('update', $tenant);

        return view('phones.form', ['tenant' => $tenant, 'phone' => new Phone]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('update', $tenant);

        $data = $this->validated($request, $tenant, new Phone);
        try {
            $phone = $tenant->phones()->create($data + ['token' => bin2hex(random_bytes(32))]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['mac' => 'This MAC address or SIP endpoint is already assigned.']);
        }

        return to_route('tenants.phones.edit', [$tenant, $phone])->with('status', 'Phone added.');
    }

    public function edit(Tenant $tenant, Phone $phone): Response
    {
        Gate::authorize('update', $tenant);

        $url = $phone->profileUrl();

        return response()->view('phones.form', compact('tenant', 'phone', 'url'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Tenant $tenant, Phone $phone): RedirectResponse
    {
        Gate::authorize('update', $tenant);

        $data = $this->validated($request, $tenant, $phone);
        $phone->fill($data);
        if ($phone->isDirty('mac') || $phone->isDirty('endpoint')) {
            $phone->token = bin2hex(random_bytes(32));
        }
        try {
            $phone->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['mac' => 'This MAC address or SIP endpoint is already assigned.']);
        }

        return to_route('tenants.phones.index', $tenant)->with('status', 'Phone updated.');
    }

    public function destroy(Tenant $tenant, Phone $phone): RedirectResponse
    {
        Gate::authorize('update', $tenant);
        $phone->delete();

        return to_route('tenants.phones.index', $tenant)->with('status', 'Phone removed.');
    }

    public function profile(string $token, string $filename): Response
    {
        $phone = Phone::query()->where('token', $token)->firstOrFail();
        abort_unless(strtolower($filename) === "$phone->mac.$phone->format", 404);

        return response($phone->profile, 200, [
            'Content-Type' => $phone->format === 'xml' ? 'application/xml' : 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reboot(Tenant $tenant, Phone $phone, PhoneReboot $reboot): RedirectResponse
    {
        Gate::authorize('update', $tenant);

        if (! $this->endpointExists($tenant, $phone->endpoint) || ! $reboot->send($phone->endpoint)) {
            return back()->withErrors(['reboot' => 'Reboot request failed. Check the endpoint, PBX connection and AMI configuration.']);
        }

        return back()->with('status', 'Asterisk accepted the reboot request. Check the handset for completion.');
    }

    private function validated(Request $request, Tenant $tenant, Phone $phone): array
    {
        $mac = $request->input('mac');
        if (is_string($mac)) {
            $request->merge(['mac' => strtolower(str_replace([':', '-'], '', $mac))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mac' => ['required', 'string', 'regex:/\\A[a-f0-9]{12}\\z/', Rule::unique('sqlite.phones')->ignore($phone)],
            'endpoint' => ['required', 'string', 'regex:/\\A[A-Za-z0-9_.@+-]{1,128}\\z/', Rule::unique('sqlite.phones')->ignore($phone)],
            'profile_url' => [$phone->exists ? 'sometimes' : 'prohibited', 'nullable', 'url:http,https', 'max:2048'],
            'profile' => [$phone->exists ? 'nullable' : 'required', 'file', 'max:512', 'extensions:xml,cfg'],
        ]);

        if ((! $phone->exists || $phone->endpoint !== $data['endpoint']) && ! $this->endpointExists($tenant, $data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => 'Choose a PJSIP endpoint in this context. Check PBX connectivity if needed.']);
        }

        if (array_key_exists('profile_url', $data)) {
            $url = $data['profile_url'];
            unset($data['profile_url']);
            $data['profile_origin'] = null;
            if ($url !== null && $url !== '') {
                $parts = parse_url($url);
                $path = route('phones.profile', [$phone->token, "$phone->mac.$phone->format"], absolute: false);
                if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
                    || isset($parts['query']) || isset($parts['fragment']) || ! str_ends_with($parts['path'] ?? '', $path)) {
                    throw ValidationException::withMessages(['profile_url' => 'Change the HTTP/HTTPS host, port, or proxy prefix while keeping the private provisioning path unchanged. Do not add credentials, a query, or a fragment.']);
                }
                $data['profile_origin'] = substr($url, 0, -strlen($path));
            }
        }

        unset($data['profile']);
        if ($file = $request->file('profile')) {
            $profile = $file->get();
            $format = strtolower($file->getClientOriginalExtension());
            if (! is_string($profile) || trim($profile) === '') {
                throw ValidationException::withMessages(['profile' => 'Upload a nonempty provisioning file.']);
            }
            if ($format === 'xml') {
                $document = new DOMDocument;
                if (! $document->loadXML($profile, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $document->doctype !== null) {
                    throw ValidationException::withMessages(['profile' => 'Upload well-formed XML without a document type declaration.']);
                }
            }
            $data['profile'] = $profile;
            $data['format'] = $format;
        }

        return $data;
    }

    private function endpointExists(Tenant $tenant, string $endpoint): bool
    {
        if (! config('realtime.enabled')) {
            return false;
        }

        try {
            return app(TenantInventory::class)->query($tenant, 'endpoints')->where('id', $endpoint)->exists();
        } catch (PDOException) {
            return false;
        }
    }
}
