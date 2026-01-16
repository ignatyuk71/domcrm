<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;

class ChatApiController extends Controller
{
    public function list()
    {
        $latestMessages = DB::table('facebook_messages as messages')
            ->join(
                DB::raw('(SELECT customer_id, MAX(id) AS last_id FROM facebook_messages GROUP BY customer_id) AS latest'),
                'messages.id',
                '=',
                'latest.last_id'
            )
            ->join('customers as customers', 'customers.id', '=', 'messages.customer_id')
            ->orderByDesc('messages.created_at')
            ->get([
                'messages.customer_id',
                'messages.text as last_message',
                'messages.created_at as last_message_time',
                'customers.first_name',
                'customers.last_name',
            ]);

        $data = $latestMessages->map(function ($message) {
            $name = trim(($message->first_name ?? '').' '.($message->last_name ?? ''));

            return [
                'customer_id' => (int) $message->customer_id,
                'customer_name' => $name !== '' ? $name : 'Невідомий клієнт',
                'last_message' => $message->last_message,
                'last_message_time' => $message->last_message_time,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function messages($id)
    {
        $messages = ChatMessage::query()
            ->where('customer_id', $id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function (ChatMessage $message) {
                return [
                    'id' => $message->id,
                    'text' => $message->text ?? null,
                    'direction' => $message->is_from_customer ? 'inbound' : 'outbound',
                    'created_at' => $message->created_at?->toDateTimeString(),
                ];
            });

        return response()->json(['data' => $messages]);
    }
}
