<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\User;
use App\Services\AppStoreConnectAuth;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;



class ProcessAppleNotificationV2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $signedPayload;
    /**
     * Create a new job instance.
     */
    public function __construct($signedPayload)
    {
        $this->signedPayload = $signedPayload;
    }

    /**
     * Execute the job.
     */
    public function handle(AppStoreConnectAuth $auth): void
    {
        $decodedNotification = $auth->JWSParse($this->signedPayload);
        
        // $decodedNotification = $this->signedPayload;
        $data = $decodedNotification['data'];
        $subtype = $decodedNotification['subtype'] ?? null;
        $notificationType = $decodedNotification['notificationType'];
        // this has to be pars from JWS
        $signedTransactionInfo = $data['signedTransactionInfo'];
        $decodedTransactionInfo = $auth->JWSParse($signedTransactionInfo);
        Log::info('Decoded Notification:', ['transactionInfo' => $decodedTransactionInfo,'notification' => $decodedNotification]);


        $productId = $decodedTransactionInfo['productId'];
        $plan = $planConfig[$productId] ?? null;
        // Log::error('App Store V2 Notification Job Start.');

        $this->subscription($notificationType, $subtype, $decodedTransactionInfo, $productId, $plan);


    }


    private function subscription($notificationType, $subtype, $decodedTransactionInfo, $productId, $plan)
    {
        if($notificationType == "DID_RENEW"){
            $subscription = Subscription::where('transaction_id', $decodedTransactionInfo['originalTransactionId'])->where('platform', 'apple')->first();
            if($subscription){
                $subscription->update([
                    'plan'              => $productId,
                    'expires_at'        => $decodedTransactionInfo['expireDateFormatted'],
                    'status'            => 'active',
                    'canceled_at'      => null,
                ]);

            }
            
        }
        elseif($notificationType == "EXPIRED"){
            $subscription = Subscription::where('transaction_id', $decodedTransactionInfo['originalTransactionId'])->first();
            if($subscription){
                $subscription->update([
                    'status' => 'expired',
                ]);
            }
            // Log::error('App Store V2 Notification Job Done. Subscription not found.'); 
        }
        elseif($notificationType == "DID_CHANGE_RENEWAL_STATUS"){
            if($subtype && $subtype == "AUTO_RENEW_DISABLED"){
                $subscription = Subscription::where('transaction_id', $decodedTransactionInfo['originalTransactionId'])->first();
                if($subscription){
                    $subscription->update([
                        'canceled_at' => Carbon::now(),
                    ]);
                    
                }
            }
            
        }
        else{
            // Log::error('App Store V2 Notification Job Done. Subscription not found.');
        }
    }

}
