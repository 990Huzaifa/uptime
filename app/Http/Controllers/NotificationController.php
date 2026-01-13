<?php

namespace App\Http\Controllers;

use App\Events\NotificationList;
use App\Events\ProfileInfo;
use App\Events\SiteLinkList;
use App\Events\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Jobs\FireEvents;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\Notification;
use Illuminate\Support\Facades\Broadcast;


class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        try{
            $user = Auth::user();
            $notifications =Notification::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();

            return response()->json(['message' => 'Notifications listed successfully', 'data' => $notifications], 200);
        }catch(Exception $e){
            return response()->json(['DB error' => $e->getMessage()], 500);
        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function markAsRead($id): JsonResponse
    {
        try{
            $user = Auth::user();
            $notification = Notification::where('id', $id)->where('user_id', $user->id)->first();

            if (!$notification) {
                return response()->json(['message' => 'Notification not found'], 404);
            }

            $notification->update(['read_at' => now()]);

            return response()->json(['message' => 'Notification marked as read successfully'], 200);
        }catch(Exception $e){
            return response()->json(['DB error' => $e->getMessage()], 500);
        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try{
            $user = Auth::user();
            $notification = Notification::where('id', $id)->where('user_id', $user->id)->first();

            if (!$notification) {
                return response()->json(['message' => 'Notification not found'], 404);
            }

            $notification->delete();

            return response()->json(['message' => 'Notification deleted successfully'], 200);
        }catch(Exception $e){
            return response()->json(['DB error' => $e->getMessage()], 500);
        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function broadcast(Request $request)
    {
        // Set sanctum guard
        config(['auth.defaults.guard' => 'sanctum']);
        
        $user = $request->user();
        $channelName = $request->channel_name;
        $socketId = $request->socket_id;
        
        
        
        // Authenticate the channel
        $pusherKey = config('broadcasting.connections.pusher.key');
        $pusherSecret = config('broadcasting.connections.pusher.secret');
        
        $stringToSign = $socketId . ':' . $channelName;
        $signature = hash_hmac('sha256', $stringToSign, $pusherSecret);
        $auth = $pusherKey . ':' . $signature;


        // broadcast((new SiteLinkList($user->id)));
        // broadcast((new SubscriptionPlan($user->id)));
        // broadcast((new NotificationList($user->id)));
        // broadcast((new ProfileInfo($user->id)));

        // we need to run these event separately to avoid delay in response we need a job queue for that

        FireEvents::dispatch($user->id)->delay(now()->addSeconds(3))->onQueue('event-broadcasts');

        return response()->json([
            'auth' => $auth
        ]);
    }



    public function runEvent(): JsonResponse
    {
        // enable logged
        $user = Auth::user();
        
        broadcast(new SiteLinkList($user->id));
        

        return response()->json(['message' => 'Event broadcasted'], 200);
    }
}
