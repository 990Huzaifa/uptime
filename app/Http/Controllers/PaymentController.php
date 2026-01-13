<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\GoogleAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\CreditsWallet as Wallet;
use App\Models\CreditsTransaction;
use App\Models\Subscription;
use Carbon\Carbon;
use Exception;
use App\Services\AppStoreConnectAuth;

class PaymentController extends Controller
{
    public function verifyapple(Request $request): JsonResponse
    {
        try{
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'receipt' => 'required|string',
                'product_id' => 'required|string'
            ]);
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 422);
            }
            
            $receiptData = $request->input('receipt');
            // $datar = $this->getData($receiptData);
            $transaction_id = $this->gettId($receiptData);
            // verify from server
            $res = $this->verifyFromApple($transaction_id);
            if(!$res) return response()->json(['error' => 'Invalid receipt'], 400);
            $latestReceipt = $res;
            // check subscription here..

            $checkSub = $user->subscription()->where('platform', 'apple')->orderBy('updated_at', 'desc')->first();
            $caseData = 'new';
            if ($checkSub) {
                $caseData = 'upgrade';
            }
            // return response()->json($caseData);


            $productId = $latestReceipt['productId'];

            $expiresAt = $latestReceipt['expireDateFormatted'];
            // transaction
            if($caseData == 'new'){
                DB::transaction(function () use ($user, $productId, $expiresAt, $latestReceipt) {
                    Subscription::Create([
                            'user_id'           => $user->id,
                            'plan'              => $productId,
                            'transaction_id'    => $latestReceipt['transactionId'],
                            'status'            => 'active',
                            'expires_at'        => $expiresAt,
                    ]);
                });
            }
            if($caseData == 'upgrade'){
                DB::transaction(function () use ($user, $productId, $expiresAt, $latestReceipt) {
                    $user->subscription()->where('transaction_id', $latestReceipt['originalTransactionId'])->update([
                        'plan'              => $productId,
                        'status'            => 'active',
                        'expires_at'        => $expiresAt,
                    ]);
                });
            }

            return response()->json(['message' => 'Payment verified successfully'], 200);
        }catch(QueryException $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }catch(Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // apple verify functions start

    private function verifyFromApple($t_id)
    {
        $authService = new AppStoreConnectAuth();
        $jwtToken = $authService->generateToken();

        $productionUrl = 'https://api.storekit.itunes.apple.com/inApps/v1/transactions/'.$t_id;
        $sandboxUrl = 'https://api.storekit-sandbox.itunes.apple.com/inApps/v1/transactions/'.$t_id;

        // hit post request to production
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$jwtToken,
        ])->get($productionUrl);

        if ($response->failed()) {
            // hit post request to sandbox
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$jwtToken,
            ])->get($sandboxUrl);
        }
        
        // here we compare the transaction id from the response with the transaction id from the request
        if ($response->successful()) {
            $data = $response->json();
            $transaction_id = $this->gettId($data['signedTransactionInfo']);
            if ($transaction_id == $t_id) {
                $data = $this->getData($data['signedTransactionInfo']);
                return $data;
            }
        }
    }

    private function gettId($data)
    {
        $pl = explode(".", $data);
        $newdata = json_decode(base64_decode($pl[1]), true);
        $transaction_id = $newdata['transactionId']; 
        return $transaction_id;
    }

    private function getOgtId($data)
    {
        $pl = explode(".", $data);
        $newdata = json_decode(base64_decode($pl[1]), true);
        $transaction_id = $newdata['originalTransactionId']; 
        return $transaction_id;
    }

    private function getData($data)
    {
        $pl = explode(".", $data);
        $newdata = json_decode(base64_decode($pl[1]), true);

        if (isset($newdata['purchaseDate'])) {
            $timestampInSeconds = floor($newdata['purchaseDate'] / 1000);
            
            // Purchase Date ko Carbon se format karna
            $newdata['purchaseDateFormatted'] = Carbon::createFromTimestamp($timestampInSeconds)->toDateTimeString();
            // Agar aap chahein to original millisecond value ko hata bhi sakte hain
            // unset($newdata['purchaseDate']); 
        }

        if (isset($newdata['originalPurchaseDate'])) {
            $timestampInSeconds = floor($newdata['originalPurchaseDate'] / 1000);
            
            // Original Purchase Date ko format karna
            $newdata['originalPurchaseDateFormatted'] = Carbon::createFromTimestamp($timestampInSeconds)->toDateTimeString();
            // unset($newdata['originalPurchaseDate']);
        }

        if (isset($newdata['price']) && isset($newdata['currency'])) {
            
            // Step A: Micro-units ko asal price mein tabdeel karna (Divide by 1,000,000)
            $actualPrice = (float) $newdata['price'] / 1000.0; 
            
            // Step B: Asal price ko ek nayi field mein save karna
            $newdata['priceActual'] = $actualPrice;

        }
        if (isset($newdata['expiresDate'])) {
            $timestampInSeconds = floor($newdata['expiresDate'] / 1000);
            
            // Expire Date ko Carbon se format karna
            $newdata['expireDateFormatted'] = Carbon::createFromTimestamp($timestampInSeconds)->toDateTimeString();
            // Agar aap chahein to original millisecond value ko hata bhi sakte hain
            // unset($newdata['ExpireDate']); 
        }

        return $newdata;
    }


    // apple verify functions end

    // google verify functions start


    private function verifyFromGoogle($token, $parent_id)
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

    // google verify functions end

    // new functions here

    public function verifygoogle(Request $request): JsonResponse
    {
        try{
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'parent_id' => 'required|string',
                'product_id' => 'required|string',
            ],[
                'token.required' => 'The token field is required.',
                'parent_id.required' => 'The parent_id field is required.',
                'product_id.required' => 'The product_id field is required.',
            ]);
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 422);
            }

            // ============================
            // 🎯 PLAN MAPPING
            // ============================


            $checkSub = $user->subscription()->where('platform', 'google')->where('user_id', $user->id)->first();
            $caseData = 'new';
            if ($checkSub) {
                $caseData = 'upgrade';
            }

            $productId = $request->input('product_id');
            $parentId = $request->input('parent_id');
            $purchaseToken = $request->input('token');
            $verificationData = $this->verifyFromGoogle($purchaseToken, $parentId);
            if(!$verificationData) return response()->json(['error' => 'Invalid Purchase'], 400);
            // Log::info('Google Verification Data: ' . json_encode($verificationData));
            $account_id = $verificationData['obfuscatedExternalAccountId']  ?? $user->id;
            $filterData = [
                "start"=> $verificationData['startTimeMillis'],
                "expiry"=> $verificationData['expiryTimeMillis'],
                "price"=> $verificationData['priceAmountMicros']
            ];

            $filterData = $this->filter($filterData);
            $verificationData = array_merge($verificationData, $filterData);
            
            // db transaction start here
            if($caseData == 'new'){
                DB::transaction(function () use ($user, $productId, $verificationData) {
                    Subscription::Create([
                        'user_id'           => $user->id,
                        'plan'              => $productId,
                        'platform'          => "google",
                        'transaction_id'    => $verificationData['obfuscatedExternalAccountId'] ?? $user->id,
                        'status'            => 'active',
                        'expires_at'        => $verificationData['expiry'],
                    ]);
                });
            }
            if($caseData == 'upgrade'){
                DB::transaction(function () use ($user, $productId, $verificationData) {
                    $user->subscription()->update([
                        'plan'              => $productId,
                        'platform'          => "google",
                        'transaction_id'    => $verificationData['obfuscatedExternalAccountId'] ?? $user->id,
                        'status'            => 'active',
                        'expires_at'        => $verificationData['expiry'],
                    ]);
                });
            }
            return response()->json(['message' => 'Payment verified successfully'], 200);
        }catch(QueryException $e){
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }catch(Exception $e){
            Log::error($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
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

    public function googleCancel(Request $request) {


        $token = $request->input('token');
        $parent_id = $request->input('parent_id');
        // 1. Access Token generate karein
        $packageName = config('services.google.android_package_name');
        $authService = new GoogleAuthService();
        $accessToken = $authService->getAccessToken();

        if (!$accessToken) {
            Log::error('Cannot proceed, Google Access Token not available.');
            return null;
        }

        $url = "https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$packageName}/purchases/subscriptions/{$parent_id}/tokens/{$token}:cancel";
        try{
            // 3. GET Request bhejhe Access Token header ke saath
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Accept' => 'application/json',
            ])->get($url);


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
