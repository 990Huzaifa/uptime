<?php

namespace App\Console\Commands;

use App\Events\NotifyUser;
use App\Models\Notification;
use App\Models\SiteCheck;
use App\Models\SiteLink;
use App\Models\User;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class Notify extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->comment('');

        $Sites = SiteLink::select('site_links.*','site_checks.checked_at')
            ->join('site_checks', 'site_checks.site_link_id', '=', 'site_links.id')
            ->where('site_links.is_active', 'active')
            ->where('site_links.is_disabled', false)
            ->where('site_links.is_notify', true)
            ->get();
        foreach ($Sites as $Site) {
            $duration = $Site->duration; //30,60,300,1800,3600,43200,86400 in secs
            $last_checked = $Site->checked_at;

            // here we check  last_checked + duration < now
            if( (strtotime($last_checked) + $duration) < time() ) {
                
                $metrics = probe($Site->url, (int)$Site->duration, 15);

                SiteCheck::where('site_link_id', $Site->id)
                    ->update([
                        'status' => $metrics['status'],
                        'response_time_ms' => $metrics['response_time_ms'],
                        'ssl_days_left'    => $metrics['ssl_days_left'],
                        'html_bytes'       => $metrics['html_bytes'],
                        'assets_bytes'       => $metrics['assets_bytes'],
                        'checked_at' => now(),
                    ]);

                $this->comment("Notification sent for site: {$Site->url}");
                $service = new FirebaseService();
                $user = User::find($Site->user_id);
                $data = [
                    'site_link_id' => $Site->id,
                    'status' => $metrics['status'],
                ];
                // send notification logic here
                if($metrics['status'] == 'down') {
                    if($user->fcm_id != null) {
                        $notification = Notification::create([
                            'user_id' => $user->id,
                            'title' => "Site Down Alert",
                            'message' => "The site {$Site->url} is down.",
                            'data' => json_encode($data)
                        ]);
                        $service->sendToDevice($user->fcm_id, "Site Down Alert", "The site {$Site->url} is down.", ['site_link_id' => $Site->id, 'status' => 'down'] );
                    }
                    if($Site->notify_email) {
                        myMailSend(
                            $user->email,
                            $user->name,
                            "Site Down Alert: {$Site->url}",
                            "The site {$Site->url} is down. Please check."
                        );
                    }

                    broadcast(new NotifyUser("Site Down Alert", $user->id, [
                        'site_link_id' => $Site->id,
                        'status' => 'down',
                    ]));
                    
                }else{

                    if($user->fcm_id != null) {

                        $notification = Notification::create([
                            'user_id' => $user->id,
                            'title' => "Site Up Alert",
                            'message' => "The site {$Site->url} is up.",
                            'data' => json_encode($data)
                        ]);

                        $service->sendToDevice($user->fcm_id, "Site Up Alert", "The site {$Site->url} is up.", [
                            'site_link_id' => $Site->id,
                            'status' => 'up',
                        ]);
                    }

                    if($Site->notify_email) {
                        myMailSend(
                            $user->email,
                            $user->name,
                            "Site Up Alert: {$Site->url}",
                            "The site {$Site->url} is up. Please check."
                        );
                    }
                    broadcast(new NotifyUser("Site Up Alert", $user->id, [
                        'site_link_id' => $Site->id,
                        'status' => 'up',
                    ]));
                    
                }
            } else {
                $this->comment("No notification needed for site: {$Site->url}");
            }
        }
    }
}
