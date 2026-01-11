<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index()
    {
        $customers = DB::table('customers')
            ->joinSub(
                DB::table('facebook_messages')
                    ->select('customer_id', DB::raw('MAX(created_at) as last_msg'))
                    ->groupBy('customer_id'),
                'last_messages',
                'customers.id',
                '=',
                'last_messages.customer_id'
            )
            ->select('customers.*', 'last_messages.last_msg')
            ->orderByDesc('last_messages.last_msg')
            ->get();

        return view('chat.index', compact('customers'));
    }

    public function getMessages($id)
    {
        $messages = DB::table('facebook_messages')
            ->where('customer_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }
}
