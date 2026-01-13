<?php

namespace App\Jobs;

use App\Models\SiteLink;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GoogleAuthService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Events\SubscriptionPlan;
use Exception;

class ProcessGoogleNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $base64Data;

    public function __construct(string $base64Data)
    {
        $this->base64Data = $base64Data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $decodedJsonString = base64_decode($this->base64Data);
            $notification = json_decode($decodedJsonString, true);

                // Log::info('RTDN Job: Notification Received.', [
                //     'notification' => $notification
                // ]);
                // 2. Route the Notification
                if (isset($notification['testNotification'])) {
                    $this->handleTestNotification($notification);
                } elseif (isset($notification['subscriptionNotification'])) {
                    $this->handleSubscriptionNotification($notification);
                }elseif (isset($notification['voidedPurchaseNotification'])) {
                    $this->handleVoidedPurchaseNotification($notification);
                } else {
                    Log::warning('RTDN Job: Unhandled Notification Type.', $notification);
                }
        } catch (Exception $e) {
            // This will ensure the job fails and can be retried if necessary,
            // but the webhook itself already returned 200 OK.
            Log::error('RTDN Job failed to process notification.', [
                'error' => $e->getMessage(),
                'base64Decode' => $decodedJsonString
            ]);

            // Optional: Re-throw the exception to trigger Laravel queue retry
            // throw $e; 
        }
    }

    protected function handleTestNotification(array $notification)
    {
        Log::info('RTDN Job: Test Notification successfully confirmed.', [
            'package' => $notification['packageName']
        ]);
        // No further action is usually required for a test.
    }

    protected function handleSubscriptionNotification(array $notification)
    {
        $subNotification = $notification['subscriptionNotification'];
        $type = $subNotification['notificationType'] ?? 'UNKNOWN';
        $purchaseToken = $subNotification['purchaseToken'] ?? 'N/A';
        $subscriptionId = $subNotification['subscriptionId'] ?? 'N/A';

        $data = $this->verifyFromGoogle($purchaseToken, $subscriptionId);

        $orderId = $data['orderId'] ?? 'N/A';
        $orderDetails = $this->getOrderDetails($orderId);
        $productId = $orderDetails['lineItems'][0]['subscriptionDetails']['basePlanId'] ?? null;
        Log::info("RTDN Job: Subscription event received.", [
            'type_code' => $type,
            'orderId' => $orderId,
            'basePlanId' => $productId,
            'data'=> $data
        ]);

        $filterData = [
            "start"=> $data['startTimeMillis'],
            "expiry"=> $data['expiryTimeMillis'],
            "price"=> $data['priceAmountMicros'],
            "user_id"=> $data['obfuscatedExternalAccountId']
        ];
        $filterData = $this->filter($filterData);
        $data = array_merge($data, $filterData);
        
        switch ((int)$type) {
            case 4: // SUBSCRIPTION_PURCHASED (New subscription) insert new subscription but first check if already exists

                break;
            case 2: // SUBSCRIPTION_RENEWED
                $subscription = Subscription::where('user_id', $data['obfuscatedExternalAccountId'])->where('platform', 'google')->first();
                if($subscription){
                    $subscription->update([
                        'plan'              => $productId,
                        'expires_at'        => $data['expiry'],
                        'status'            => 'active',
                        'canceled_at'      => null,
                    ]);
                    FireEvents::dispatch($subscription->user_id)->delay(now()->addSeconds(3))->onQueue('event-broadcasts');
                }
                // fireevent
                
                break;
            case 3: // SUBSCRIPTION_CANCELED
                $subscription = Subscription::where('user_id', $data['obfuscatedExternalAccountId'])->where('platform', 'google')->first();
                if($subscription){
                    $subscription->update([
                        'canceled_at' => Carbon::now(),
                    ]);
                }
                // broadcast(new SubscriptionPlan($data['obfuscatedExternalAccountId']));
                break;
            case 13: // SUBSCRIPTION_EXPIRED
                $subscription = Subscription::where('user_id', $data['obfuscatedExternalAccountId'])->where('plan',$productId)->where('platform', 'google')->first();
                if($subscription){
                    $subscription->update([
                        'status' => 'expired',
                    ]);


                     // 🔥 Disable ALL links
                    SiteLink::where('user_id', $subscription->user_id)
                        ->update([
                            'is_disabled' => true,
                            'duration' => "86400",
                            'notify_email' => 0,
                            'notify_sms' => 0,
                        ]);
                    FireEvents::dispatch($subscription->user_id)->delay(now()->addSeconds(3))->onQueue('event-broadcasts');
                }
                // broadcast(new SubscriptionPlan($data['obfuscatedExternalAccountId']));
                break;
            // ... include other types like RECOVERED, ON_HOLD, etc.
        }

        // IMPORTANT: Use the $purchaseToken to call the Google Play Developer API
        // (server-to-server) to get the final, secure status verification.
        // E.g., AppStoreClient::verifySubscription($purchaseToken, $productId);
    }

    protected function handleVoidedPurchaseNotification(array $notification)
    {
        $voidNotification = $notification['voidedPurchaseNotification'];
        
        // Note: voidedPurchaseNotification is used for both subs AND one-time purchases
        $purchaseToken = $voidNotification['purchaseToken'] ?? 'N/A';
        $orderId = $voidNotification['orderId'] ?? 'N/A';
        
        // Note: The notification doesn't give you the subscriptionId directly, 
        // but the orderId/purchaseToken is enough to look it up in your system.
        // If you need the full subscription details, you might call the Google API 
        // to verify the purchase token against all possible products.

        // 1. Find the subscription or purchase in your database
        // $subscription = Subscription::where('purchase_token', $purchaseToken)
        //                             ->where('platform', 'google')
        //                             ->first();

        Log::info("RTDN Job: Voided Purchase event received (Refund/Revoke).", [
            'purchaseToken' => $purchaseToken,
            'orderId' => $orderId,
            // The refundType is also available in this notification if needed for logging:
            // 'refundType' => $voidNotification['refundType'] ?? 'N/A', 
        ]);

        // 2. Process the refund/void action
        // if ($subscription) {
            
        //     // Revoke Access: The user should immediately lose their premium features.
        //     // The purchase is no longer valid, regardless of the original expiration date.
        //     $subscription->update([
        //         'status' => 'voided',          // Mark as voided/refunded
        //         'canceled_at' => Carbon::now(), // Treat as immediately canceled
        //         'expires_at' => Carbon::now(),  // Set the expiration to now to remove access
        //         'revoked_at' => Carbon::now(),
        //     ]);

        //     // Optional: Implement a mechanism to handle credit deductions
        //     // If the user was given credits for this purchase, you must now deduct them.
        //     // This logic is highly specific to your credit system.
        //     // Example: $this->deductCreditsForVoidedSubscription($subscription);
            
        //     Log::info("RTDN Job: Subscription successfully voided and access revoked.", [
        //         'user_id' => $subscription->user_id,
        //         'purchaseToken' => $purchaseToken,
        //     ]);
        // } else {
        //     // Handle case where it might be a voided one-time product or a subscription 
        //     // that wasn't properly tracked/created initially.
        //     Log::warning("RTDN Job: Voided Purchase Token not found in database.", [
        //         'purchaseToken' => $purchaseToken,
        //         'orderId' => $orderId,
        //     ]);
        // }
    }

    protected function verifyFromGoogle($token, $parent_id)
    {
        // 1. Access Token generate karein
        $packageName = config('services.google.android_package_name');
        $authService = new GoogleAuthService();
        $accessToken = $authService->getAccessToken();

        if (!$accessToken) {
            Log::error('Cannot proceed, Google Access Token not available.');
            return null;
        }

        // 2. Google Play Developer API ka Endpoint URL
        $url = "https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$packageName}/purchases/subscriptions/{$parent_id}/tokens/{$token}";

        try {
            // 3. GET Request bhejhe Access Token header ke saath
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Accept' => 'application/json',
            ])->get($url);

            // 4. Response check karein
            if ($response->successful()) {
                // Success! Response mein subscription details hain
                return $response->json();
            } else {
                // API error (e.g., token invalid, not found)
                Log::warning("Google Verification Failed: " . $response->body());
                return  null;
            }
        } catch (Exception $e) {
            // Connection/Guzzle error
            Log::error("HTTP Request Error to Google: " . $e->getMessage());
            return $e->getMessage();
        }
    }

    private function filter(array $data){
        
        if (isset($data['price'])) {
            $actualPrice = (float) $data['price'] / 1000000; 
            $data['price'] = $actualPrice;
        }
        if (isset($data['start'])) {
            $timestampInSeconds = floor($data['start'] / 1000);
            $data['start'] = Carbon::createFromTimestamp($timestampInSeconds)->toDateTimeString();

        }
        if (isset($data['expiry'])) {
            $timestampInSeconds = floor($data['expiry'] / 1000);
            $data['expiry'] = Carbon::createFromTimestamp($timestampInSeconds)->toDateTimeString();

        }

        return $data;
    }

    private function getOrderDetails($orderId){

        // 1. Access Token generate karein
        $packageName = config('services.google.android_package_name');
        $authService = new GoogleAuthService();
        $accessToken = $authService->getAccessToken();

        if (!$accessToken) {
            Log::error('Cannot proceed, Google Access Token not available.');
            return null;
        }

        // 2. Google Play Developer API ka Endpoint URL
        $url = "https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$packageName}/orders/{$orderId}";

        try {
            // 3. GET Request bhejhe Access Token header ke saath
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Accept' => 'application/json',
            ])->get($url);

            // 4. Response check karein
            if ($response->successful()) {
                // Success! Response mein subscription details hain
                return $response->json();
            } else {
                // API error (e.g., token invalid, not found)
                Log::warning("Google Verification Failed: " . $response->body());
                return  null;
            }
        } catch (Exception $e) {
            // Connection/Guzzle error
            Log::error("HTTP Request Error to Google: " . $e->getMessage());
            return $e->getMessage();
        }
    }
}
