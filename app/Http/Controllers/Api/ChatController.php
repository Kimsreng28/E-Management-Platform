<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Events\MessageRead;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Events\CallEnded;
use App\Events\CallRejected;
use App\Events\CallAccepted;
use App\Events\CallInitiated;
use App\Models\CallHistory;


class ChatController extends Controller
{
    use AuthorizesRequests;

    public function sendMessage(Request $request, Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $request->validate([
                'body' => 'required_without:attachment|string|nullable',
                'type' => 'sometimes|in:text,image,video,voice',
                'attachment' => 'sometimes|file|mimes:jpg,jpeg,png,mp4,mov,avi,mp3,wav,m4a,ogg,webm,webp|max:10240', // 10MB max
                'duration' => 'required_if:type,voice|numeric|nullable' // Duration in seconds for voice chat
            ]);

            $attachmentPath = null;
            $messageType = $request->type ?? 'text';
            $duration = $request->duration;

            // Handle image/video upload
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $attachmentPath = $file->store('chat_attachments', 'public');

                $mimeType = $file->getMimeType();

                if (str_starts_with($file->getMimeType(), 'image/')) {
                    $messageType = 'image';
                } elseif (str_starts_with($file->getMimeType(), 'video/')) {
                    $messageType = 'video';
                } elseif (str_starts_with($mimeType, 'audio/') || in_array($file->getClientOriginalExtension(), ['mp3', 'wav', 'm4a', 'ogg', 'webm'])) {
                    $messageType = 'voice';
                    // Ensure duration is set for voice messages
                    if (!$duration || $duration <= 0) {
                        return response()->json([
                            'error' => 'Duration is required for voice messages'
                        ], 422);
                    }
                }
            }

            if ($request->type === 'voice' && !$request->hasFile('attachment')) {
                return response()->json([
                    'error' => 'Attachment is required for voice messages'
                ], 422);
            }

            $messageBody = $request->body ?? '';

            $messageData = [
                'user_id' => Auth::id(),
                'body' => $messageBody,
                'type' => $messageType,
                'attachment' => $attachmentPath,
                'duration' => $duration
            ];

            $message = $conversation->messages()->create($messageData);
            $message->load('user');

            broadcast(new MessageSent($message, $conversation));

            return response()->json([
                'message' => $message,
                'status' => 'Message sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to send message',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Add call initiation method
    public function initiateCall(Request $request, Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $request->validate([
                'type' => 'required|in:audio,video',
                'call_id' => 'required|string' // Unique call identifier
            ]);

            // Get the receiver (other participant)
            $participants = $conversation->participants()->where('user_id', '!=', Auth::id())->get();

            if ($participants->isEmpty()) {
                return response()->json(['error' => 'No receiver found'], 404);
            }

            $receiver = $participants->first();

            $callData = [
                'call_id' => $request->call_id,
                'conversation_id' => $conversation->id,
                'caller_id' => Auth::id(),
                'receiver_id' => $receiver->id,
                'type' => $request->type,
                'status' => 'initiated',
                'started_at' => now(),
            ];

            // Store call in database
            $callHistory = CallHistory::create($callData);

            // Broadcast call event to other participants
            broadcast(new CallInitiated($callHistory, $conversation));

            return response()->json([
                'call' => $callHistory,
                'status' => 'Call initiated'
            ]);

        } catch (\Exception $e) {
            Log::error('Error initiating call: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to initiate call',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Add call acceptance method
    public function acceptCall(Request $request, Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $request->validate([
                'call_id' => 'required|string'
            ]);

            // Find the call
            $call = CallHistory::where('call_id', $request->call_id)
                ->where('conversation_id', $conversation->id)
                ->firstOrFail();

            // Update call status and set start time
            $call->update([
                'status' => 'accepted',
                'started_at' => now(),
                // Don't set duration here - duration is set when call ends
            ]);

            // Broadcast call accepted event
            broadcast(new CallAccepted($call, $conversation));

            return response()->json([
                'status' => 'Call accepted',
                'call' => $call
            ]);

        } catch (\Exception $e) {
            Log::error('Error accepting call: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to accept call',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Add call rejection method
    public function rejectCall(Request $request, Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $request->validate([
                'call_id' => 'required|string',
                'reason' => 'sometimes|string'
            ]);

            // Find the call
            $call = CallHistory::where('call_id', $request->call_id)
                ->where('conversation_id', $conversation->id)
                ->firstOrFail();

            // Update call status
            $call->update([
                'status' => 'rejected',
                'reason' => $request->reason,
                'ended_at' => now(),
                'duration' => 0, // Set duration to 0 for rejected calls
            ]);

            // Create a message for the rejected call
            $messageData = [
                'user_id'    => Auth::id(),
                'body'       => '',
                'type'       => 'call',
                'duration'   => 0,
                'call_type'  => $call->type,
                'call_status'=> 'rejected',
                'call_id'    => $call->id,
                'call_reason'=> $request->reason,
            ];

            $message = $conversation->messages()->create($messageData);
            $message->load('user');

            // Broadcast call rejected event with the message
            broadcast(new CallRejected($call, $conversation, $message));

            return response()->json([
                'status' => 'Call rejected',
                'call' => $call,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            Log::error('Error rejecting call: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to reject call',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function endCall(Request $request, Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $request->validate([
                'call_id' => 'required|string',
                'duration' => 'required|numeric',
            ]);

            // Find the call
            $call = CallHistory::where('call_id', $request->call_id)
                ->where('conversation_id', $conversation->id)
                ->firstOrFail();

            // Determine who ended the call
            $endedBy = Auth::id();

            // Update call status
            $call->update([
                'status' => 'ended',
                'duration' => $request->duration,
                'ended_at' => now(),
                'ended_by' => $endedBy,
            ]);

            $messageData = [
                'user_id'    => $endedBy,
                'body'       => '',
                'type'       => 'call',
                'duration'   => $call->duration,
                'call_type'  => $call->type,
                'call_status'=> $call->status,
                'call_id'    => $call->id,
            ];

            $message = $conversation->messages()->create($messageData);
            $message = $message->fresh();
            $message->load('user');

            // Broadcast call ended event with the message
            broadcast(new \App\Events\CallEnded($call, $conversation, $message));

            return response()->json([
                'status'  => 'Call ended',
                'call'    => $call,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            Log::error('Error ending call: ' . $e->getMessage());
            return response()->json([
                'error'   => 'Failed to end call',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function markAsRead(Conversation $conversation)
    {
        try {
            $this->authorize('view', $conversation);

            $conversation->markAsRead(Auth::id());

            // Broadcast that messages were read
            broadcast(new MessageRead($conversation->id, Auth::id()));

            return response()->json([
                'status' => 'Messages marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking as read: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to mark messages as read',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMessage(Request $request, Conversation $conversation, Message $message)
    {
        $this->authorize('view', $conversation);

        if ($message->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'body' => 'required|string',
        ]);

        $message->body = $request->body;
        $message->save();

        broadcast(new \App\Events\MessageUpdated($message));

        return response()->json(['message' => $message]);
    }

    public function deleteMessage(Conversation $conversation, Message $message)
    {
        $this->authorize('view', $conversation);

        if ($message->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $messageId = $message->id;
        $conversationId = $message->conversation_id;

        $message->delete();

        broadcast(new \App\Events\MessageDeleted($messageId, $conversationId));

        return response()->json(['status' => 'Message deleted']);
    }
}