<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Delivery;
use App\Models\DeliveryTracking;
use Illuminate\Support\Facades\DB;
use App\Models\DeliveryPreferences;
use App\Events\DeliveryStatusUpdated;
use App\Events\DeliveryAgentLocationUpdated;
use Illuminate\Support\Facades\Auth;
use App\Models\Conversation;
use App\Events\DeliveryAssigned;
use Illuminate\Support\Facades\Log;
use App\Events\ActiveDeliveriesUpdated;

class DeliveryController extends Controller
{
    // Get available delivery agents
    public function getAvailableAgents()
    {
        $agents = User::where('role_id', 3) // Delivery role
            ->where('is_active', true)
            ->withCount(['deliveries' => function($query) {
                $query->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery']);
            }])
            ->orderBy('deliveries_count')
            ->get();

        return response()->json([
            'success' => true,
            'agents' => $agents
        ]);
    }

    // Assign delivery to an agent
    public function assignDelivery(Order $order, Request $request)
    {
        $request->validate([
            'agent_id' => 'nullable|exists:users,id',
            'auto_assign' => 'nullable|boolean',
            'delivery_notes' => 'nullable|string',
            'estimated_arrival_time' => 'nullable|date',
            'delivery_options' => 'nullable|array'
        ]);

        // Check if order already has a delivery
        if ($order->delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Order already has a delivery assigned'
            ], 400);
        }

        $agentId = $request->agent_id;
        if ($request->auto_assign) {
            $agent = User::where('role_id', 3)
                ->where('is_active', true)
                ->withCount(['deliveries' => function($q) {
                    $q->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery']);
                }])
                ->orderBy('deliveries_count')
                ->first();

            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'No available delivery agents'
                ], 400);
            }

            $agentId = $agent->id;
        }

        DB::beginTransaction();

        try {
            $delivery = Delivery::create([
                'order_id' => $order->id,
                'delivery_agent_id' => $agentId,
                'status' => 'assigned',
                'assigned_at' => now(),
                'delivery_notes' => $request->delivery_notes,
                'tracking_number' => 'DL' . time() . $order->id,
                'estimated_arrival_time' => $request->estimated_arrival_time,
                'delivery_options' => $request->delivery_options
            ]);

            // Create tracking history entry
            DeliveryTracking::create([
                'delivery_id' => $delivery->id,
                'status' => 'assigned',
                'notes' => 'Delivery assigned to agent'
            ]);

            // Update order status
            $order->update(['status' => 'processing']);

            // Create conversation between customer and delivery agent
            $this->startDeliveryConversation($delivery);

            DB::commit();

            // Notify customer and agent
            broadcast(new DeliveryAssigned($delivery));
            broadcast(new DeliveryStatusUpdated($delivery, 'assigned'));

            return response()->json([
                'success' => true,
                'message' => 'Delivery assigned successfully',
                'delivery' => $delivery
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign delivery: ' . $e->getMessage()
            ], 500);
        }
    }

    // Update delivery status
    public function updateStatus(Delivery $delivery, Request $request)
    {
        $request->validate([
            'status' => 'required|in:picked_up,in_transit,out_for_delivery,delivered,cancelled',
            'notes' => 'nullable|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric'
        ]);

        DB::beginTransaction();

        try {
            $previousStatus = $delivery->status;
            $delivery->status = $request->status;

            // Set timestamps based on status
            switch ($request->status) {
                case 'picked_up':
                    $delivery->picked_up_at = now();
                    break;
                case 'out_for_delivery':
                    $delivery->out_for_delivery_at = now();
                    break;
                case 'delivered':
                    $delivery->delivered_at = now();
                    // Update order status to completed
                    $delivery->order->update(['status' => 'completed']);
                    break;
            }

            // Update agent location if provided
            if ($request->lat && $request->lng) {
                $delivery->agent_lat = $request->lat;
                $delivery->agent_lng = $request->lng;

                // Broadcast location update
                broadcast(new DeliveryAgentLocationUpdated($delivery, $request->lat, $request->lng));
            }

            $delivery->save();

            // Create tracking history entry
            DeliveryTracking::create([
                'delivery_id' => $delivery->id,
                'status' => $request->status,
                'notes' => $request->notes ?? 'Status updated to ' . $request->status,
                'lat' => $request->lat,
                'lng' => $request->lng
            ]);

            DB::commit();

            // Broadcast status update
            broadcast(new DeliveryStatusUpdated($delivery, $request->status));

            return response()->json([
                'success' => true,
                'message' => 'Delivery status updated successfully',
                'delivery' => $delivery
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery status: ' . $e->getMessage()
            ], 500);
        }
    }

    // Customer accepts delivery options
    public function acceptDelivery(Request $request, $deliveryId)
    {
        try {
            $delivery = Delivery::findOrFail($deliveryId);

            // Update delivery acceptance info
            $delivery->update([
                'estimated_arrival_time' => $request->delivery_time,
                'delivery_options' => [
                    'instructions' => $request->delivery_instructions,
                    'leave_at_door' => $request->leave_at_door,
                    'signature_required' => $request->signature_required,
                ],
                'customer_accepted_at' => now(),
            ]);

            // Log acceptance in tracking history
            DeliveryTracking::create([
                'delivery_id' => $delivery->id,
                'status' => $delivery->status, // keep current valid status (e.g. "assigned")
                'notes' => 'Customer accepted delivery options',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Delivery accepted successfully',
                'delivery' => $delivery,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept delivery: ' . $e->getMessage(),
            ], 500);
        }
    }


    // Get delivery tracking history
    public function getTracking(Delivery $delivery)
    {
        $tracking = $delivery->trackingHistory()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'tracking' => $tracking,
            'current_status' => $delivery->status,
            'agent' => $delivery->agent
        ]);
    }

    // User confirms delivery receipt
    public function confirmReceipt(Delivery $delivery)
    {
        if ($delivery->status !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'Delivery is not yet marked as delivered'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Update delivery to mark as received by customer
            $delivery->update([
                'received_at' => now(),
                'status' => 'delivered'
            ]);

            // Create tracking entry
            DeliveryTracking::create([
                'delivery_id' => $delivery->id,
                'status' => 'delivered',
                'notes' => 'Customer confirmed receipt of delivery'
            ]);

            $payment = $delivery->order->payments()->orderByDesc('id')->first();

            if ($payment) {
                $payment->update([
                    'status' => 'completed',
                    'paid_at' => now()
                ]);

                $payment->refresh();
            } else {
                // Log if no payment found
                Log::warning("No payment found for order_id: {$delivery->order->id}");
            }

            // Broadcast delivery completion
            broadcast(new DeliveryStatusUpdated($delivery, 'completed'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery receipt confirmed and payment updated',
                'payment' => $payment
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm delivery receipt: ' . $e->getMessage()
            ], 500);
        }
    }


    // Get user's delivery preferences
    public function getPreferences(Request $request)
    {
        $preferences = DeliveryPreferences::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['delivery_type' => 'standard']
        );

        return response()->json([
            'success' => true,
            'preferences' => $preferences
        ]);
    }

    // Update user's delivery preferences
    public function updatePreferences(Request $request)
    {
        $request->validate([
            'delivery_type' => 'required|in:standard,express,scheduled',
            'preferred_delivery_time' => 'nullable|date_format:H:i',
            'delivery_instructions' => 'nullable|string',
            'leave_at_door' => 'boolean',
            'signature_required' => 'boolean'
        ]);

        $preferences = DeliveryPreferences::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->only([
                'delivery_type',
                'preferred_delivery_time',
                'delivery_instructions',
                'leave_at_door',
                'signature_required'
            ])
        );

        return response()->json([
            'success' => true,
            'message' => 'Delivery preferences updated',
            'preferences' => $preferences
        ]);
    }

    // Get delivery agent location
    public function getAgentLocation(Delivery $delivery)
    {
        return response()->json([
            'success' => true,
            'lat' => $delivery->agent_lat,
            'lng' => $delivery->agent_lng,
            'last_updated' => $delivery->updated_at
        ]);
    }

    // Update delivery agent location
    public function updateAgentLocation(Delivery $delivery, Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric'
        ]);

        $delivery->update([
            'agent_lat' => $request->lat,
            'agent_lng' => $request->lng
        ]);

        // Broadcast location update
        broadcast(new DeliveryAgentLocationUpdated($delivery, $request->lat, $request->lng));

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully'
        ]);
    }

    // Start delivery conversation
    public function startDeliveryConversation(Delivery $delivery)
    {
        // Find or create conversation between customer and delivery agent
        $participants = [$delivery->order->user_id, $delivery->delivery_agent_id];
        sort($participants);
        $participantsKey = implode('_', $participants);

        $conversation = Conversation::firstOrCreate(
            ['participants_key' => $participantsKey],
            [
                'type' => 'customer_to_delivery',
                'delivery_id' => $delivery->id,
                'title' => 'Delivery #' . $delivery->tracking_number
            ]
        );

        // Ensure both participants are in the conversation
        $conversation->participants()->syncWithoutDetaching($participants);

        // Associate conversation with delivery
        $delivery->conversation()->associate($conversation);
        $delivery->save();

        return $conversation;
    }

    // Get delivery options for customer to accept
    public function getDeliveryOptions(Delivery $delivery)
    {
        $user = Auth::user();

        // Verify user is the customer for this delivery
        if ($user->id !== $delivery->order->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'delivery' => $delivery,
            'agent' => $delivery->agent,
            'estimated_arrival_time' => $delivery->estimated_arrival_time,
            'options' => $delivery->delivery_options
        ]);
    }

    public function getDeliveryInfo($orderId)
    {
        try {
            $delivery = Delivery::with('agent')->where('order_id', $orderId)->first();

            if (!$delivery) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'delivery' => $delivery
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getActiveDeliveries(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $deliveries = Delivery::with(['order', 'agent'])
                ->whereHas('order', function ($query) use ($user) {
                    $query->where('user_id', $user->id); // orders.user_id
                })
                ->whereIn('status', [
                    'assigned',
                    'picked_up',
                    'in_transit',
                    'out_for_delivery'
                ]) // active statuses
                ->orderBy('created_at', 'desc')
                ->get();

            broadcast(new ActiveDeliveriesUpdated($user->id, $deliveries));

            return response()->json([
                'success' => true,
                'deliveries' => $deliveries
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch active deliveries: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDeliveryById($id)
    {
        try {
            $delivery = Delivery::with(['agent', 'order', 'trackingHistory'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'delivery' => $delivery
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found'
            ], 404);
        }
    }

}
