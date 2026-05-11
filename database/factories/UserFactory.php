<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Administration\Domain\Models\Store;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'tenant_id' => function (): int {
                return Tenant::factory()->create()->id;
            },
            'store_id' => function (array $attributes): int {
                /** @var int $tenantId */
                $tenantId = $attributes['tenant_id'];

                return Store::factory()->create([
                    'tenant_id' => $tenantId,
                ])->id;
            },
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
