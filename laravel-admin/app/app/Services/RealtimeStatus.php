<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class RealtimeStatus
{
    public function check(): string
    {
        if (! config('realtime.enabled')) {
            return 'disabled';
        }

        try {
            DB::connection('asterisk')->select('SELECT 1');

            return 'connected (read only)';
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
