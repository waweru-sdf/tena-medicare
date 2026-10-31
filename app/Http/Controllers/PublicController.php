<?php

namespace App\Http\Controllers;

class PublicController extends Controller
{
    public function health()
    {
        return response()->json(['ok' => true]);
    }
}
