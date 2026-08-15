<?php

namespace App\Profile\Controllers;

use App\Profile\Requests\UpdateProfilePasswordRequest;
use App\Profile\Requests\UpdateProfileRequest;
use App\Profile\Requests\UploadProfileAvatarRequest;
use App\Profile\Resources\ProfileResource;
use App\Profile\Services\ProfileService;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profileService,
    ) {
    }

    public function show(): JsonResponse
    {
        $user = $this->profileService->getProfile(request()->user());

        return response()->json(new ProfileResource($user));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profileService->updateProfile($request->user(), $request);

        return response()->json(new ProfileResource($user));
    }

    public function updatePassword(UpdateProfilePasswordRequest $request): JsonResponse
    {
        $this->profileService->updatePassword(
            $request->user(),
            (string) $request->validated('password'),
        );

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    public function uploadAvatar(UploadProfileAvatarRequest $request): JsonResponse
    {
        $user = $this->profileService->uploadAvatar(
            $request->user(),
            $request->file('avatar'),
        );

        return response()->json([
            'avatarUrl' => $this->profileService->avatarUrl($user),
            'profilePicture' => $user->profile_picture,
        ]);
    }
}
