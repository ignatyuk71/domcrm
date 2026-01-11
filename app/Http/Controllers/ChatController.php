<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatController extends Controller
{
    public function index()
    {
        $textColumn = Schema::hasColumn('facebook_messages', 'message_text') ? 'message_text' : 'text';
        $latestMessages = DB::table('facebook_messages as m1')
            ->select('m1.*')
            ->whereRaw(
                'm1.id = (SELECT MAX(m2.id) FROM facebook_messages as m2 WHERE m2.customer_id = m1.customer_id)'
            )
            ->orderByDesc('m1.created_at')
            ->get();

        $chats = $latestMessages->map(function ($message) {
            $customer = DB::table('customers')->where('id', $message->customer_id)->first();
            $firstName = $customer->first_name ?? '';
            $lastName = $customer->last_name ?? '';
            $name = trim($firstName.' '.$lastName);

            return [
                'customer_id' => $message->customer_id,
                'name' => $name !== '' ? $name : 'Невідомий клієнт',
                'text' => $message->{$textColumn} ?? null,
                'platform' => $message->platform,
                'time' => $message->created_at,
            ];
        });

        return view('chat.index', compact('chats'));
    }

    public function getMessages($id)
    {
        $textColumn = Schema::hasColumn('facebook_messages', 'message_text') ? 'message_text' : 'text';
        $messages = DB::table('facebook_messages')
            ->where('customer_id', $id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) use ($textColumn) {
                $message->text = $message->{$textColumn} ?? null;
                return $message;
            });

        return response()->json($messages);
    }
}
