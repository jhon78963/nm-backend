<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $increment = 0;
        ++$increment;

        return [
            'name' => sprintf('Tenant %s', fake()->company()),
            'domain' => sprintf('tenant%d.%s.local', $increment, uniqid('', true)),
            'is_active' => true,
        ];
    }
}
