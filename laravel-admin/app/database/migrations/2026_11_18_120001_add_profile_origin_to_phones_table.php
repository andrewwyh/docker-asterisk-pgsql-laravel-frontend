<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlite';

    public function up(): void
    {
        Schema::connection('sqlite')->table('phones', function (Blueprint $table): void {
            $table->text('profile_origin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('sqlite')->table('phones', function (Blueprint $table): void {
            $table->dropColumn('profile_origin');
        });
    }
};
