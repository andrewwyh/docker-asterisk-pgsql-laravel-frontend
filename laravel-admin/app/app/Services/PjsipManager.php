<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PDOException;

class PjsipManager
{
    public const PASSWORD_RULES = ['string', 'min:12', 'max:80', 'not_regex:/[\x00-\x1f\x7f]/'];

    public const FIELDS = [
        'endpoints' => ['transport', 'allow', 'disallow', 'callerid', 'direct_media', 'rtp_symmetric', 'force_rport', 'rewrite_contact'],
        'auths' => ['username', 'realm'],
        'aors' => ['max_contacts', 'default_expiration', 'minimum_expiration', 'maximum_expiration', 'qualify_frequency', 'qualify_timeout', 'remove_existing'],
    ];

    public function __construct(private TenantInventory $inventory, private PbxMutation $mutations) {}

    public function authorize(Tenant $tenant): void
    {
        Gate::authorize('managePjsip', $tenant);
        abort_unless(config('realtime.enabled') && config('realtime.writes_enabled'), 503, 'PBX management is currently disabled.');
    }

    private function db(): Connection
    {
        return DB::connection('asterisk_write');
    }

    public function record(Tenant $tenant, string $resource, string $id, bool $includePassword = false): object
    {
        $this->authorize($tenant);

        try {
            $columns = array_unique(array_merge(TenantInventory::COLUMNS[$resource], self::FIELDS[$resource]));
            if ($includePassword && $resource === 'auths') {
                $columns[] = 'password';
            }

            return $this->inventory->query($tenant, $resource, 'asterisk_write')
                ->select($columns)->selectRaw('xmin::text AS row_version')->where('id', $id)->firstOrFail();
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['operation' => 'PBX management is unavailable. Please try again later.']);
        }
    }

    public function endpoints(Tenant $tenant): array
    {
        $this->authorize($tenant);
        try {
            return $this->db()->table('ps_endpoints')->where('context', $tenant->context)->orderBy('id')->pluck('id')->all();
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['operation' => 'PBX management is unavailable.']);
        }
    }

    public function token(Tenant $tenant, string $resource, object $record, array $related = []): string
    {
        return Crypt::encryptString(json_encode([
            'tenant' => $tenant->id, 'resource' => $resource, 'id' => $record->id,
            'version' => $record->row_version, 'related' => $related, 'expires' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
    }

    private function verify(Tenant $tenant, string $resource, object $record, string $token, ?array $related = null): void
    {
        try {
            $snapshot = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['version_token' => 'Invalid form. Reopen the record and try again.']);
        }
        if (($snapshot['tenant'] ?? null) !== $tenant->id || ($snapshot['resource'] ?? null) !== $resource
            || ($snapshot['id'] ?? null) !== $record->id || ($snapshot['version'] ?? null) !== $record->row_version
            || ($snapshot['expires'] ?? 0) < time() || ($related !== null && ($snapshot['related'] ?? null) !== $related)) {
            throw ValidationException::withMessages(['version_token' => 'This record or its dependencies changed. Reload and review before saving.']);
        }
    }

    private function mutate(Tenant $tenant, string $resource, string $id, string $action, callable $callback): mixed
    {
        $this->authorize($tenant);

        return $this->mutations->run($tenant, 'pbx.'.$action, ['resource' => $resource, 'id' => $id], 'asterisk_write', 'LOCK TABLE ps_endpoints, ps_auths, ps_aors IN SHARE ROW EXCLUSIVE MODE', $callback);
    }

    private function values(string $resource, array $input): array
    {
        return array_intersect_key($input, array_flip(self::FIELDS[$resource]));
    }

    private function references(?string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value ?? '')), fn (string $id): bool => $id !== '')));
    }

    private function identity(Tenant $tenant, string $key): string
    {
        $id = $key.'_'.$tenant->context;
        if (strlen($id) > 40) {
            throw ValidationException::withMessages(['resource_key' => 'The identity including the tenant suffix must fit 40 characters.']);
        }

        return $id;
    }

    private function aorValues(array $values): void
    {
        foreach ([['minimum_expiration', 'default_expiration'], ['default_expiration', 'maximum_expiration'], ['minimum_expiration', 'maximum_expiration']] as [$minimum, $maximum]) {
            if (isset($values[$minimum], $values[$maximum]) && $values[$minimum] > $values[$maximum]) {
                throw ValidationException::withMessages([$maximum => 'Expiration settings must be in ascending order.']);
            }
        }
    }

    public function create(Tenant $tenant, string $resource, array $input): string
    {
        $this->authorize($tenant);
        $id = $this->identity($tenant, $input['resource_key']);

        return $this->mutate($tenant, $resource, $id, 'created', function () use ($tenant, $resource, $input, $id): string {
            foreach ($resource === 'endpoints' ? ['auths', 'aors', 'endpoints'] : [$resource] as $type) {
                if ($this->db()->table('ps_'.$type)->where('id', $id)->exists()) {
                    throw ValidationException::withMessages(['resource_key' => 'This identity is already in use.']);
                }
            }
            if ($resource === 'endpoints') {
                $this->db()->table('ps_auths')->insert(['id' => $id, 'auth_type' => 'userpass', 'username' => $id, 'password' => $input['password']]);
                $aor = array_replace(['max_contacts' => 1, 'remove_existing' => 'yes'], $this->values('aors', $input));
                $this->aorValues($aor);
                $this->db()->table('ps_aors')->insert(['id' => $id] + $aor);
                $endpoint = array_replace([
                    'transport' => config('realtime.transports')[0] ?? 'transport-udp', 'disallow' => 'all', 'allow' => 'ulaw,alaw',
                    'callerid' => '<'.$input['resource_key'].'>', 'direct_media' => 'no', 'rtp_symmetric' => 'yes', 'force_rport' => 'yes', 'rewrite_contact' => 'yes',
                ], $this->values('endpoints', $input));
                if ($endpoint['callerid'] === null) {
                    $endpoint['callerid'] = '<'.$input['resource_key'].'>';
                }
                $this->db()->table('ps_endpoints')->insert(['id' => $id, 'context' => $tenant->context, 'auth' => $id, 'aors' => $id] + $endpoint);
            } else {
                $endpoint = $this->db()->table('ps_endpoints')->where('id', $input['endpoint_id'])->where('context', $tenant->context)->firstOrFail();
                $field = $resource === 'auths' ? 'auth' : 'aors';
                $links = $this->references($endpoint->{$field});
                $links[] = $id;
                $joined = implode(',', $links);
                if (strlen($joined) > ($resource === 'auths' ? 255 : 2048)) {
                    throw ValidationException::withMessages(['endpoint_id' => 'This endpoint has no room for another reference.']);
                }
                $values = $this->values($resource, $input);
                if ($resource === 'auths') {
                    $values['username'] = $values['username'] ?? $id;
                    if (strlen($values['username']) > 40) {
                        throw ValidationException::withMessages(['username' => 'Specify a SIP username of at most 40 characters.']);
                    }
                    $values += ['auth_type' => 'userpass', 'password' => $input['password']];
                } else {
                    $values += ['max_contacts' => 1];
                    $this->aorValues($values);
                }
                $this->db()->table('ps_'.$resource)->insert(['id' => $id] + $values);
                $this->db()->table('ps_endpoints')->where('id', $endpoint->id)->update([$field => $joined]);
            }

            return $id;
        });
    }

    public function update(Tenant $tenant, string $resource, string $id, array $input): void
    {
        $this->mutate($tenant, $resource, $id, 'updated', function () use ($tenant, $resource, $id, $input): void {
            $record = $this->record($tenant, $resource, $id, includePassword: true);
            $this->verify($tenant, $resource, $record, $input['version_token']);
            $values = $this->values($resource, $input);
            $password = $input['password'] ?? '';
            if ($resource === 'auths' && $password !== '' && $password !== $record->password) {
                if ($record->auth_type !== 'userpass') {
                    throw ValidationException::withMessages(['password' => 'Password replacement is supported only for userpass authentication.']);
                }
                Validator::make(['password' => $password], ['password' => ['required', ...self::PASSWORD_RULES]])->validate();
                $values += ['password' => $password, 'md5_cred' => null, 'password_digest' => null];
            }
            if ($resource === 'aors') {
                $this->aorValues(array_replace((array) $record, $values));
            }
            if ($values !== []) {
                $this->db()->table('ps_'.$resource)->where('id', $id)->update($values);
            }
        });
    }

    private function dependents(string $resource, string $id): array
    {
        $query = $this->db()->table('ps_endpoints')->select(['id', 'context', 'auth', 'outbound_auth', 'aors'])->selectRaw('xmin::text AS row_version');
        $fields = $resource === 'auths' ? ['auth', 'outbound_auth'] : ['aors'];
        $query->where(function ($query) use ($fields, $id): void {
            foreach ($fields as $field) {
                $query->orWhereRaw("EXISTS (SELECT 1 FROM unnest(string_to_array({$field}, ',')) AS ref(value) WHERE btrim(ref.value) = ?)", [$id]);
            }
        });

        return $query->orderBy('id')->get()->all();
    }

    private function registered(array $endpoints, array $aors): bool
    {
        if ($endpoints === [] && $aors === []) {
            return false;
        }

        return $this->db()->table('ps_contacts')->select('id')->where(function ($query) use ($endpoints, $aors): void {
            $query->whereIn('endpoint', $endpoints)->orWhereIn(DB::raw("split_part(id, ';', 1)"), $aors);
        })->where(function ($query): void {
            $query->whereNull('expiration_time')->orWhere('expiration_time', '>', time());
        })->exists();
    }

    public function deletion(Tenant $tenant, string $resource, string $id): array
    {
        $record = $this->record($tenant, $resource, $id);
        $related = [];
        $blocked = [];
        if ($resource === 'endpoints') {
            $endpoint = $this->db()->table('ps_endpoints')->where('id', $id)->first(['auth', 'outbound_auth', 'aors']);
            foreach (['auths' => array_unique(array_merge($this->references($endpoint->auth), $this->references($endpoint->outbound_auth))), 'aors' => $this->references($endpoint->aors)] as $type => $ids) {
                foreach ($ids as $linkedId) {
                    $references = $this->dependents($type, $linkedId);
                    if (count($references) !== 1 || $references[0]->id !== $id) {
                        $blocked[] = 'A linked record is shared with another endpoint.';

                        continue;
                    }
                    $linked = $this->record($tenant, $type, $linkedId);
                    $related[$type][$linkedId] = $linked->row_version;
                }
            }
            if ($this->db()->table('ps_endpoint_id_ips')->select('id')->where('endpoint', $id)->exists()
                || $this->db()->table('ps_registrations')->select('id')->where('endpoint', $id)->exists()
                || $this->db()->table('extensions')->select('id')->whereRaw('strpos(appdata, ?) > 0', [$id])->exists()) {
                $blocked[] = 'The endpoint has an IP identifier, outbound registration, or dialplan dependency.';
            }
            if ($this->registered([$id], $this->references($endpoint->aors))) {
                $blocked[] = 'Unregister the phones using this account before deleting it.';
            }
            foreach (array_keys($related['auths'] ?? []) as $authId) {
                if ($this->registrationUsesAuth($authId)) {
                    $blocked[] = 'A linked authentication record is used by an outbound registration.';
                }
            }
        } else {
            $references = $this->dependents($resource, $id);
            if (count($references) !== 1) {
                $blocked[] = 'This record is shared by multiple endpoints.';
            }
            foreach ($references as $endpoint) {
                $related['endpoints'][$endpoint->id] = $endpoint->row_version;
                foreach ($resource === 'auths' ? ['auth', 'outbound_auth'] : ['aors'] as $field) {
                    $links = $this->references($endpoint->{$field});
                    if (in_array($id, $links, true) && count($links) === 1) {
                        $blocked[] = 'This is the last record in an endpoint reference. Delete the SIP account instead.';
                    }
                }
            }
            if ($resource === 'auths' && $this->registrationUsesAuth($id)) {
                $blocked[] = 'This authentication record is used by an outbound registration.';
            }
            if ($resource === 'aors' && $this->registered([], [$id])) {
                $blocked[] = 'Unregister phones using this AOR before deleting it.';
            }
        }

        return ['record' => $record, 'related' => $related, 'blocked' => array_values(array_unique($blocked))];
    }

    private function registrationUsesAuth(string $id): bool
    {
        return $this->db()->table('ps_registrations')->select('id')->whereRaw("EXISTS (SELECT 1 FROM unnest(string_to_array(outbound_auth, ',')) AS ref(value) WHERE btrim(ref.value) = ?)", [$id])->exists();
    }

    public function destroy(Tenant $tenant, string $resource, string $id, array $input): void
    {
        $this->mutate($tenant, $resource, $id, 'deleted', function () use ($tenant, $resource, $id, $input): void {
            $preview = $this->deletion($tenant, $resource, $id);
            $this->verify($tenant, $resource, $preview['record'], $input['version_token'], $preview['related']);
            if ($preview['blocked'] !== []) {
                throw ValidationException::withMessages(['operation' => implode(' ', $preview['blocked'])]);
            }
            if ($resource === 'endpoints') {
                $this->db()->table('ps_endpoints')->where('id', $id)->delete();
                foreach (['auths', 'aors'] as $type) {
                    $this->db()->table('ps_'.$type)->whereIn('id', array_keys($preview['related'][$type] ?? []))->delete();
                }
            } else {
                foreach (array_keys($preview['related']['endpoints'] ?? []) as $endpointId) {
                    $endpoint = $this->db()->table('ps_endpoints')->where('id', $endpointId)->first();
                    $changes = [];
                    foreach ($resource === 'auths' ? ['auth', 'outbound_auth'] : ['aors'] as $field) {
                        $links = $this->references($endpoint->{$field});
                        if (in_array($id, $links, true)) {
                            $changes[$field] = implode(',', array_values(array_diff($links, [$id]))) ?: null;
                        }
                    }
                    $this->db()->table('ps_endpoints')->where('id', $endpointId)->update($changes);
                }
                $this->db()->table('ps_'.$resource)->where('id', $id)->delete();
            }
        });
    }
}
