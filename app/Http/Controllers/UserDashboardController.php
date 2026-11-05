<?php

namespace App\Http\Controllers;

use App\Support\Dashboard\UserDashboardData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserDashboardController extends Controller
{
    public function __invoke(Request $request, UserDashboardData $data): Response
    {
        return Inertia::render('users/dashboard/user-dashboard', $data->props($request->user()));
    }
}
