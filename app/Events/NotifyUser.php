<?php

namespace App\Events;

use App\Models\SiteLink;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyUser  implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $title;
    public $userId;
    public $data; // extra  info to send

    public function __construct($title, $userId, array $data = [])
    {
        $this->title = $title;
        $this->userId = (int)$userId;
        $site =SiteLink::select('site_links.*','site_checks.*')
            ->join('site_checks', 'site_checks.site_link_id', '=', 'site_links.id')
            ->where('site_links.id', $data['site_link_id'] ?? 0)
            ->where('site_links.user_id', $userId)
            ->first();
        $this->data = array_merge($data, ['site' => $site]);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId),
        ];
    }

    public function broadcastAs() 
    { 
        return 'NotifyUser'; 
    }

    public function broadcastWith(): array
    {
        return array_merge(['title' => $this->title], $this->data);
    }
}
