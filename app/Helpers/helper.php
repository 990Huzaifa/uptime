<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Str;

function probe(string $url, int $durationSeconds = 60, int $timeout = 60)
{
    $timings = [
        'ttfb_ms'   => null,
        'total_ms'  => null,
    ];

    $response = null;

    // Request + timings
        try {
            $response = Http::withHeaders([
                    'User-Agent' => 'DownLinkNotifyer/1.0 (+https://downlink.techvince.com)'
                ])
                ->timeout($timeout)
                ->withOptions([
                    'allow_redirects' => true,
                    'on_stats' => function (\GuzzleHttp\TransferStats $stats) use (&$timings) {
                        $handler = $stats->getHandlerStats(); // cURL stats
                        // TTFB: namelookup + connect + appconnect + pretransfer + starttransfer
                        if (isset($handler['starttransfer_time'])) {
                            $timings['ttfb_ms']  = (int) round($handler['starttransfer_time'] * 1000);
                        }
                        $timings['total_ms'] = (int) round($stats->getTransferTime() * 1000);
                    },
                ])
                ->get($url);
        } catch (\Exception $e) {
            // Agar URL hi invalid ya network issue hai
            return false;
        }

            // new retrive
                // $psi_api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
                // $psi_response = Http::get($psi_api_url, [
                //     'url'      => $url, // User ka URL
                //     'strategy' => 'desktop', // Ya 'mobile'
                //     // Aapko API key ki zaroorat nahi hai, agar requests limited ho
                // ]);
                // $performanceScore = null;
                // $seoScore = null;
                // $lcp = null;
                // if ($psi_response->successful()) {
                //     $data = $psi_response->json();

                //     // Data nikalna
                //     $performanceScore = $data['lighthouseResult']['categories']['performance']['score'] * 100;
                //     $seoScore = $data['lighthouseResult']['categories']['seo']['score'] * 100;
                    
                //     // Core Web Vitals
                //     $lcp = $data['lighthouseResult']['audits']['largest-contentful-paint']['displayValue'];

                //     // ... baaqi sab metrics bhi yahan se mil jaengi.
                // }
            // new retrive end





    $statusCode   = $response->status();
    $isUp         = ($statusCode >= 200 && $statusCode < 400);
    $finalUrl     = (string) ($response->effectiveUri() ?? $url);
    $contentType  = $response->header('content-type');
    $encoding     = $response->header('content-encoding');
    $html         = (Str::startsWith($contentType, 'text/html')) ? $response->body() : '';
    $htmlBytes    = strlen($html);

    // Approx total asset weight (HEAD on top assets, max 15)
    [$assetBytes, $topAssets] = estimateAssetsWeight($finalUrl, $html, 15, $timeout);

    // SSL info (only for https)
    $sslDaysLeft = null;
    if (Str::startsWith($finalUrl, 'https://')) {
        $sslDaysLeft = getSslDaysLeft(parse_url($finalUrl, PHP_URL_HOST));
    }

    $now = Carbon::now('UTC');
    $nextCheckAt = $now->copy()->addSeconds($durationSeconds);

    return [
        // Status block
        'status'        => $isUp ? 'up' : 'down',
        'status_code'   => $statusCode ?? null,
        'last_checked_at' => $now->toDateTimeString()  ?? null,
        'next_check_at' => $nextCheckAt->toDateTimeString() ?? null,

        // Speed
        'response_time_ms' => $timings['total_ms'] ?? null,
        'ttfb_ms'          => $timings['ttfb_ms']  ?? null,

        // Page size
        'html_bytes'       => $htmlBytes ?? 0,
        'assets_bytes'     => $assetBytes ?? 0,
        'top_assets'       => $topAssets ?? [], // [{url, bytes}] (where available)

        // Security
        'ssl_days_left'    => $sslDaysLeft ?? null,

        // Raw extras
        'final_url'        => $finalUrl ?? '',
        'content_type'     => $contentType ?? '',
        'content_encoding' => $encoding ?? '',

    ];
}

function estimateAssetsWeight(string $baseUrl, string $html, int $limit = 15, int $timeout = 60): array
{
    // 1. Basic check for HTML
    if (empty($html)) {
        // Log::warning('estimateAssetsWeight: HTML is empty.', compact('baseUrl')); // Debugging ke liye log add kar sakte hain
        return [0, []];
    }

    // 2. Asset URLs ko retrieve karo (Assuming extractAssetUrls function is correct)
    $assetUrls = extractAssetUrls($baseUrl, $html);
    
    if (empty($assetUrls)) {
        // Log::info('estimateAssetsWeight: No assets found in HTML.', compact('baseUrl')); // Debugging ke liye
        return [0, []]; // Agar yahan 0 aa raha hai, toh masla extractAssetUrls mein hai.
    }

    $assetUrls = array_slice($assetUrls, 0, $limit);

    $total = 0;
    $top   = [];

    foreach ($assetUrls as $aurl) {
        try {
            $len = 0;

            // Padhai: Head request
            $head = Http::timeout($timeout)->head($aurl);
            $len  = (int) ($head->header('content-length') ?? 0);
            
            // Check 1: Agar HEAD request failed hai (e.g., 404/500), skip karo.
            if ($head->failed() || $head->status() >= 400) {
                 // Log::debug("HEAD failed for asset: {$aurl}. Status: {$head->status()}");
                 continue; // Next asset par jao
            }

            // Check 2: Agar HEAD se length 0 mili, toh lightweight GET try karo.
            if ($len === 0) {
                // Kuch servers HEAD ko block karte hain ya content-length nahi dete.
                $get = Http::timeout($timeout)->withHeaders(['Range' => 'bytes=0-0'])->get($aurl);
                $len = (int) ($get->header('content-length') ?? 0);
                
                // Naya Check: Agar lightweight GET mein 'Content-Range' header ho toh total size nikalen
                $contentRange = $get->header('content-range');
                if ($len === 0 && $contentRange) {
                     // Content-Range: bytes 0-0/12345 (Jahan 12345 total size hai)
                     if (preg_match('/\/\s*(\d+)$/', $contentRange, $matches)) {
                         $len = (int) $matches[1];
                     }
                }
            }
            
            // Final check aur store
            if ($len > 0) {
                $total += $len;
                $top[] = ['url' => $aurl, 'bytes' => $len];
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
             // Connection time-out ya DNS failure, is asset ko ignore karo.
             // Log::error("Connection failed for asset: {$aurl}", ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Doosre errors ko ignore karo.
        }
    }

    // Sort biggest first
    usort($top, fn($a, $b) => $b['bytes'] <=> $a['bytes']);

    return [$total, $top];
}

function extractAssetUrls(string $baseUrl, string $html): array
{
    $urls = [];

    // Quick regex for src/href of common assets
    preg_match_all('/<(img|script)\s[^>]*src=["\']([^"\']+)["\']/i', $html, $m1);
    preg_match_all('/<link\s[^>]*rel=["\'](?:stylesheet|preload)["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m2);

    $candidates = array_merge($m1[2] ?? [], $m2[1] ?? []);
    $candidates = array_unique($candidates);

    // Normalize to absolute
    $base = parse_url($baseUrl);
    $scheme = $base['scheme'] ?? 'https';
    $host   = $base['host']   ?? '';

    foreach ($candidates as $u) {
        if (Str::startsWith($u, '//')) {
            $urls[] = $scheme . ':' . $u;
        } elseif (Str::startsWith($u, ['http://', 'https://'])) {
            $urls[] = $u;
        } elseif (Str::startsWith($u, '/')) {
            $urls[] = $scheme . '://' . $host . $u;
        } else {
            // relative path
            $basePath = rtrim(dirname($base['path'] ?? '/'), '/');
            $urls[] = $scheme . '://' . $host . ($basePath ? $basePath . '/' : '/') . $u;
        }
    }

    return array_values(array_filter($urls));
}

function getSslDaysLeft(?string $host): ?int
{
    if (!$host) return null;

    try {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$client) return null;

        $params = stream_context_get_params($client);
        if (!isset($params['options']['ssl']['peer_certificate'])) return null;

        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (!isset($cert['validTo_time_t'])) return null;

        $days = (int) floor(($cert['validTo_time_t'] - time()) / 86400);
        return $days;
    } catch (\Throwable $e) {
        return null;
    }
}

function myMailSend($to, $name, $subject, $message, $link = null, $data = null){
    $payload = [
        "to"      => $to,
        "subject" => $subject,
        "name"    => $name,
        "message" => $message,
        "link"    => $link,
        "data"    => $data,
        "logo"    => 'https://downnotify.techvince.com/assets/images/logo.png',
        "from"    => 'DownLink',
    ];

    // Send using Guzzle HTTP client
    $client = new \GuzzleHttp\Client([
        'timeout' => 10,
        'verify'  => false, // if you have self‑signed certs
    ]);

    $response = $client->post('https://apluspass.zetdigi.com/form.php', [
        'json' => $payload,
    ]);

    // Optionally check for a successful response (e.g. HTTP 200 + success flag)
    if ($response->getStatusCode() !== 200) {
        // log, rollback, or throw
        throw new Exception('External mail API error: '.$response->getBody());
    }
    return true;
}