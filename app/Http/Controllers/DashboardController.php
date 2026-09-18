<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $organizations = Organization::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get();

        return view('dashboard', [
            'organizations' => $organizations,
            'isPlatformAdmin' => $user->isPlatformAdmin(),
        ]);
    }
}
