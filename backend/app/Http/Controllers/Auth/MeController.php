<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BuildSession;
use App\Http\Resources\SessionResource;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request, BuildSession $action): SessionResource
    {
        return new SessionResource($action->execute($request->user()));
    }
}
