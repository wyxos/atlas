<?php

namespace App\Http\Controllers\Playground;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SpotifyController extends Controller
{
    public function __invoke(Request $request)
    {
        abort(404);
    }
}
