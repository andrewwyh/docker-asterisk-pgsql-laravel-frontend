<?php

namespace App\Http\Controllers;

use App\Services\RealtimeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(RealtimeStatus $realtime): View
    {
        DB::connection('sqlite')->select('SELECT 1');

        return view('dashboard', ['realtimeStatus' => $realtime->check()]);
    }
}
