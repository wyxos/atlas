<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrowseController extends Controller
{
    /**
     * Display the browse page with VIBE masonry example.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Browse');
    }
}
