<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::limit(Str::slug($name), 40, '').'-'.fake()->unique()->numberBetween(100, 999);

        return [
            'name' => $name,
            'slug' => $slug,
            'type' => OrganizationType::Studio,
        ];
    }
}
