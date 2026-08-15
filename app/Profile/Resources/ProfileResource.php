<?php

namespace App\Profile\Resources;

use App\Administration\User\Models\User;
use App\Inventory\WooCommerce\Support\ProductMediaUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $resolver = app(ProductMediaUrlResolver::class);
        $avatarUrl = $resolver->absoluteUrl((string) ($user->profile_picture ?? ''));

        return [
            'id' => $user->id,
            'name' => $user->name,
            'surname' => $user->surname,
            'email' => $user->email,
            'phone' => $user->phone,
            'profilePicture' => $user->profile_picture,
            'avatarUrl' => $avatarUrl,
            'role' => $user->getRoleNames()->first(),
            'roles' => $user->getRoleNames()->values()->all(),
            'warehouse' => $user->warehouse?->name ?? '',
            'warehouseName' => $user->warehouse?->name ?? '',
            'createdAt' => $this->formatDateTime($user->creation_time),
            'creation_time' => $this->formatDateTime($user->creation_time),
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        $stringValue = trim((string) $value);

        return $stringValue !== '' ? $stringValue : null;
    }
}
