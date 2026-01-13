<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Carbon\Carbon;

class AppStoreConnectAuth
{

    public function generateToken(): string
    {
        
        $keyId = config('services.apple.key_id');          // App Store Connect se mila
        $issuerId = config('services.apple.issuer_id');    // App Store Connect se mila
        $privateKeyPath = storage_path('app/apple/SubscriptionKey_S6952LB8YX.p8'); 
        // 1. Private Key ko .p8 file se read karein
        $privateKey = file_get_contents($privateKeyPath);
        
        if (!$privateKey) {
            throw new \Exception("Private key file not found or unreadable: " . $privateKeyPath);
        }

        // 2. JWT Header (Aapki 'p8' key ki information)
        $header = [
            'alg' => 'ES256', // Algorithm hamesha 'ES256' hoga
            'kid' => $keyId,  // Key ID (.p8 key ke saath mila)
            'typ' => 'JWT',   // Token Type
        ];

        // 3. JWT Payload (Token ki details)
        // IAT: Issued At (Token kab bana)
        $issuedAt = Carbon::now()->getTimestamp();
        
        // EXP: Expiration (Token kab expire hoga - 20 minutes maximum)
        $expiration = Carbon::now()->addMinutes(19)->getTimestamp(); 
        
        $payload = [
            // 'iss': Issuer ID (App Store Connect API key ka Issuer ID)
            'iss' => $issuerId, 
            
            // 'iat': Issued At (Current time)
            'iat' => $issuedAt, 
            
            // 'exp': Expiration Time (Max 20 minutes)
            'exp' => $expiration, 
            
            // 'aud': Audience (App Store Connect API ke liye fixed value)
            'aud' => 'appstoreconnect-v1',
            
            'bid' => 'com.obtech.downlinknotifierios',
            // 'bid': Bundle ID (Agar sirf ek app ke liye use kar rahe hain)
            // Note: App Store Server API ke liye 'bid' zaroori nahi, lekin App Store Connect API ke liye hota hai. 
            // Agar App Store Server API ke liye use kar rahe hain to isko hata sakte hain ya 'scope' use karein.
            // Hum App Store Server API (in-app purchases) assume kar rahe hain.
        ];

        // 4. Token Create aur Sign karein
        // ES256: ECDSA using P-256 and SHA-256
        $jwtToken = JWT::encode($payload, $privateKey, 'ES256', $keyId, $header);

        return $jwtToken;
    }

    public function JWSParse($data)
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
}