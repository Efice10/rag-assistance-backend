<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'organization_id' => fn (array $attributes) => Department::findOrFail($attributes['department_id'])->organization_id,
            'uploaded_by' => fn (array $attributes) => User::factory()
                ->in(Department::findOrFail($attributes['department_id']))
                ->manager(4),
            'title' => Str::title(fake()->words(3, true)).' Policy',
            'file_path' => fn (array $attributes) => 'documents/'.$attributes['organization_id'].'/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'clearance_level' => 1,
            'status' => Document::STATUS_INDEXED,
        ];
    }

    public function in(Department $department): static
    {
        return $this->state(fn () => [
            'department_id' => $department->id,
            'organization_id' => $department->organization_id,
        ]);
    }

    public function clearance(int $level): static
    {
        return $this->state(fn () => ['clearance_level' => $level]);
    }
}
