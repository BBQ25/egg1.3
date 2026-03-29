<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class MachineBlueprintController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user && ($user->isAdmin() || $user->isOwner() || $user->isStaff()), 403);

        return view('machine-blueprint');
    }
}
