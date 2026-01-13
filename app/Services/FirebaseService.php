<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    protected $userMessaging;


    public function __construct()
    {
        // Initialize Firebase
        $userFactory = (new Factory)
            ->withServiceAccount(storage_path('app/firebase/user-firebase-credentials.json'));
        $this->userMessaging = $userFactory->createMessaging();
    }



    /**
     * Send notification to single device
     */
    public function sendToDevice($fcmToken, $title, $body, $data = [])
    {
        try {
            $messaging = $this->userMessaging;
            
            $message = CloudMessage::withTarget('token', $fcmToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $result = $messaging->send($message);
            
            // Log::info('FCM notification sent successfully', [
            //     'token' => $fcmToken,
            //     'result' => $result
            // ]);
            
            return [
                'success' => true,
                'result' => $result
            ];
            
        } catch (\Exception $e) {
            // Log::error('FCM notification failed', [
            //     'token' => $fcmToken,
            //     'error' => $e->getMessage()
            // ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send notification to multiple devices of same app type
     */
    public function sendToMultipleDevices($fcmTokens, $title, $body, $data = [])
    {
        try {
            $messaging = $this->userMessaging;
            
            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            $result = $messaging->sendMulticast($message, $fcmTokens);
            
            // Log::info('FCM multicast notification sent', [
            //     'tokens_count' => count($fcmTokens),
            //     'success_count' => $result->successes()->count(),
            //     'failure_count' => $result->failures()->count()
            // ]);
            
            return [
                'success' => true,
                'result' => $result,
                'success_count' => $result->successes()->count(),
                'failure_count' => $result->failures()->count()
            ];
            
        } catch (\Exception $e) {
            // Log::error('FCM multicast notification failed', [
            //     'tokens_count' => count($fcmTokens),
            //     'error' => $e->getMessage()
            // ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }


}