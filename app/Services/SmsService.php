<?php

namespace App\Services;

use Twilio\Rest\Client;
use Exception;

class SmsService
{
    protected $twilio;
    protected $from;

    public function __construct()
    {
        $this->twilio = new Client(
            config('services.twilio.sid'),
            config('services.twilio.auth_token')
        );
        $this->from = config('services.twilio.phone_number');
    }

    public function sendSms($to, $message)
    {
        try {
            $message = $this->twilio->messages->create($to, [
                'from' => $this->from,
                'body' => $message
            ]);

            return [
                'success' => true,
                'message_sid' => $message->sid
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}