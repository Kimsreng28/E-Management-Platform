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

Broadcast::channel('user.presence.{userId}', function ($user, $userId) {
    // Allow user to listen to their own presence channel
    return (int) $user->id === (int) $userId;
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
            'is_online' => $user->isOnline(), // Add the user's online status
            'last_seen' => $user->last_seen_at?->toISOString(), // Add the last seen time
        ];
    }

    return false;
});

// Delivery channel - allow customer, agent
Broadcast::channel('delivery.{deliveryId}', function ($user, $deliveryId) {
    Log::info('Delivery channel access attempt', [
        'user_id' => $user?->id,
        'delivery_id' => $deliveryId,
    ]);

    if (!$user) {
        Log::warning('Delivery channel rejected: no user', [
            'delivery_id' => $deliveryId
        ]);
        return false;
    }

    $delivery = \App\Models\Delivery::find($deliveryId);
    if (!$delivery) {
        Log::warning('Delivery channel rejected: delivery not found', [
            'delivery_id' => $deliveryId
        ]);
        return false;
    }

    $isCustomer = (int)$user->id === (int)($delivery->order->user_id ?? 0);
    $isAgent = (int)$user->id === (int)($delivery->delivery_agent_id ?? 0);

    Log::info('Delivery channel auth check', [
        'user_id' => $user->id,
        'delivery_id' => $delivery->id,
        'isCustomer' => $isCustomer,
        'isAgent' => $isAgent,
        'authorized' => $isCustomer || $isAgent
    ]);

    return $isCustomer || $isAgent
        ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar]
        : false;
});

Broadcast::channel('user.{userId}', function ($user, $userId) {
    Log::info('User channel access attempt', [
        'user_id' => $user?->id,
        'requested_user_id' => $userId,
        'authorized' => $user && (int)$user->id === (int)$userId
    ]);
    
    return $user && (int)$user->id === (int)$userId ? [
        'id' => $user->id,
        'name' => $user->name
    ] : false;
});