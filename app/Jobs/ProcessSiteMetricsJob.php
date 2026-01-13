<?php

namespace App\Jobs;

use App\Models\SiteLink;
use App\Models\SiteCheck;
use App\Services\GooglePageSpeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSiteMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $siteLinkId;

    /**
     * Job timeout (seconds)
     */
    public int $timeout = 120;

    /**
     * Max retries
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(int $siteLinkId)
    {
        $this->siteLinkId = $siteLinkId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $siteLink = SiteLink::find($this->siteLinkId);

        if (!$siteLink) {
            Log::warning('SiteLink not found', ['id' => $this->siteLinkId]);
            return;
        }

        try {
            // 🔹 Probe metrics
            $metrics = probe($siteLink->url, (int) $siteLink->duration, 30);

            if (!$metrics) {
                throw new \Exception('Probe failed');
            }

            // 🔹 Google PageSpeed
            $service = new GooglePageSpeedService();
            $pageSpeedData = $service->getCombinedData($siteLink->url);

            // 🔹 Update latest SiteCheck
            SiteCheck::updateOrCreate(
                ['site_link_id' => $siteLink->id],
                [
                    'status'            => $metrics['status'],
                    'response_time_ms'  => $metrics['response_time_ms'],
                    'ssl_days_left'     => $metrics['ssl_days_left'],
                    'html_bytes'        => $metrics['html_bytes'],
                    'assets_bytes'      => $metrics['assets_bytes'],
                    // 'checked_at'        => $metrics['last_checked_at'],
                    'scores'            => isset($pageSpeedData['scores'])
                        ? json_encode($pageSpeedData['scores'])
                        : null,
                ]
            );

        } catch (Throwable $e) {
            Log::error('ProcessSiteMetricsJob failed', [
                'site_link_id' => $this->siteLinkId,
                'error' => $e->getMessage(),
            ]);

            // rethrow so Laravel can retry
            throw $e;
        }
    }
}
