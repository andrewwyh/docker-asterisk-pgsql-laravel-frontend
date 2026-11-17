<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlite';

    public function up(): void
    {
        $schema = Schema::connection('sqlite');
        $schema->table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
        });
        DB::connection('sqlite')->table('users')->update(['is_platform_admin' => true]);
        $schema->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('context', 32)->unique();
            $table->timestamps();
        });
        $schema->create('tenant_user', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['viewer', 'admin'])->default('viewer');
            $table->timestamps();
            $table->primary(['tenant_id', 'user_id']);
        });
        $schema->create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->json('details');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('sqlite');
        $schema->dropIfExists('audit_events');
        $schema->dropIfExists('tenant_user');
        $schema->dropIfExists('tenants');
        $schema->table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
