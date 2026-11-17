<?php

namespace App\Providers;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::connection('sqlite')->select('SELECT 1 FROM migrations LIMIT 1');
        });

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getName() === 'asterisk_write') {
                if (! config('realtime.enabled') || ! config('realtime.writes_enabled')) {
                    throw new LogicException('PBX writes are disabled.');
                }
                $event->connection->disableQueryLog();
            }

            if ($event->connection->getName() === 'asterisk') {
                if (! config('realtime.enabled')) {
                    throw new LogicException('Realtime access is disabled.');
                }

                $event->connection->getPdo()->exec('SET SESSION default_transaction_read_only = on');
                $event->connection->getPdo()->exec('SET SESSION statement_timeout = 3000');
            }
        });
    }
}
