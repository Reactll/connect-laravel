<?php

namespace Reactll\Connect;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reactll\Connect\Jobs\SendOrders;
use Throwable;

/**
 * Reactll Connect for Laravel. Built and maintained by Reactor Technology.
 *
 * The token holds the endpoint, the site's public key and its secret. Server calls are signed:
 * X-Reactll-Signature = hex(HMAC-SHA256(secret, "{timestamp}.{body}")).
 */
class Connect
{
    public const VERSION = '1.3.0';

    public const MODULES = ['visitors', 'events', 'forms', 'sales'];

    /** Orders per request (the contract's maximum). */
    public const BATCH = 50;

    /** Orders (and deletions) kept for the retry at most: the newest win. */
    public const RETRY_MAX = 500;

    private const RETRY_KEY = 'reactll-connect:orders-retry';

    private const LAST_SENT_KEY = 'reactll-connect:orders-last-sent';

    /** @var array{u: string, k: string, s: string}|null|false */
    private array|null|false $credentials = false;

    /** Orders waiting for the end of this request: one send per order, whatever the app called. */
    private array $pending = ['orders' => [], 'deleted' => []];

    private bool $flushRegistered = false;

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

    /** The site owner's switches (config reactll-connect.modules); a module not set counts as on. */
    public function modules(): array
    {
        $config = (array) config('reactll-connect.modules', []);
        $out = [];
        foreach (self::MODULES as $module) {
            $value = $config[$module] ?? true;
            $out[$module] = is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
        }

        return $out;
    }

    public function module(string $name): bool
    {
        return $this->modules()[$name] ?? true;
    }

    /** What this app does, for Reactll: forms when it sends its leads (config sends_leads), sales when the module is on. */
    public function capabilities(): array
    {
        return [
            'forms' => (bool) filter_var(config('reactll-connect.sends_leads', true), FILTER_VALIDATE_BOOL),
            'sales' => $this->module('sales'),
        ];
    }

    /** The script tag for the page head, or '' when not connected or the visitors module is off. */
    public function script(): string
    {
        $c = $this->credentials();
        if (! $c || ! $this->module('visitors')) {
            return '';
        }

        return '<script src="'.e($c['u']).'/c.js" data-site="'.e($c['k']).'"'.($this->module('events') ? '' : ' data-events="off"').' defer></script>';
    }

    /**
     * Send a form your site accepted to Reactll as a lead. Call it after validation and saving,
     * so a rejected form is never a lead. Never throws: false when it could not be sent (true, with nothing
     * sent, when the forms module is switched off — see submit() for the reason).
     *
     * @param  array<string, mixed>  $fields  label => value (passwords and tokens are dropped by Reactll)
     */
    public function submission(string $form, array $fields, ?Request $request = null, ?string $id = null): bool
    {
        return $this->submit($form, $fields, $request, $id)->ok;
    }

    /**
     * submission() with the whole answer: `reason` is `switched_off` when the forms module is off (nothing sent),
     * `unreachable`, `refused`, `host` or `not_connected` when it did not arrive.
     *
     * @param  array<string, mixed>  $fields
     */
    public function submit(string $form, array $fields, ?Request $request = null, ?string $id = null): Result
    {
        if (! $this->module('forms')) {
            return new Result(true, reason: 'switched_off');
        }
        if (! $this->connected()) {
            return Result::notConnected();
        }
        $request ??= request();

        $answer = $this->send('submissions', [
            'id' => $id ?? Str::slug($form).'-'.Str::uuid(),
            'form' => $form,
            'page' => $request?->headers->get('referer') ?: $request?->fullUrl(),
            'pv' => is_string($pv = $request?->input('_rc_pv')) && Str::isUuid($pv) ? $pv : null,
            'submitted_at' => now()->toIso8601String(),
            'fields' => collect($fields)->except(['_token', '_rc_pv', 'password', 'password_confirmation'])
                ->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))
                ->all(),
        ]);
        if ($answer === null) {
            return new Result(false, reason: 'unreachable');
        }
        [$status, $json] = $answer;
        if ($status === 409 && ($json['error'] ?? null) === 'host') {
            return new Result(false, reason: 'host');
        }
        if ($status >= 200 && $status < 300 || $status === 409) {
            return new Result(true, stored: (int) ($json['stored'] ?? ($status === 409 ? 0 : 1)), reason: $json['reason'] ?? null);
        }

        return new Result(false, reason: $status >= 500 || $status === 429 ? 'unreachable' : 'refused');
    }

    /**
     * Like submission(), but sent once the response has gone to the visitor: a slow or unreachable
     * Reactll never holds up your form. Nothing is serialized, so it works with any queue driver.
     *
     * @param  array<string, mixed>  $fields
     */
    public function submitAfterResponse(string $form, array $fields, ?Request $request = null, ?string $id = null): void
    {
        if (! $this->module('forms')) {
            return;
        }
        $request ??= request();
        app()->terminating(fn () => $this->submission($form, $fields, $request, $id));
    }

    // ── Sales & orders (docs: README → Sales & orders) ─────────────────────────────────────────────────

    /**
     * One order (a checkout, a paid invoice, a refund — the same id again with a newer updated_at). Normalised now,
     * sent after the response (or by the queue, config reactll-connect.queue), so it never slows the request.
     * Pass the buyer's `email`: it becomes `customer.key` and is never sent.
     *
     * @param  array<string, mixed>  $order
     */
    public function order(array $order): Result
    {
        return $this->orders([$order]);
    }

    /**
     * Several orders (and order ids removed in the app), sent like order(). `skipped` counts the orders that could
     * not be one (no id, created_at, currency or total) or are checkout drafts; `queued` what will be sent.
     *
     * @param  array<int, array<string, mixed>>  $orders
     * @param  array<int, string|int>  $deleted
     */
    public function orders(array $orders, array $deleted = []): Result
    {
        if (! $this->module('sales')) {
            return Result::switchedOff();
        }
        if (! $this->connected()) {
            return Result::notConnected();
        }
        [$payloads, $skipped] = $this->prepare($orders);
        $deleted = $this->ids($deleted);
        if (! $payloads && ! $deleted) {
            return new Result(true, skipped: $skipped);
        }
        $this->later($payloads, $deleted);

        return new Result(true, skipped: $skipped, reason: 'queued', queued: count($payloads) + count($deleted));
    }

    /**
     * Order ids removed in the app (deleted, or a test order): Reactll deletes them. Sent like order().
     *
     * @param  array<int, string|int>  $ids
     */
    public function deleteOrders(array $ids): Result
    {
        return $this->orders([], $ids);
    }

    /**
     * Send orders now and wait for Reactll's answer (a command, a queued job, an import of past orders with
     * `$backfill`). Batches of 50; what could not arrive waits for the retry.
     *
     * @param  array<int, array<string, mixed>>  $orders
     * @param  array<int, string|int>  $deleted
     */
    public function sendOrders(array $orders, array $deleted = [], bool $backfill = false): Result
    {
        if (! $this->module('sales')) {
            return Result::switchedOff();
        }
        if (! $this->connected()) {
            return Result::notConnected();
        }
        [$payloads, $skipped] = $this->prepare($orders);

        return $this->deliver($payloads, $this->ids($deleted), $backfill, $skipped);
    }

    /**
     * A Stripe Invoice (object or webhook array, no stripe/stripe-php needed) as a contract order, for
     * order(): paid → completed, refunded → refunded, a failed attempt → failed. Null when it is not an invoice.
     *
     * @param  array<string, mixed>|object  $invoice
     * @param  array<string, mixed>  $extra  fields to set or override (source, admin_url, customer.returning…)
     * @return array<string, mixed>|null
     */
    public function fromStripeInvoice(array|object $invoice, array $extra = []): ?array
    {
        return Orders::fromStripeInvoice($invoice, $extra, $this->credentials()['s'] ?? null);
    }

    /** The orders Reactll could not take (down, unreachable): sent again by the heartbeat and Reactll's health call. */
    public function retry(): ?Result
    {
        if (! $this->module('sales') || ! $this->connected()) {
            return null;
        }
        $queue = $this->retryQueue(pull: true);
        if (! $queue['orders'] && ! $queue['deleted']) {
            return null;
        }

        return $this->deliver(array_values($queue['orders']), $queue['deleted']);
    }

    public function hasRetry(): bool
    {
        try {
            return Cache::has(self::RETRY_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Post normalised orders, 50 per request. 409 `host` (a staging copy with the live token) stops everything;
     * any other 409 is Reactll having taken this exact request already (delivered). Down or unreachable: this batch
     * and the rest wait for the retry. Reactll refusing the data for good (400/401/413/422): dropped, not retried.
     *
     * @internal  used by the queued job; call sendOrders()
     *
     * @param  array<int, array<string, mixed>>  $orders  normalised
     * @param  array<int, string>  $deleted
     */
    public function deliver(array $orders, array $deleted, bool $backfill = false, int $skipped = 0): Result
    {
        $t = ['stored' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => $skipped];
        $allowed = null;
        $result = function (bool $ok, ?string $reason = null) use (&$t, &$allowed) {
            return new Result($ok, $t['stored'], $t['updated'], $t['deleted'], $t['skipped'], $allowed, $reason);
        };

        while ($orders || $deleted) {
            $batch = array_splice($orders, 0, self::BATCH);
            $gone = array_splice($deleted, 0, self::BATCH);
            $answer = $this->send('orders', [
                'site_url' => (string) config('app.url'),
                'backfill' => $backfill,
                'orders' => $batch,
                'deleted' => $gone,
            ], 10);

            [$status, $json] = $answer ?? [0, []];
            if ($status === 409 && ($json['error'] ?? null) === 'host') {
                Log::warning('Reactll Connect: orders refused — '.config('app.url').' is not this site\'s address in Reactll (a staging copy with the live token?). Nothing was stored.');

                return $result(false, 'host');
            }
            if ($status === 409) {
                continue; // the same signed request already arrived: delivered
            }
            if ($status >= 200 && $status < 300) {
                foreach (['stored', 'updated', 'deleted', 'skipped'] as $count) {
                    $t[$count] += (int) ($json[$count] ?? 0);
                }
                $this->remember(self::LAST_SENT_KEY, now()->toIso8601String());
                if (array_key_exists('sales_allowed', $json)) {
                    $allowed = (bool) $json['sales_allowed'];
                }
                if (! empty($json['reason'])) {
                    // Switched off or not agreed in Reactll: nothing will be stored, the rest is not sent.
                    return $result(true, (string) $json['reason']);
                }

                continue;
            }
            if ($status === 0 || $status >= 500 || $status === 429) {
                if (! $backfill) {
                    $this->queueRetry(array_merge($batch, $orders), array_merge($gone, $deleted));
                }

                return $result(false, 'unreachable');
            }
            // 400, 401, 413, 422…: Reactll will never take this batch.
            $t['skipped'] += count($batch);

            return $result(false, 'refused');
        }

        return $result(true);
    }

    /** One subscription now (trial started, renewed, cancelled, plan changed). See sendSubscriptions(). */
    public function subscription(array $subscription): Result
    {
        return $this->sendSubscriptions([$subscription]);
    }

    /**
     * Subscriptions now (POST /v1/subscriptions, batches of 50): what MRR, churn and trials are counted from.
     * Idempotent — a repeat with an older or equal updated_at changes nothing, so a scheduled full resend is fine.
     * Unlike orders there is no retry queue: send them from a job or a scheduled command, which reruns.
     *
     * @param  list<array<string, mixed>>  $subscriptions
     * @param  list<string|int>  $deleted  subscription ids removed in the app
     */
    public function sendSubscriptions(array $subscriptions, array $deleted = []): Result
    {
        if (! $this->module('sales')) {
            return Result::switchedOff();
        }
        $secret = $this->credentials()['s'] ?? null;
        if (! $secret) {
            return Result::notConnected();
        }
        $payloads = [];
        $skipped = 0;
        foreach ($subscriptions as $s) {
            $one = is_array($s) ? Subscriptions::normalise($s, $secret) : null;
            if ($one) {
                $payloads[$one['id']] = $one;
            } else {
                $skipped++;
            }
        }
        $payloads = array_values($payloads);
        $deleted = $this->ids($deleted);
        $t = ['stored' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => $skipped];
        $allowed = null;
        $result = function (bool $ok, ?string $reason = null) use (&$t, &$allowed) {
            return new Result($ok, $t['stored'], $t['updated'], $t['deleted'], $t['skipped'], $allowed, $reason);
        };

        while ($payloads || $deleted) {
            $batch = array_splice($payloads, 0, self::BATCH);
            $gone = array_splice($deleted, 0, self::BATCH);
            [$status, $json] = $this->send('subscriptions', ['site_url' => (string) config('app.url'), 'subscriptions' => $batch, 'deleted' => $gone], 10) ?? [0, []];
            if ($status === 409 && ($json['error'] ?? null) === 'host') {
                return $result(false, 'host');
            }
            if ($status === 409) {
                continue; // the same signed request already arrived
            }
            if ($status >= 200 && $status < 300) {
                foreach (['stored', 'updated', 'deleted', 'skipped'] as $count) {
                    $t[$count] += (int) ($json[$count] ?? 0);
                }
                if (array_key_exists('sales_allowed', $json)) {
                    $allowed = (bool) $json['sales_allowed'];
                }
                if (! empty($json['reason'])) {
                    return $result(true, (string) $json['reason']);
                }

                continue;
            }

            return $result(false, $status === 0 || $status >= 500 || $status === 429 ? 'unreachable' : 'refused');
        }

        return $result(true);
    }

    /** A Stripe Subscription as a contract subscription (pass plan / email in $extra when the object only has ids). */
    public function fromStripeSubscription(array|object $subscription, array $extra = []): ?array
    {
        return Subscriptions::fromStripe($subscription, $extra, $this->credentials()['s'] ?? null);
    }

    /** What this app runs: sent by reactll:heartbeat, and answered when Reactll asks (/api/reactll/health). */
    public function healthPayload(): array
    {
        $capabilities = $this->capabilities();

        return array_filter([
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
            'modules' => $this->modules(),
            'capabilities' => $capabilities,
            // An app has no past orders to import for Reactll: it sends what happens from now on.
            'sales' => $capabilities['sales'] ? ['backfill' => 'none', 'last_sent_at' => $this->recall(self::LAST_SENT_KEY)] : null,
        ], fn ($v) => $v !== null);
    }

    /** Versions and settings (reactll:heartbeat, if the app runs a scheduler). Returns Reactll's answer. */
    public function heartbeat(): ?array
    {
        return $this->post('heartbeat', $this->healthPayload(), 10);
    }

    /** @return array<string, mixed>|null */
    public function post(string $path, array $payload, ?int $timeout = null): ?array
    {
        $answer = $this->send($path, $payload, $timeout);
        if ($answer === null) {
            return null;
        }
        [$status, $json] = $answer;

        return ($status >= 200 && $status < 300) || $status === 409 ? $json : null; // a refusal is logged by send()
    }

    /**
     * One signed request. Null when it never got an answer (not connected, network error).
     *
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    protected function send(string $path, array $payload, ?int $timeout = null): ?array
    {
        $c = $this->credentials();
        if (! $c) {
            return null;
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

            if (! $response->successful() && $response->status() !== 409) {
                Log::warning('Reactll Connect: '.$path.' refused', ['status' => $response->status(), 'error' => $response->json('error')]);
            }

            return [$response->status(), (array) $response->json()];
        } catch (Throwable $e) {
            Log::warning('Reactll Connect: '.$path.' failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** True while a visitor waits for this response: orders are then sent after it (or by the queue). */
    protected function inRequest(): bool
    {
        return ! app()->runningInConsole();
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: int} normalised orders (one per id, the last wins) and how many were skipped */
    private function prepare(array $orders): array
    {
        $secret = $this->credentials()['s'] ?? null;
        $out = [];
        $skipped = 0;
        foreach ($orders as $order) {
            $normal = is_array($order) ? Orders::normalise($order, $secret) : null;
            if ($normal === null) {
                Log::warning('Reactll Connect: an order without id, created_at, currency or total was not sent', ['id' => is_array($order) ? ($order['id'] ?? null) : null]);
                $skipped++;
            } elseif ($normal['status'] === 'checkout-draft') {
                $skipped++;
            } else {
                $out[$normal['id']] = $normal;
            }
        }

        return [array_values($out), $skipped];
    }

    /** @return array<int, string> */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($id) => is_scalar($id) ? mb_substr(trim((string) $id), 0, 100) : '', $ids), 'strlen')));
    }

    private function later(array $orders, array $deleted): void
    {
        $queue = config('reactll-connect.queue', false);
        $queue = is_string($queue) && ($flag = filter_var($queue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)) !== null ? $flag : $queue;

        if ($queue !== false && $queue !== null && $queue !== '') {
            $job = new SendOrders($orders, $deleted);
            if (is_string($queue)) {
                $job->onConnection($queue);
            }
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);

            return;
        }
        if (! $this->inRequest()) {
            // A command or a queue worker: nobody is waiting for a response.
            $this->deliver($orders, $deleted);

            return;
        }

        foreach ($orders as $order) {
            $this->pending['orders'][$order['id']] = $order;
        }
        $this->pending['deleted'] = array_values(array_unique(array_merge($this->pending['deleted'], $deleted)));
        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(function () {
                $pending = $this->pending;
                $this->pending = ['orders' => [], 'deleted' => []];
                $this->flushRegistered = false;
                $this->deliver(array_values($pending['orders']), $pending['deleted']);
            });
        }
    }

    /** @return array{orders: array<string, array<string, mixed>>, deleted: array<int, string>} */
    private function retryQueue(bool $pull = false): array
    {
        try {
            $queue = $pull ? Cache::pull(self::RETRY_KEY) : Cache::get(self::RETRY_KEY);
        } catch (Throwable) {
            $queue = null;
        }

        return ['orders' => (array) ($queue['orders'] ?? []), 'deleted' => (array) ($queue['deleted'] ?? [])];
    }

    private function queueRetry(array $orders, array $deleted): void
    {
        $queue = $this->retryQueue();
        foreach ($orders as $order) {
            unset($queue['orders'][$order['id']]);
            $queue['orders'][$order['id']] = $order;
        }
        $queue['orders'] = array_slice($queue['orders'], -self::RETRY_MAX, null, true);
        $queue['deleted'] = array_slice(array_values(array_unique(array_merge($queue['deleted'], $deleted))), -self::RETRY_MAX);

        try {
            Cache::put(self::RETRY_KEY, $queue, now()->addDays(7));
        } catch (Throwable $e) {
            Log::warning('Reactll Connect: orders could not be kept for the retry', ['error' => $e->getMessage()]);
        }
    }

    private function remember(string $key, string $value): void
    {
        try {
            Cache::forever($key, $value);
        } catch (Throwable) {
            // no cache: nothing to report
        }
    }

    private function recall(string $key): ?string
    {
        try {
            $value = Cache::get($key);

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
