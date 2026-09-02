<?php

namespace App\Http\Controllers\Routine;

use App\Http\Resources\Routine\RoutineResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListRoutinesController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $routines = $user->routines()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();

        return RoutineResource::collection($routines);
    }
}
