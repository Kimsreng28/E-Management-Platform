<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::routes(['middleware' => ['auth:sanctum,api']]);

// Log channel access attempts for debugging
Broadcast::channel('user.notifications.{notifiable_id}', function ($user, $notifiable_id) {

    Log::info('Channel access attempt', [
        'user_id' => $user->id,
        'requested_channel' => $notifiable_id,
        'is_authorized' => (int) $user->id === (int) $notifiable_id
    ]);

    return $user && (int) $user->id === (int) $notifiable_id;
});

// Conversation channel
Broadcast::channel('chat.{conversationId}', function ($user, $conversationId) {
    $conversation = \App\Models\Conversation::find($conversationId);

    if (!$conversation) return false;

    $isParticipant = $conversation->participants
        ->contains(fn($p) => (int)$p->id === (int)$user->id);

    if ($isParticipant) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
        ];
    }

    return false;
});