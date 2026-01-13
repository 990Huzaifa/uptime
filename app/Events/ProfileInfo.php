<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProfileInfo implements ShouldBroadcastNow  
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $user;

    public function __construct($userId)
    {
        $this->userId = $userId;
        $this->user = User::where('id', $userId)->first();
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
        return 'ProfileInfo';  // Socket event name
    }

    public function broadcastWith()
    {
        return [
            'data' => $this->user,
        ];
    }
}
