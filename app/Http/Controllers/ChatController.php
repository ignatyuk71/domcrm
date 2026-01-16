<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class ChatController extends Controller
{
    public function index(): View
    {
        return view('chat.index');
    }
}
