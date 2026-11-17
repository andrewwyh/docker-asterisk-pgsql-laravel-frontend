<?php

namespace Tests\Feature;

use App\Services\RealtimeStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealtimeConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_disposable_postgres_connection_is_read_only_and_has_no_laravel_tables(): void
    {
        if (getenv('REALTIME_INTEGRATION') !== 'true') {
            $this->markTestSkipped('Run using compose.test.yml for the isolated PostgreSQL fixture.');
        }

        config([
            'realtime.enabled' => true,
            'database.connections.asterisk.host' => 'pbx-fixture',
            'database.connections.asterisk.database' => 'realtime_fixture',
            'database.connections.asterisk.username' => 'fixture_reader',
            'database.connections.asterisk.password' => '',
        ]);
        $this->assertSame('connected (read only)', app(RealtimeStatus::class)->check());
        $connection = DB::connection('asterisk');
        $this->assertSame('on', $connection->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only);
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('ps_endpoints'));
        $this->assertFalse($connection->getSchemaBuilder()->hasTable('users'));
        $this->assertFalse($connection->getSchemaBuilder()->hasTable('migrations'));
        $privileges = $connection->selectOne("SELECT has_column_privilege(current_user, 'public.ps_auths', 'password', 'SELECT') AS credentials, has_table_privilege(current_user, 'public.ps_endpoints', 'UPDATE') AS writes");
        $this->assertFalse($privileges->credentials);
        $this->assertFalse($privileges->writes);

        try {
            $connection->insert("INSERT INTO extensions (context, exten, priority, app, appdata) VALUES ('fixture', '100', 1, 'Hangup', '')");
            $this->fail('The PostgreSQL session must reject writes.');
        } catch (QueryException $exception) {
            $this->assertSame('25006', $exception->getCode());
        } finally {
            DB::purge('asterisk');
        }
    }
}
