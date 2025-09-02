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

Route::get('/test-notification', function () {
    $notification = App\Models\Notification::first();
    event(new App\Events\NotificationCreated($notification));
    return ['status' => 'notification dispatched'];
});