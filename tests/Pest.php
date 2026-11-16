<?php

use App\Models\Department;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Two tenants, each with departments, for isolation tests.
 *
 * @return array{acme: Organization, globex: Organization, procurement: Department, hr: Department, operations: Department}
 */
function tenants(): array
{
    $acme = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $globex = Organization::factory()->create(['name' => 'Globex', 'slug' => 'globex']);

    return [
        'acme' => $acme,
        'globex' => $globex,
        'procurement' => Department::factory()->create(['organization_id' => $acme->id, 'name' => 'Procurement']),
        'hr' => Department::factory()->create(['organization_id' => $acme->id, 'name' => 'Human Resources']),
        'operations' => Department::factory()->create(['organization_id' => $globex->id, 'name' => 'Operations']),
    ];
}

/**
 * A fake Server-Sent Events body, shaped like the AI service's /query stream.
 *
 * @param  list<int>  $documentIds
 */
function fakeAiStream(array $documentIds = [], string $answer = 'Grounded answer.'): string
{
    $sources = array_map(fn (int $id) => [
        'document_id' => $id,
        'document_title' => "Document {$id}",
        'page_number' => 1,
    ], $documentIds);

    return 'event: token'."\n".'data: '.json_encode(['text' => $answer])."\n\n"
        .'event: sources'."\n".'data: '.json_encode(['sources' => $sources])."\n\n"
        .'event: done'."\n".'data: {}'."\n\n";
}
