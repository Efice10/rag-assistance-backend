<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Must match the embedding model used by ai-service (nomic-embed-text = 768).
     */
    private const EMBEDDING_DIMENSIONS = 768;

    public function up(): void
    {
        $isPostgres = DB::getDriverName() === 'pgsql';

        if ($isPostgres) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('document_chunks', function (Blueprint $table) use ($isPostgres) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedTinyInteger('clearance_level');
            $table->string('document_title');
            $table->unsignedInteger('page_number');
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();

            // The RBAC filter applied to every vector search.
            $table->index(['organization_id', 'department_id', 'clearance_level'], 'document_chunks_rbac_index');

            if (! $isPostgres) {
                // Non-Postgres drivers (the SQLite test database) have no vector
                // type; the column exists only so the schema has the same shape.
                $table->text('embedding')->nullable();
            }
        });

        if ($isPostgres) {
            DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector('.self::EMBEDDING_DIMENSIONS.') NOT NULL');
            DB::statement('CREATE INDEX document_chunks_embedding_hnsw ON document_chunks USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
