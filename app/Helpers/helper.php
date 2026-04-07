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

    $statusCode   = null;
    $finalUrl     = $url;
    $contentType  = null;
    $encoding     = null;
    $html         = '';

    try {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '', // gzip/deflate support
            CURLOPT_AUTOREFERER => true,
            CURLOPT_REFERER => $url,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            CURLOPT_HEADER => true, // 🔥 important (headers + body)
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new \Exception(curl_error($ch));
        }

        $info = curl_getinfo($ch);

        // 🔥 Extract data
        $statusCode  = $info['http_code'] ?? null;
        $headerSize  = $info['header_size'] ?? 0;
        $finalUrl    = $info['url'] ?? $url;

        $headersRaw = substr($response, 0, $headerSize);
        $body       = substr($response, $headerSize);

        // Parse headers
        foreach (explode("\r\n", $headersRaw) as $headerLine) {
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = trim(explode(':', $headerLine, 2)[1]);
            }
            if (stripos($headerLine, 'Content-Encoding:') === 0) {
                $encoding = trim(explode(':', $headerLine, 2)[1]);
            }
        }

        // Timings
        if (isset($info['starttransfer_time'])) {
            $timings['ttfb_ms'] = (int) round($info['starttransfer_time'] * 1000);
        }

        if (isset($info['total_time'])) {
            $timings['total_ms'] = (int) round($info['total_time'] * 1000);
        }

        // Only HTML content
        if ($contentType && str_contains($contentType, 'text/html')) {
            $html = $body;
        }

        curl_close($ch);

    } catch (\Exception $e) {
        return [
            'status' => 'down',
            'response_time_ms' => null,
            'ssl_days_left' => null,
            'html_bytes' => 0,
            'assets_bytes' => 0,
        ];
    }

    // 🔍 Same logic as before (UNCHANGED)
    $isUp      = ($statusCode >= 200 && $statusCode < 400);
    $htmlBytes = strlen($html);

    // Assets
    [$assetBytes, $topAssets] = estimateAssetsWeight($finalUrl, $html, 15, $timeout);

    // SSL
    $sslDaysLeft = null;
    if (str_starts_with($finalUrl, 'https://')) {
        $sslDaysLeft = getSslDaysLeft(parse_url($finalUrl, PHP_URL_HOST));
    }

    $now = \Carbon\Carbon::now('UTC');
    $nextCheckAt = $now->copy()->addSeconds($durationSeconds);

    return [
        // Status
        'status'        => $isUp ? 'up' : 'down',
        'status_code'   => $statusCode ?? null,
        'last_checked_at' => $now->toDateTimeString(),
        'next_check_at' => $nextCheckAt->toDateTimeString(),

        // Speed
        'response_time_ms' => $timings['total_ms'] ?? null,
        'ttfb_ms'          => $timings['ttfb_ms']  ?? null,

        // Size
        'html_bytes'   => $htmlBytes ?? 0,
        'assets_bytes' => $assetBytes ?? 0,
        'top_assets'   => $topAssets ?? [],

        // Security
        'ssl_days_left' => $sslDaysLeft ?? null,

        // Extras
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
        "logo"    => 'https://uptime.techvince.com/assets/images/logo.png',
        "from"    => 'Uptime Monitor',
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