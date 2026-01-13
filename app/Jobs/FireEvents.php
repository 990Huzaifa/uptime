<?php

namespace App\Jobs;

use App\Events\ProfileInfo;
use App\Events\SubscriptionPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FireEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    protected $userId;
    /**
     * Create a new job instance.
     */
    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Delay of 5 seconds
        sleep(3);  // Wait for 5 seconds before firing the events

        // Trigger the events
        // broadcast(new SiteLinkList($this->userId));
        broadcast(new SubscriptionPlan($this->userId));
        // broadcast(new NotificationList($this->userId));
        broadcast(new ProfileInfo($this->userId));
    }
}
