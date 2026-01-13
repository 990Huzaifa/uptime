<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sendinblue\Client\Configuration;
use Sendinblue\Client\Api\EmailCampaignsApi;
use Sendinblue\Client\Model\CreateEmailCampaign;
use GuzzleHttp\Client;
use Exception;

class BrevoService
{
    protected $apiUrl = 'https://api.brevo.com/v3/smtp/email';
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = env('BREVO_API_KEY');
    }


    public function sendMail(string $subject,string $toEmail,string $toName,string $htmlContent,string $fromEmail = null,string $fromName = null)
    {
        $fromEmail = "surajkumar@techvince.com";
        $fromName = "Downlink Notifyer";
        $payload = [
            'sender' => [
                'name' => $fromName,
                'email' => $fromEmail,
            ],
            'to' => [
                [
                    'email' => $toEmail,
                    'name' => $toName,
                ],
            ],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ];
        $response = Http::withHeaders([
            'accept' => 'application/json',
            'api-key' => $this->apiKey,
            'content-type' => 'application/json',
        ])->post($this->apiUrl, $payload);

        if ($response->successful()) {
            return ['success' => true, 'response' => $response->json()];
        }

        return [
            'success' => false,
            'error' => $response->json(),
            'status' => $response->status(),
        ];
    }
}
