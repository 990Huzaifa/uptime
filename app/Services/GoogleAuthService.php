<?php

namespace App\Services;

use Google\Client;

class GoogleAuthService
{
    /**
     * Google Play Developer API ke liye Access Token generate karta hai.
     * @return string|null Access Token ya null agar error ho.
     */
    public function getAccessToken()
    {
        // 1. JSON Key file ka path set karein.
        // Ise .env file mein rakhein aur config() se fetch karein.
        // Example: config('services.google.play_service_account_path')
        $keyFilePath = storage_path('app/google/service-account.json'); // Apne path se replace karein

        if (!file_exists($keyFilePath)) {
            // Log error: Key file not found
            return null;
        }

        try {
            $client = new Client();
            // Google Play Developer API ka required scope
            $scope = ['https://www.googleapis.com/auth/androidpublisher'];

            // Service Account credentials load karein
            $client->setAuthConfig($keyFilePath);
            $client->setScopes($scope);

            // Access Token fetch karein
            $token = $client->fetchAccessTokenWithAssertion();

            // Check karein ki token mil gaya hai
            if (isset($token['access_token'])) {
                return $token['access_token'];
            }

        } catch (\Exception $e) {
            // Log the exception for debugging
            \Log::error("Google Access Token Error: " . $e->getMessage());
        }

        return null;
    }
}