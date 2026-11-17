<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TenantInventory
{
    public const COLUMNS = [
        'endpoints' => ['id', 'context', 'transport', 'auth', 'aors', 'allow', 'callerid'],
        'auths' => ['id', 'auth_type', 'username', 'realm'],
        'aors' => ['id', 'max_contacts', 'default_expiration', 'maximum_expiration', 'qualify_frequency'],
    ];

    public function paginate(Tenant $tenant, string $resource): LengthAwarePaginator
    {
        $table = 'ps_'.$resource;
        $query = DB::connection('asterisk')->table($table)->select(self::COLUMNS[$resource]);

        if ($resource === 'endpoints') {
            $query->where('context', $tenant->context);
        } else {
            $fields = $resource === 'auths' ? ['auth', 'outbound_auth'] : ['aors'];
            $references = implode(' OR ', array_map(
                fn (string $field): string => "EXISTS (SELECT 1 FROM unnest(string_to_array(e.{$field}, ',')) AS ref(value) WHERE btrim(ref.value) = {$table}.id)",
                $fields,
            ));
            $query->whereExists(function (Builder $endpoints) use ($tenant, $references): void {
                $endpoints->selectRaw('1')->from('ps_endpoints as e')->where('e.context', $tenant->context)->whereRaw('('.$references.')');
            });
            $query->whereNotExists(function (Builder $endpoints) use ($tenant, $references): void {
                $endpoints->selectRaw('1')->from('ps_endpoints as e')->whereRaw("COALESCE(e.context, '') <> ?", [$tenant->context])->whereRaw('('.$references.')');
            });
        }

        return $query->orderBy('id')->paginate(25)->withQueryString();
    }
}
