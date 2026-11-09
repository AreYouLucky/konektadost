<?php

namespace App\Http\Controllers;

use App\Support\Map\MapPageProps;
use Inertia\Inertia;
use Inertia\Response;

/** Public landing page: the header and the intelligence map. */
class WelcomeController extends Controller
{
    public function __invoke(MapPageProps $map): Response
    {
        return Inertia::render('welcome', ['map' => $map->toArray()]);
    }
}
