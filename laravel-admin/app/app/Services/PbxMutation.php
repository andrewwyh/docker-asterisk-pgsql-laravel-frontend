<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

class PbxMutation
{
    public function run(Tenant $tenant, string $action, array $identifiers, string $connection, string $lockSql, callable $callback): mixed
    {
        $details = ['operation_id' => (string) Str::uuid(), 'status' => 'pending'] + $identifiers;
        $audit = DB::connection('sqlite')->table('audit_events')->insertGetId([
            'actor_id' => auth()->id(), 'tenant_id' => $tenant->id, 'action' => $action,
            'details' => json_encode($details), 'created_at' => now(),
        ]);
        try {
            $result = DB::connection($connection)->transaction(function () use ($callback, $connection, $lockSql): mixed {
                DB::connection($connection)->select('SELECT pg_advisory_xact_lock(171117, 1)');
                DB::connection($connection)->statement($lockSql);

                return $callback();
            });
        } catch (Throwable $exception) {
            $details['status'] = $exception instanceof QueryException || $exception instanceof PDOException ? 'failed_or_unknown' : 'rejected';
            try {
                DB::connection('sqlite')->table('audit_events')->where('id', $audit)->update(['details' => json_encode($details)]);
            } catch (Throwable) {
                // The pending audit entry is retained if the application store is unavailable.
            }
            if ($exception instanceof QueryException || $exception instanceof PDOException) {
                throw ValidationException::withMessages(['operation' => 'The PBX operation could not be confirmed. Refresh the inventory before retrying.']);
            }
            throw $exception;
        }
        $details['status'] = 'committed';
        try {
            DB::connection('sqlite')->table('audit_events')->where('id', $audit)->update(['details' => json_encode($details)]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['operation' => 'The PBX change was saved, but audit completion is pending. Refresh before retrying.']);
        }

        return $result;
    }
}
