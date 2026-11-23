<?php
declare(strict_types=1);

final class RemoteFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false, public readonly int $status = 0) { parent::__construct($message); }
}
final class WorkerBudgetExpired extends RuntimeException {}

class Http
{
    private ?float $deadline=null;
    public function deadline(?float $deadline): void { $this->deadline=$deadline; }
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $remaining=$this->deadline===null?21:(int)floor($this->deadline-microtime(true));
        if ($remaining<2) throw new WorkerBudgetExpired('Publiceringsjobbet fortsätter vid nästa cron-körning.');
        $timeout=min(20,$remaining-1);
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $allowed = ['accounts.google.com','oauth2.googleapis.com','www.googleapis.com','upload.googleapis.com','content.googleapis.com','graph.facebook.com','graph-video.facebook.com'];
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, $allowed, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) throw new RemoteFailure('Plattformen returnerade en otillåten API-adress.');
        $ch = curl_init($url); $responseHeaders = []; $bytes = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(8,$timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders): int { if (str_starts_with($line, 'HTTP/')) $responseHeaders = []; if (str_contains($line, ':')) { [$key,$value] = explode(':', $line, 2); $responseHeaders[strtolower(trim($key))] = trim($value); } return strlen($line); },
            CURLOPT_WRITEFUNCTION => static function ($curl, $data) use (&$bytes): int { if (strlen($bytes) + strlen($data) > 2097152) return 0; $bytes .= $data; return strlen($data); },
            CURLOPT_USERAGENT => 'Publicera/0.1',
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($ok === false) throw new RemoteFailure('Anslutningen till plattformen avbröts. Ingen automatisk ny publicering görs vid okänt resultat.', $method !== 'GET');
        $json = $bytes !== '' ? json_decode($bytes, true) : [];
        if ($status >= 400) {
            $error = is_array($json) ? ($json['error']['message'] ?? $json['error_description'] ?? $json['error'] ?? '') : '';
            if (!is_string($error)) $error = '';
            throw new RemoteFailure('Plattformen svarade HTTP ' . $status . ($error !== '' ? ': ' . mb_substr($error, 0, 500) : '.'), $method !== 'GET' && $status >= 500, $status);
        }
        if (!is_array($json) && !in_array($status, [204,308], true)) throw new RemoteFailure('Plattformens svar gick inte att läsa.', $method !== 'GET', $status);
        return ['status' => $status, 'headers' => $responseHeaders, 'json' => is_array($json) ? $json : []];
    }
    public function form(string $url, array $data, array $headers = []): array { return $this->request('POST', $url, array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers), http_build_query($data)); }
}
