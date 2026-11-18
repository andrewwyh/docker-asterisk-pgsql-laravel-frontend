<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PDOException;

class DialplanManager
{
    public const APPLICATIONS = ['Answer', 'Wait', 'Playback', 'Echo', 'Dial', 'Hangup'];

    public function __construct(private PbxMutation $mutations) {}

    public function authorize(Tenant $tenant): void
    {
        Gate::authorize('manageDialplan', $tenant);
        abort_unless(config('realtime.enabled') && config('realtime.dialplan_writes_enabled'), 503, 'Dialplan management is disabled.');
    }

    private function db(): Connection
    {
        return DB::connection('dialplan_write');
    }

    public function listing(Tenant $tenant): ?LengthAwarePaginator
    {
        Gate::authorize('view', $tenant);
        if (! config('realtime.enabled')) {
            return null;
        }
        try {
            return DB::connection('asterisk')->table('extensions')->select('exten')->selectRaw('count(*) AS step_count')
                ->where('context', $tenant->context)->groupBy('exten')->orderBy('exten')->paginate(25);
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['operation' => 'Dialplan inventory is unavailable.']);
        }
    }

    private function rows(Tenant $tenant, string $extension): array
    {
        return $this->db()->table('extensions')->where('context', $tenant->context)->where('exten', $extension)
            ->select(['id', 'priority', 'app', 'appdata'])->selectRaw('xmin::text AS row_version')->orderBy('priority')->get()->all();
    }

    public function endpoints(Tenant $tenant): array
    {
        $this->authorize($tenant);
        try {
            return array_values(array_filter($this->db()->table('ps_endpoints')->where('context', $tenant->context)->orderBy('id')->pluck('id')->all(),
                fn (string $id): bool => preg_match('/\A[A-Za-z0-9_.-]{1,255}\z/', $id) === 1));
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['operation' => 'Endpoint choices are unavailable.']);
        }
    }

    public function compile(Tenant $tenant, array $steps): array
    {
        $this->authorize($tenant);
        if ($steps === [] || count($steps) > 20) {
            throw ValidationException::withMessages(['steps' => 'Provide between 1 and 20 steps.']);
        }
        $endpoints = $this->endpoints($tenant);
        $compiled = [];
        foreach (array_values($steps) as $index => $step) {
            $app = $step['app'] ?? '';
            $argument = (string) ($step['argument'] ?? '');
            $data = '';
            $valid = in_array($app, self::APPLICATIONS, true);
            if ($app === 'Dial') {
                $timeout = (string) ($step['timeout'] ?? '30');
                $valid = $valid && in_array($argument, $endpoints, true) && preg_match('/\A[0-9]+\z/', $timeout) && (int) $timeout >= 1 && (int) $timeout <= 120;
                $data = 'PJSIP/'.$argument.','.$timeout.',i';
            } elseif ($app === 'Playback') {
                $valid = $valid && in_array($argument, config('realtime.playback_prompts'), true) && preg_match('/\A[a-zA-Z0-9_-]+\z/', $argument);
                $data = $argument;
            } elseif ($app === 'Wait') {
                $valid = $valid && preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $argument) && (float) $argument >= 0 && (float) $argument <= 30;
                $data = $argument;
            } else {
                $valid = $valid && $argument === '';
            }
            if (! $valid || strlen($data) > 256) {
                throw ValidationException::withMessages(['steps.'.$index.'.argument' => 'Choose a supported application and valid arguments. Dial targets must belong to this tenant.']);
            }
            $compiled[] = ['priority' => $index + 1, 'app' => $app, 'appdata' => $data];
        }
        if (end($compiled)['app'] !== 'Hangup') {
            throw ValidationException::withMessages(['steps' => 'Finish the route with Hangup.']);
        }

        return $compiled;
    }

    private function decode(array $rows): ?array
    {
        $steps = [];
        foreach ($rows as $row) {
            $step = ['app' => $row->app, 'argument' => '', 'timeout' => '30'];
            if (! in_array($row->app, self::APPLICATIONS, true)) {
                return null;
            }
            if ($row->app === 'Dial') {
                if (! preg_match('/\APJSIP\/([A-Za-z0-9_.-]+),([0-9]+)(?:,i)?\z/', $row->appdata, $match)) {
                    return null;
                }
                $step['argument'] = $match[1];
                $step['timeout'] = $match[2];
            } elseif ($row->app === 'Playback') {
                if (! in_array($row->appdata, config('realtime.playback_prompts'), true)) {
                    return null;
                }
                $step['argument'] = $row->appdata;
            } elseif ($row->app === 'Wait') {
                if (! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $row->appdata) || (float) $row->appdata > 30) {
                    return null;
                }
                $step['argument'] = $row->appdata;
            } elseif ($row->appdata !== '') {
                return null;
            }
            $steps[] = $step;
        }

        return $steps;
    }

    private function versions(array $rows): array
    {
        return array_map(fn (object $row): array => ['id' => $row->id, 'version' => $row->row_version], $rows);
    }

    public function token(Tenant $tenant, string $extension, array $rows): string
    {
        return Crypt::encryptString(json_encode(['tenant' => $tenant->id, 'context' => $tenant->context, 'extension' => $extension,
            'rows' => $this->versions($rows), 'expires' => time() + 3600], JSON_THROW_ON_ERROR));
    }

    private function verify(Tenant $tenant, string $extension, array $rows, string $token): void
    {
        try {
            $snapshot = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['version_token' => 'Invalid form. Reload the route.']);
        }
        if (($snapshot['tenant'] ?? null) !== $tenant->id || ($snapshot['context'] ?? null) !== $tenant->context
            || ($snapshot['extension'] ?? null) !== $extension || ($snapshot['rows'] ?? null) !== $this->versions($rows)
            || ($snapshot['expires'] ?? 0) < time()) {
            throw ValidationException::withMessages(['version_token' => 'The route changed or the form expired. Reload and review it.']);
        }
    }

    public function form(Tenant $tenant, string $extension): array
    {
        $this->authorize($tenant);
        try {
            $rows = $this->rows($tenant, $extension);
            abort_if($rows === [], 404);
            $steps = $this->decode($rows);

            // Unknown arguments are deliberately withheld from forms and previews.
            return ['steps' => $steps, 'token' => $this->token($tenant, $extension, $rows),
                'summary' => array_map(fn (object $row): array => ['priority' => $row->priority, 'app' => $row->app], $rows)];
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['operation' => 'Dialplan management is unavailable.']);
        }
    }

    public function preview(Tenant $tenant, string $extension, array $steps, ?string $token, bool $creating): array
    {
        $this->authorize($tenant);
        try {
            $rows = $this->rows($tenant, $extension);
            if ($creating && $rows !== []) {
                throw ValidationException::withMessages(['extension' => 'This extension already exists. Open it from the route list.']);
            }
            if (! $creating) {
                abort_if($rows === [], 404);
                $this->verify($tenant, $extension, $rows, $token ?? '');
                if ($this->decode($rows) === null) {
                    throw ValidationException::withMessages(['operation' => 'This route contains unsupported settings and is read-only in this editor.']);
                }
            }

            return ['compiled' => $this->compile($tenant, $steps), 'token' => $this->token($tenant, $extension, $rows)];
        } catch (QueryException|PDOException) {
            throw ValidationException::withMessages(['operation' => 'Dialplan preview is unavailable.']);
        }
    }

    private function mutate(Tenant $tenant, string $action, string $extension, callable $callback): mixed
    {
        $this->authorize($tenant);

        return $this->mutations->run($tenant, 'dialplan.'.$action, ['resource' => 'extensions', 'extension' => $extension], 'dialplan_write',
            'LOCK TABLE extensions IN SHARE ROW EXCLUSIVE MODE', $callback);
    }

    public function save(Tenant $tenant, string $extension, array $steps, string $token, bool $creating): void
    {
        $this->mutate($tenant, $creating ? 'created' : 'updated', $extension, function () use ($tenant, $extension, $steps, $token, $creating): void {
            $rows = $this->rows($tenant, $extension);
            $this->verify($tenant, $extension, $rows, $token);
            if ($creating && $rows !== []) {
                throw ValidationException::withMessages(['extension' => 'This extension already exists.']);
            }
            if (! $creating) {
                abort_if($rows === [], 404);
                if ($this->decode($rows) === null) {
                    throw ValidationException::withMessages(['operation' => 'Unsupported routes are read-only in this editor.']);
                }
            }
            $compiled = $this->compile($tenant, $steps);
            $this->db()->table('extensions')->where('context', $tenant->context)->where('exten', $extension)->delete();
            $this->db()->table('extensions')->insert(array_map(fn (array $row): array => ['context' => $tenant->context, 'exten' => $extension] + $row, $compiled));
        });
    }

    public function destroy(Tenant $tenant, string $extension, string $token): void
    {
        $this->mutate($tenant, 'deleted', $extension, function () use ($tenant, $extension, $token): void {
            $rows = $this->rows($tenant, $extension);
            abort_if($rows === [], 404);
            $this->verify($tenant, $extension, $rows, $token);
            if ($this->decode($rows) === null) {
                throw ValidationException::withMessages(['operation' => 'Unsupported routes are read-only in this editor.']);
            }
            $this->db()->table('extensions')->where('context', $tenant->context)->where('exten', $extension)->delete();
        });
    }

    public function provision(Tenant $tenant): array
    {
        return $this->mutate($tenant, 'provisioned', '*', function () use ($tenant): array {
            $routes = [
                '200' => [['app' => 'Answer'], ['app' => 'Echo'], ['app' => 'Hangup']],
                '201' => [['app' => 'Answer'], ['app' => 'Wait', 'argument' => '1'], ['app' => 'Playback', 'argument' => 'hello-world'], ['app' => 'Hangup']],
            ];
            foreach ($this->endpoints($tenant) as $endpoint) {
                if (preg_match('/\A([0-9]+)_'.preg_quote($tenant->context, '/').'\z/', $endpoint, $match) && ! in_array($match[1], ['200', '201'], true)) {
                    $routes[$match[1]] = [['app' => 'Dial', 'argument' => $endpoint, 'timeout' => '30'], ['app' => 'Hangup']];
                }
            }
            $created = $skipped = 0;
            foreach ($routes as $extension => $steps) {
                $extension = (string) $extension;
                if ($this->rows($tenant, $extension) !== []) {
                    $skipped++;

                    continue;
                }
                $compiled = $this->compile($tenant, $steps);
                $this->db()->table('extensions')->insert(array_map(fn (array $row): array => ['context' => $tenant->context, 'exten' => $extension] + $row, $compiled));
                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }
}
