<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGoogleNotification;
use App\Jobs\ProcessAppleNotificationV2;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $signedPayload = $request->input('signedPayload');

        if (!$signedPayload) {
            // Log the error for missing payload but still return 200 to prevent retries
            Log::error('App Store V2 Notification received with missing signedPayload.');
            return response()->json(['status' => 'ok']);
        }
        // This is crucial: respond quickly (within a few seconds) and process asynchronously.
        ProcessAppleNotificationV2::dispatch($signedPayload)->onQueue('apple-webhooks');

        // 3. Respond with 200 OK to acknowledge receipt
        return response()->json(['status' => 'ok'], 200);
    }

    public function handleGoogle(Request $request)
    {

        $data = $request->input('message.data');
        // here ye need to set a job for better and background processing
        ProcessGoogleNotification::dispatch($data)->onQueue('google-webhooks');
        // Must return a 200 status code to acknowledge receipt
        return response()->json(['status' => 'ok'], 200);
    }
}
