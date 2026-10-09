<?php

namespace Reactll\Connect;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reactll Connect for Laravel. Built and maintained by Reactor Technology.
 *
 * The token holds the endpoint, the site's public key and its secret. Server calls are signed:
 * X-Reactll-Signature = hex(HMAC-SHA256(secret, "{timestamp}.{body}")).
 */
class Connect
{
    public const VERSION = '1.1.1';

    /** @var array{u: string, k: string, s: string}|null|false */
    private array|null|false $credentials = false;

    public function __construct(private ?string $token) {}

    /** @return array{u: string, k: string, s: string}|null */
    public function credentials(): ?array
    {
        if ($this->credentials !== false) {
            return $this->credentials;
        }
        $token = trim((string) $this->token);
        $json = str_starts_with($token, 'rcc_') ? base64_decode(strtr(substr($token, 4), '-_', '+/'), true) : false;
        $data = $json ? json_decode($json, true) : null;

        return $this->credentials = is_array($data) && ! empty($data['u']) && ! empty($data['k']) && ! empty($data['s'])
            ? ['u' => rtrim($data['u'], '/'), 'k' => $data['k'], 's' => $data['s']]
            : null;
    }

    public function connected(): bool
    {
        return $this->credentials() !== null;
    }

    /** The script tag for the page head, or '' when not connected. */
    public function script(): string
    {
        $c = $this->credentials();

        return $c ? '<script src="'.e($c['u']).'/c.js" data-site="'.e($c['k']).'" defer></script>' : '';
    }

    /**
     * Send a form your site accepted to Reactll as a lead. Call it after validation and saving,
     * so a rejected form is never a lead. Never throws: false when it could not be sent.
     *
     * @param  array<string, mixed>  $fields  label => value (passwords and tokens are dropped by Reactll)
     */
    public function submission(string $form, array $fields, ?Request $request = null, ?string $id = null): bool
    {
        $request ??= request();

        return $this->post('submissions', [
            'id' => $id ?? Str::slug($form).'-'.Str::uuid(),
            'form' => $form,
            'page' => $request?->headers->get('referer') ?: $request?->fullUrl(),
            'pv' => is_string($pv = $request?->input('_rc_pv')) && Str::isUuid($pv) ? $pv : null,
            'submitted_at' => now()->toIso8601String(),
            'fields' => collect($fields)->except(['_token', '_rc_pv', 'password', 'password_confirmation'])
                ->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))
                ->all(),
        ]) !== null;
    }

    /**
     * Like submission(), but sent once the response has gone to the visitor: a slow or unreachable
     * Reactll never holds up your form. Nothing is serialized, so it works with any queue driver.
     *
     * @param  array<string, mixed>  $fields
     */
    public function submitAfterResponse(string $form, array $fields, ?Request $request = null, ?string $id = null): void
    {
        $request ??= request();
        app()->terminating(fn () => $this->submission($form, $fields, $request, $id));
    }

    /** What this app runs: sent by reactll:heartbeat, and answered when Reactll asks (/api/reactll/health). */
    public function healthPayload(): array
    {
        return [
            'platform' => 'laravel',
            'version' => self::VERSION,
            'site_url' => config('app.url'),
            'runtime' => array_filter([
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'environment' => app()->environment(),
                'queue' => config('queue.default'),
                'cache' => config('cache.default'),
                'database' => config('database.default'),
                // Reactll counts the site's days on this clock (UTC is read as "not set").
                'timezone' => config('app.timezone'),
            ]),
        ];
    }

    /** Versions and settings (reactll:heartbeat, if the app runs a scheduler). Returns Reactll's answer. */
    public function heartbeat(): ?array
    {
        return $this->post('heartbeat', $this->healthPayload(), 10);
    }

    /** @return array<string, mixed>|null */
    public function post(string $path, array $payload, ?int $timeout = null): ?array
    {
        $c = $this->credentials();
        if (! $c) {
            return null;
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ts = (string) time();
            $response = Http::timeout($timeout ?? (int) config('reactll-connect.timeout', 3))
                ->withHeaders([
                    'X-Reactll-Key' => $c['k'],
                    'X-Reactll-Timestamp' => $ts,
                    'X-Reactll-Signature' => hash_hmac('sha256', $ts.'.'.$body, $c['s']),
                    'Accept' => 'application/json',
                    'User-Agent' => 'ReactllConnect/'.self::VERSION.' Laravel/'.app()->version(),
                ])
                ->withBody($body, 'application/json')
                ->post($c['u'].'/'.ltrim($path, '/'));

            if ($response->successful() || $response->status() === 409) {
                return (array) $response->json();
            }
            Log::warning('Reactll Connect: '.$path.' refused', ['status' => $response->status(), 'error' => $response->json('error')]);
        } catch (Throwable $e) {
            Log::warning('Reactll Connect: '.$path.' failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
