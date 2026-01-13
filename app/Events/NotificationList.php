<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationList implements ShouldBroadcastNow  
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $notifications;

    public function __construct($userId)
    {
        $this->userId = $userId;
        $this->notifications = Notification::where('user_id', $userId)->get();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn()
    {
        return [new PrivateChannel('user.' . $this->userId)]; // Broadcasting to the user-specific channel
    }

    public function broadcastAs() 
    { 
        return 'NotificationList';  // Socket event name
    }

    public function broadcastWith()
    {
        return [
            'data' => $this->notifications,
        ];
    }
}
