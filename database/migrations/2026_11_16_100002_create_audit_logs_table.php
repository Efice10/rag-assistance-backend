<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 50);
            $table->text('prompt')->nullable();
            $table->jsonb('retrieved_document_ids')->default('[]');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
        });

        // Immutability is enforced in the database, so it holds even for
        // queries that bypass the Eloquent model.
        match (DB::getDriverName()) {
            'pgsql' => $this->createPostgresGuards(),
            'sqlite' => $this->createSqliteGuards(),
            default => null,
        };
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs');
            DB::statement('DROP FUNCTION IF EXISTS audit_logs_block_mutation()');
        }

        Schema::dropIfExists('audit_logs');
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is immutable';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_immutable
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
        SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'audit_logs is immutable');
            END;

            CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'audit_logs is immutable');
            END;
        SQL);
    }
};
