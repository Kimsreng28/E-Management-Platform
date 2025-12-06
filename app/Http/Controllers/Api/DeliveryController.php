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
        $agents = User::where('role_id', 5) // Delivery role
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
            $agent = User::where('role_id', 5)
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
            // Verify the authenticated user is the delivery agent for this delivery
            $user = Auth::user();
            if ($user->id !== $delivery->delivery_agent_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: You are not assigned to this delivery'
                ], 403);
            }

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

                // Also update user's last known location
                $user->last_lat = $request->lat;
                $user->last_lng = $request->lng;
                $user->last_location_at = now();
                $user->save();

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
                'delivery' => $delivery->fresh() // Return fresh data
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateLocation(Request $request)
    {
        $request->validate([
            'delivery_id' => 'required|exists:deliveries,id',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric'
        ]);

        Log::info('Update location request received', [
            'user_id' => Auth::id(),
            'delivery_id' => $request->delivery_id,
            'lat' => $request->lat,
            'lng' => $request->lng
        ]);

        try {
            $user = Auth::user();
            $delivery = Delivery::findOrFail($request->delivery_id);

            // Verify the authenticated user is the delivery agent for this delivery
            if ($user->id !== $delivery->delivery_agent_id) {
                Log::warning('Unauthorized location update attempt', [
                    'user_id' => $user->id,
                    'agent_id' => $delivery->delivery_agent_id
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: You are not assigned to this delivery'
                ], 403);
            }

            DB::beginTransaction();

            // Update THIS delivery's location
            $delivery->agent_lat = $request->lat;
            $delivery->agent_lng = $request->lng;
            $delivery->save();

            Log::info('Delivery location updated', [
                'delivery_id' => $delivery->id,
                'lat' => $delivery->agent_lat,
                'lng' => $delivery->agent_lng
            ]);

            // Update user's last known location
            $user->last_lat = $request->lat;
            $user->last_lng = $request->lng;
            $user->last_location_at = now();
            $user->save();

            Log::info('User location updated', [
                'user_id' => $user->id,
                'last_lat' => $user->last_lat,
                'last_lng' => $user->last_lng
            ]);

            // Create tracking history entry for location update
            DeliveryTracking::create([
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
                'notes' => 'Location updated',
                'lat' => $request->lat,
                'lng' => $request->lng
            ]);

            Log::info('Tracking history created');

            // Try to broadcast the update
            try {
                event(new DeliveryAgentLocationUpdated($delivery, $request->lat, $request->lng));
                Log::info('DeliveryAgentLocationUpdated event fired');
            } catch (\Exception $e) {
                Log::error('Failed to broadcast event: ' . $e->getMessage());
                // Don't fail the whole request if broadcasting fails
            }

            // Also broadcast to update all active deliveries for this agent
            try {
                $this->broadcastActiveDeliveries($user);
                Log::info('Active deliveries broadcast sent');
            } catch (\Exception $e) {
                Log::error('Failed to broadcast active deliveries: ' . $e->getMessage());
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'delivery' => $delivery->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in updateLocation: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update location: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateInitialLocation(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric'
        ]);

        Log::info('updateInitialLocation called', [
            'user_id' => Auth::id(),
            'lat' => $request->lat,
            'lng' => $request->lng
        ]);

        try {
            $user = Auth::user();

            if (!$user) {
                Log::error('No authenticated user found');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            if ($user->role_id !== 5) {
                Log::warning('User is not a delivery agent', ['user_id' => $user->id, 'role_id' => $user->role_id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only delivery agents can update location'
                ], 403);
            }

            DB::beginTransaction();

            // Update ALL deliveries assigned to this agent
            $updatedCount = Delivery::where('delivery_agent_id', $user->id)
                ->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'])
                ->update([
                    'agent_lat' => $request->lat,
                    'agent_lng' => $request->lng,
                    'updated_at' => now()
                ]);

            Log::info('Deliveries updated', ['count' => $updatedCount]);

            // Update user's location using forceFill to bypass mass assignment
            $user->forceFill([
                'last_lat' => $request->lat,
                'last_lng' => $request->lng,
                'last_location_at' => now(),
                'last_seen_at' => now(), // Also update last_seen_at
            ])->save();

            // Get all updated deliveries to broadcast
            $deliveries = Delivery::where('delivery_agent_id', $user->id)
                ->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'])
                ->get();

            Log::info('Found deliveries for broadcasting', ['count' => $deliveries->count()]);

            // Broadcast location update for each delivery
            foreach ($deliveries as $delivery) {
                try {
                    // Create tracking entry
                    DeliveryTracking::create([
                        'delivery_id' => $delivery->id,
                        'status' => $delivery->status,
                        'notes' => 'Location initialized',
                        'lat' => $request->lat,
                        'lng' => $request->lng
                    ]);

                    // Broadcast the update
                    event(new DeliveryAgentLocationUpdated($delivery, $request->lat, $request->lng));

                    Log::debug('Broadcast sent for delivery', ['delivery_id' => $delivery->id]);
                } catch (\Exception $e) {
                    Log::error('Error broadcasting for delivery ' . $delivery->id . ': ' . $e->getMessage());
                }
            }

            // Broadcast active deliveries update
            $this->broadcastActiveDeliveries($user);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Initial location set for ' . $updatedCount . ' deliveries',
                'updated_count' => $updatedCount,
                'user_location' => [
                    'lat' => $user->last_lat,
                    'lng' => $user->last_lng,
                    'last_location_at' => $user->last_location_at,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in updateInitialLocation: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update initial location: ' . $e->getMessage()
            ], 500);
        }
    }

    private function broadcastActiveDeliveries($user)
    {
        try {
            $deliveries = Delivery::with(['order', 'agent'])
                ->where('delivery_agent_id', $user->id)
                ->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'])
                ->get();

            broadcast(new ActiveDeliveriesUpdated($user->id, $deliveries))->toOthers();
        } catch (\Exception $e) {
            Log::error('Broadcast failed: ' . $e->getMessage());
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

    // Get delivery route coordinates (for drawing path)
    public function getDeliveryRoute(Delivery $delivery)
    {
        // This would typically integrate with a mapping service like Google Maps or Mapbox
        // For now, return tracking history as points
        $trackingPoints = DeliveryTracking::where('delivery_id', $delivery->id)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('created_at', 'asc')
            ->get(['lat', 'lng', 'created_at', 'status'])
            ->toArray();

        return response()->json([
            'success' => true,
            'route_points' => $trackingPoints,

            'current_location' => [
                'lat' => $delivery->agent_lat,
                'lng' => $delivery->agent_lng,
            ]
        ]);
    }

    // Get multiple deliveries for map view (admin dashboard)
    public function getActiveDeliveriesMap()
    {
        try {
            $deliveries = Delivery::with(['agent', 'order.customer', 'order.shippingAddress'])
                ->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'])
                ->whereNotNull('agent_lat')
                ->whereNotNull('agent_lng')
                ->get()
                ->map(function ($delivery) {
                    return [
                        'id' => $delivery->id,
                        'tracking_number' => $delivery->tracking_number,
                        'status' => $delivery->status,
                        'agent' => [
                            'id' => $delivery->agent->id ?? null,
                            'name' => $delivery->agent->name ?? 'Unknown',
                            'phone' => $delivery->agent->phone ?? null,
                        ],
                        'customer' => [
                            'id' => $delivery->order->customer->id ?? null,
                            'name' => $delivery->order->customer->name ?? 'Unknown',
                        ],

                        'last_updated' => $delivery->updated_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'deliveries' => $deliveries
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch deliveries map data: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get delivery agent's deliveries for their map view
    public function getAgentDeliveriesMap(Request $request)
    {
        try {
            $user = $request->user();

            if ($user->role_id !== 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $deliveries = Delivery::with([
                'order.user',
                'order.items.product',
                'order.shippingAddress',
                'order.billingAddress'
            ])
                ->where('delivery_agent_id', $user->id)
                ->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'])
                ->get()
                ->map(function ($delivery) {
                    $customer = $delivery->order->user ?? null;
                    $shippingAddress = $delivery->order->shippingAddress ?? null;
                    $items = $delivery->order->items ?? collect();

                    return [
                        'id' => $delivery->id,
                        'tracking_number' => $delivery->tracking_number,
                        'status' => $delivery->status,
                        'estimated_arrival_time' => $delivery->estimated_arrival_time,
                        'delivery_notes' => $delivery->delivery_notes,
                        'customer_accepted_at' => $delivery->customer_accepted_at,

                        'customer' => $customer ? [
                            'name' => $customer->name,
                            'phone' => $customer->phone,
                            'email' => $customer->email,
                        ] : null,

                        'order' => [
                            'id' => $delivery->order->id,
                            'order_number' => $delivery->order->order_number,
                            'total' => $delivery->order->total,
                            'subtotal' => $delivery->order->subtotal,
                            'shipping_cost' => $delivery->order->shipping_cost,
                            'tax_amount' => $delivery->order->tax_amount,
                            'discount_amount' => $delivery->order->discount_amount,
                            'currency' => $delivery->order->currency,
                            'notes' => $delivery->order->notes,
                            'status' => $delivery->order->status,
                            'created_at' => $delivery->order->created_at,

                            'shipping_address' => $shippingAddress ? [
                                'address_line' => trim(($shippingAddress->address_line_1 ?? '') . ' ' . ($shippingAddress->address_line_2 ?? '')),
                                'city' => $shippingAddress->city,
                                'state' => $shippingAddress->state,
                                'postal_code' => $shippingAddress->postal_code,
                                'country' => $shippingAddress->country,
                                'latitude' => $shippingAddress->latitude,
                                'longitude' => $shippingAddress->longitude,
                            ] : null,

                            'items' => $items->map(function ($item) {
                                return [
                                    'id' => $item->id,
                                    'product_id' => $item->product_id,
                                    'product_name' => $item->product_name,
                                    'product_model' => $item->product_model,
                                    'quantity' => $item->quantity,
                                    'unit_price' => $item->unit_price,
                                    'total' => $item->total,
                                    'product' => $item->product ? [
                                        'name' => $item->product->name,
                                        'images' => $item->product->images,
                                    ] : null,
                                ];
                            })->toArray(),
                        ],
                    ];
                });

            return response()->json([
                'success' => true,
                'deliveries' => $deliveries,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch agent deliveries: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deliveryStats()
    {
        try {
            $activeDeliveries = Delivery::whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'])
                ->count();

            $assignedDeliveries = Delivery::where('status', 'assigned')
                ->count();

            $inTransitDeliveries = Delivery::whereIn('status', ['out_for_delivery', 'in_transit'])
                ->count();

            $deliveredToday = Delivery::where('status', 'delivered')
                ->whereDate('delivered_at', now()->toDateString())
                ->count();

            // Calculate average delivery time
            $averageTime = Delivery::where('status', 'delivered')
                ->whereNotNull('delivered_at')
                ->whereNotNull('assigned_at')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, assigned_at, delivered_at)) as avg_time'))
                ->first();

            return response()->json([
                'success' => true,
                'active' => $activeDeliveries,
                'assigned' => $assignedDeliveries,
                'in_transit' => $inTransitDeliveries,
                'delivered_today' => $deliveredToday,
                'average_delivery_time' => round($averageTime->avg_time ?? 0, 2)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivery stats'
            ], 500);
        }
    }

    public function getAgentDashboardDeliveries(Request $request)
    {
        try {
            $user = $request->user();

            if ($user->role_id !== 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Include address_line and coordinates in shipping address
            $deliveries = Delivery::with([
                'order.user',
                'order.items.product',
                'order.shippingAddress',
                'order.billingAddress'
            ])
                ->where('delivery_agent_id', $user->id)
                ->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'])
                ->get()
                ->map(function ($delivery) {
                    $shippingAddress = $delivery->order->shippingAddress ?? null;

                    return [
                        'id' => $delivery->id,
                        'tracking_number' => $delivery->tracking_number,
                        'status' => $delivery->status,
                        'estimated_arrival_time' => $delivery->estimated_arrival_time,
                        'delivery_notes' => $delivery->delivery_notes,
                        'agent_lat' => $delivery->agent_lat,
                        'agent_lng' => $delivery->agent_lng,

                        'customer' => $delivery->order->user ? [
                            'name' => $delivery->order->user->name,
                            'phone' => $delivery->order->user->phone,
                            'email' => $delivery->order->user->email,
                        ] : null,

                        'order' => [
                            'id' => $delivery->order->id,
                            'order_number' => $delivery->order->order_number,
                            'total' => $delivery->order->total,
                            'status' => $delivery->order->status,

                            'shipping_address' => $shippingAddress ? [
                                'address_line' => trim(($shippingAddress->address_line_1 ?? '') . ' ' . ($shippingAddress->address_line_2 ?? '')),
                                'city' => $shippingAddress->city,
                                'state' => $shippingAddress->state,
                                'postal_code' => $shippingAddress->postal_code,
                                'country' => $shippingAddress->country,
                                'latitude' => $shippingAddress->latitude,
                                'longitude' => $shippingAddress->longitude,
                            ] : null,

                            'items' => $delivery->order->items->map(function ($item) {
                                return [
                                    'product_name' => $item->product_name,
                                    'quantity' => $item->quantity,
                                    'unit_price' => $item->unit_price,
                                    'total' => $item->quantity * $item->unit_price,
                                ];
                            }),
                        ],
                    ];
                });

            return response()->json([
                'success' => true,
                'deliveries' => $deliveries,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch agent deliveries: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAgentDeliveryHistory(Request $request)
    {
        try {
            $user = $request->user();

            if ($user->role_id !== 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Use paginate properly
            $deliveries = Delivery::with([
                'order.user',
                'order.items.product',
                'order.shippingAddress',
            ])
            ->where('delivery_agent_id', $user->id)
            ->whereIn('status', ['delivered', 'completed'])
            ->orderBy('delivered_at', 'desc')
            ->paginate(10);

            // Format the response
            $formattedDeliveries = $deliveries->map(function ($delivery) {
                $customer = $delivery->order->user ?? null;
                $shippingAddress = $delivery->order->shippingAddress ?? null;
                $customerReviews = [];

                // Get product reviews for this order
                foreach ($delivery->order->items as $item) {
                    if ($item->product) {
                        $reviews = $item->product->reviews()
                            ->where('order_id', $delivery->order->id)
                            ->with('user')
                            ->get();

                        foreach ($reviews as $review) {
                            $customerReviews[] = [
                                'product_name' => $item->product_name,
                                'rating' => $review->rating,
                                'comment' => $review->comment,
                                'created_at' => $review->created_at,
                                'customer_name' => $review->user->name ?? 'Anonymous'
                            ];
                        }
                    }
                }

                return [
                    'id' => $delivery->id,
                    'tracking_number' => $delivery->tracking_number,
                    'status' => $delivery->status,
                    'delivered_at' => $delivery->delivered_at,
                    'estimated_arrival_time' => $delivery->estimated_arrival_time,
                    // IMPORTANT: Include agent_rating from the delivery record
                    'agent_rating' => $delivery->agent_rating,
                    'agent_rating_comment' => $delivery->agent_rating_comment,
                    'agent_rated_at' => $delivery->agent_rated_at,

                    'customer' => $customer ? [
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                    ] : null,

                    'order' => [
                        'id' => $delivery->order->id,
                        'order_number' => $delivery->order->order_number,
                        'total' => $delivery->order->total,
                        'status' => $delivery->order->status,

                        'shipping_address' => $shippingAddress ? [
                            'address_line' => trim(($shippingAddress->address_line_1 ?? '') . ' ' . ($shippingAddress->address_line_2 ?? '')),
                            'city' => $shippingAddress->city,
                            'state' => $shippingAddress->state,
                            'postal_code' => $shippingAddress->postal_code,
                            'country' => $shippingAddress->country,
                        ] : null,

                        'items' => $delivery->order->items->map(function ($item) {
                            return [
                                'product_name' => $item->product_name,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'total' => $item->quantity * $item->unit_price,
                            ];
                        }),
                    ],

                    'customer_reviews' => $customerReviews,
                    'review_count' => count($customerReviews),
                ];
            });

            // Calculate stats
            $ratedDeliveries = $deliveries->whereNotNull('agent_rating');

            return response()->json([
                'success' => true,
                'deliveries' => $formattedDeliveries,
                'pagination' => [
                    'current_page' => $deliveries->currentPage(),
                    'total_pages' => $deliveries->lastPage(),
                    'total_items' => $deliveries->total(),
                    'per_page' => $deliveries->perPage(),
                ],
                'stats' => [
                    'total_deliveries' => $deliveries->total(),
                    'average_rating' => $ratedDeliveries->avg('agent_rating') ?? 0,
                    'total_reviews' => $formattedDeliveries->sum('review_count'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivery history: ' . $e->getMessage()
            ], 500);
        }
    }

    public function rateDeliveryAgent(Request $request, Delivery $delivery)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        // Verify user is the customer for this delivery
        if ($user->id !== $delivery->order->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only the customer can rate this delivery'
            ], 403);
        }

        // Check if delivery is completed
        if ($delivery->status !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot rate delivery that is not yet delivered'
            ], 400);
        }

        // Check if already rated
        if ($delivery->agent_rating !== null) {
            return response()->json([
                'success' => false,
                'message' => 'You have already rated this delivery'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Update delivery with rating
            $delivery->update([
                'agent_rating' => $request->rating,
                'agent_rating_comment' => $request->comment,
                'agent_rated_at' => now(),
            ]);

            // Update delivery agent's average rating
            $agent = $delivery->agent;
            if ($agent) {
                $agent->updateDeliveryStats();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery agent rated successfully',
                'delivery' => $delivery->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to rate delivery agent: ' . $e->getMessage()
            ], 500);
        }
    }
}
