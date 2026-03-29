<?php

namespace App\Http\Middleware;

use App\Models\DocEaseUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDocEaseAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var DocEaseUser|null $user */
        $user = Auth::guard('doc_ease')->user();
        if (!$user || !$user->isAdminRole()) {
            abort(403);
        }

        return $next($request);
    }
}

