<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Administration\Domain\Models\Store;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->company(),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }
}
