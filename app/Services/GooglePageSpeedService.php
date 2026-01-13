<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GooglePageSpeedService
{

    protected const PSI_BASE_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * The base URL for the Chrome UX Report API.
     */
    protected const CRUX_BASE_URL = 'https://chromeuxreport.googleapis.com/v1/records';

    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.google.api_key');
    }

    public function getPageSpeedData(string $url, string $strategy = 'desktop'): ?array
    {

        $categories = ['SEO','PERFORMANCE','BEST_PRACTICES','ACCESSIBILITY'];
        try {
            $query = http_build_query([
                'url'      => $url,
                'strategy' => $strategy,
                'key'      => $this->apiKey,
            ]);

            // manually append categories
            foreach ($categories as $cat) {
                $query .= '&category=' . $cat;
            }

            // Add fields EXACTLY LIKE POSTMAN
            $query .= '&fields=loadingExperience,lighthouseResult.categories';

            $fullUrl = self::PSI_BASE_URL . '?' . $query;

            $response = Http::get($fullUrl);

            return $response->json();
        } catch (\Exception $e) {
            logger()->error('PageSpeed Insights API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getCruxData(string $originOrUrl): ?array
    {
        try {
            $endpoint = self::CRUX_BASE_URL . ':queryRecord?key=' . $this->apiKey;
            $body = [
                'origin' => $originOrUrl,
            ];
            $response = Http::post($endpoint, $body);
            if ($response->successful()) {
                return $response->json();
            }

            logger()->error('CrUX API call failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            logger()->error('CrUX API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }



    public function getCombinedData(string $url): ?array
    {
        $psi = $this->getPageSpeedData($url);
        // $crux = $this->getCruxData($url);

        // if (!$psi && !$crux) return null;

        // Extract Lighthouse category scores (PSI)
        $lighthouse = $psi['lighthouseResult']['categories'] ?? [];

        $scores = [
            'performance'    => $lighthouse['performance']['score'] ?? null,
            'seo'            => $lighthouse['seo']['score'] ?? null,
            'accessibility'  => $lighthouse['accessibility']['score'] ?? null,
            'best_practices' => $lighthouse['best-practices']['score'] ?? null,
        ];

        // Extract CRUX metrics (FCP, LCP, CLS, FID)
        $cruxMetrics = $crux['record']['metrics'] ?? [];

        // $webVitals = [
        //     'fcp' => $cruxMetrics['first_contentful_paint']['percentiles']['p75'] ?? null,
        //     'lcp' => $cruxMetrics['largest_contentful_paint']['percentiles']['p75'] ?? null,
        //     'cls' => $cruxMetrics['cumulative_layout_shift']['percentiles']['p75'] ?? null,
        //     'fid' => $cruxMetrics['first_input_delay']['percentiles']['p75'] ?? null,
        // ];

        return [
            'scores' => $scores,
            // 'web_vitals' => $webVitals,
        ];
    }


}