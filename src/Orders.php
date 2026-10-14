<?php

namespace Reactll\Connect;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Orders as the Reactll contract has them (POST /v1/orders) — and nothing more. Personal data is minimal by contract:
 * a label ("Maria L."), city, country and a key (HMAC-SHA256 of the lowercased email with the site's secret). The email
 * itself, phone, street address, postcode and IP never leave the app: only the listed fields are copied.
 */
final class Orders
{
    /** ISO 4217 currencies without minor units, and with three. Every other currency has two. */
    private const ZERO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMALS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** Stripe's own zero-decimal list (amounts are not in cents) — ISK and UGX are sent in hundredths there. */
    private const STRIPE_ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const STRIPE_THREE = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    private const MONEY = ['total', 'subtotal', 'tax', 'shipping', 'discount', 'refunded'];

    /**
     * One order the app passed, as the contract has it; null when it cannot be one (no id, no created_at, no
     * currency or no total). Pass the buyer's `email` (top level or in `customer`): it becomes `customer.key` and is
     * never sent. No email (Apple / TikTok sign-in): pass `customer_id` (or `user_id`) instead — hashed the same way
     * with an "id:" prefix, never sent either; the email wins when both are given. Accepts `customer.name` or `first_name` / `last_name` for the label. Unknown keys are dropped.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    public static function normalise(array $order, ?string $secret): ?array
    {
        $id = self::text($order['id'] ?? null, 100);
        $currency = strtoupper(trim((string) ($order['currency'] ?? '')));
        $created = self::date($order['created_at'] ?? null);
        if ($id === null || ! preg_match('/^[A-Z]{3}$/', $currency) || $created === null || self::money($order['total'] ?? null, $currency) === null) {
            return null;
        }

        $money = [];
        foreach (self::MONEY as $field) {
            $money[$field] = self::money($order[$field] ?? null, $currency) ?? ($field === 'subtotal' ? self::money($order['total'], $currency) : self::money(0, $currency));
        }
        $money['refunded'] = ltrim($money['refunded'], '-');

        $status = strtolower(trim((string) ($order['status'] ?? 'completed')));
        $status = substr(str_starts_with($status, 'wc-') ? substr($status, 3) : $status, 0, 30) ?: 'completed';

        $items = [];
        foreach (array_values(is_array($order['items'] ?? null) ? $order['items'] : []) as $item) {
            if (count($items) >= 50) {
                break;
            }
            $item = is_array($item) ? $item : [];
            $name = self::text($item['name'] ?? null, 190);
            if ($name === null) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'quantity' => max(-100000, min(100000, (int) ($item['quantity'] ?? 1))),
                'total' => self::money($item['total'] ?? null, $currency) ?? self::money(0, $currency),
                'sku' => self::text($item['sku'] ?? null, 100),
                'product_id' => self::text($item['product_id'] ?? null, 50),
            ];
        }

        $source = is_array($order['source'] ?? null) ? $order['source'] : [];
        $referrer = self::text($source['referrer'] ?? null, 2000);
        if ($referrer !== null && str_contains($referrer, '/')) {
            $referrer = parse_url(str_contains($referrer, '://') ? $referrer : 'https://'.ltrim($referrer, '/'), PHP_URL_HOST) ?: null;
        }
        $source = [
            'type' => self::text($source['type'] ?? null, 30),
            'source' => self::text($source['source'] ?? $source['utm_source'] ?? null, 190),
            'medium' => self::text($source['medium'] ?? $source['utm_medium'] ?? null, 100),
            'campaign' => self::text($source['campaign'] ?? $source['utm_campaign'] ?? null, 190),
            'referrer' => self::text($referrer, 190),
            'device' => self::text($source['device'] ?? null, 50),
        ];

        $visit = strtolower(trim((string) ($order['visit'] ?? '')));
        $admin = trim((string) ($order['admin_url'] ?? ''));

        return [
            'id' => $id,
            'number' => self::text($order['number'] ?? null, 50) ?? substr($id, 0, 50),
            'status' => $status,
            'currency' => $currency,
            ...$money,
            'payment_method' => self::text($order['payment_method'] ?? null, 190),
            'created_via' => self::text($order['created_via'] ?? null, 50) ?? 'checkout',
            'created_at' => $created,
            'paid_at' => self::date($order['paid_at'] ?? null),
            'completed_at' => self::date($order['completed_at'] ?? null),
            'updated_at' => self::date($order['updated_at'] ?? null) ?? (new DateTimeImmutable('now', self::zone()))->format(DATE_ATOM),
            'customer' => self::customer($order, $secret),
            'items' => $items,
            'source' => array_filter($source, fn ($v) => $v !== null) ? $source : null,
            'visit' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $visit) ? $visit : null,
            'admin_url' => strlen($admin) <= 2000 && preg_match('#^https?://#i', $admin) && filter_var($admin, FILTER_VALIDATE_URL) ? $admin : null,
        ];
    }

    /** The customer key: hex HMAC-SHA256 of the lowercased, trimmed email with the site's secret. */
    public static function customerKey(?string $email, ?string $secret): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && $secret ? hash_hmac('sha256', $email, $secret) : null;
    }

    /** Without an email (Apple / TikTok sign-in): hex HMAC-SHA256 of "id:" + the lowercased, trimmed customer id. */
    public static function customerIdKey(?string $id, ?string $secret): ?string
    {
        $id = strtolower(trim((string) $id));

        return $id !== '' && $secret ? hash_hmac('sha256', 'id:'.$id, $secret) : null;
    }

    /** @return array{label: string|null, city: string|null, country: string|null, key: string|null, returning: bool|null} */
    private static function customer(array $order, ?string $secret): array
    {
        $c = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $email = $c['email'] ?? $order['email'] ?? $order['customer_email'] ?? null;
        $userId = $c['customer_id'] ?? $c['user_id'] ?? $order['customer_id'] ?? $order['user_id'] ?? null;
        $given = strtolower(trim((string) ($c['key'] ?? '')));
        $country = strtoupper(trim((string) ($c['country'] ?? '')));
        $returning = $c['returning'] ?? null;

        return [
            'label' => self::label($c),
            'city' => self::text($c['city'] ?? null, 100),
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
            'key' => self::customerKey(is_string($email) ? $email : null, $secret)
                ?? self::customerIdKey(is_scalar($userId) ? (string) $userId : null, $secret)
                ?? (preg_match('/^[0-9a-f]{64}$/', $given) ? $given : null),
            'returning' => $returning === null ? null : (bool) $returning,
        ];
    }

    /** First name + initial of the last name ("Maria L."), from first_name / last_name, name or label. */
    private static function label(array $c): ?string
    {
        $first = self::text($c['first_name'] ?? null, 60);
        $last = self::text($c['last_name'] ?? null, 60);
        if ($first === null && $last === null) {
            $words = preg_split('/\s+/u', (string) self::text($c['name'] ?? $c['label'] ?? null, 190), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $first = isset($words[0]) ? mb_substr($words[0], 0, 60) : null;
            $last = count($words) > 1 ? end($words) : null;
        }
        if ($first === null) {
            return null;
        }
        $initial = $last !== null ? mb_strtoupper(mb_substr(ltrim($last, '.'), 0, 1)) : '';

        return mb_strtoupper(mb_substr($first, 0, 1)).mb_substr($first, 1).($initial !== '' ? ' '.$initial.'.' : '');
    }

    /**
     * A Stripe Invoice (an object from stripe/stripe-php, a webhook's `data.object` array or a decoded stdClass) as
     * a contract order: one order per invoice (`id` = the invoice id), `created_via: subscription` for subscription
     * invoices, the invoice lines as items, amounts from the smallest unit. Paid → completed, refunded (credit notes
     * after payment or an expanded charge) → refunded, a failed attempt → failed. `$extra` overrides any field
     * (`source`, `admin_url`, `customer.returning`…).
     *
     * @param  array<string, mixed>|object  $invoice
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    public static function fromStripeInvoice(array|object $invoice, array $extra, ?string $secret): ?array
    {
        $i = self::toArray($invoice);
        $currency = strtoupper((string) ($i['currency'] ?? ''));
        $amount = fn ($v) => is_numeric($v) ? self::fromSmallest((int) $v, $currency) : null;
        $sum = fn ($rows) => array_sum(array_map(fn ($r) => (int) (is_array($r) ? ($r['amount'] ?? 0) : 0), is_array($rows) ? $rows : []));

        $paid = ($i['status'] ?? null) === 'paid' || ! empty($i['paid']);
        $total = (int) ($paid ? ($i['amount_paid'] ?? $i['total'] ?? 0) : ($i['total'] ?? $i['amount_due'] ?? 0));
        $charge = is_array($i['charge'] ?? null) ? $i['charge'] : [];
        $refunded = max((int) ($i['post_payment_credit_notes_amount'] ?? 0), (int) ($charge['amount_refunded'] ?? 0));
        $tax = isset($i['tax']) ? (int) $i['tax'] : $sum($i['total_taxes'] ?? $i['total_tax_amounts'] ?? []);
        $discount = $sum($i['total_discount_amounts'] ?? []);

        $status = match (true) {
            $paid && $refunded > 0 && $refunded >= $total => 'refunded',
            $paid => 'completed',
            ($i['status'] ?? null) === 'void' => 'cancelled',
            ($i['status'] ?? null) === 'uncollectible', (int) ($i['attempt_count'] ?? 0) > 0 || ! empty($i['last_finalization_error']) => 'failed',
            default => 'pending',
        };

        $lines = $i['lines']['data'] ?? $i['lines'] ?? [];
        $items = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $items[] = [
                'name' => $line['description'] ?? $line['price']['nickname'] ?? $line['plan']['nickname'] ?? 'Invoice line',
                'quantity' => (int) ($line['quantity'] ?? 1),
                'total' => $amount($line['amount'] ?? 0),
                'product_id' => is_string($p = $line['price']['product'] ?? $line['pricing']['price_details']['product'] ?? null) ? $p : null,
            ];
        }

        $reason = (string) ($i['billing_reason'] ?? '');
        $subscription = ! empty($i['subscription']) || ! empty($i['parent']['subscription_details']) || str_starts_with($reason, 'subscription');
        $transitions = is_array($i['status_transitions'] ?? null) ? $i['status_transitions'] : [];
        $address = is_array($i['customer_address'] ?? null) ? $i['customer_address'] : [];
        $live = (bool) ($i['livemode'] ?? true);

        $order = [
            'id' => $i['id'] ?? null,
            'number' => $i['number'] ?? null,
            'status' => $status,
            'currency' => $currency,
            'total' => $amount($total),
            'subtotal' => $amount($i['subtotal'] ?? $total),
            'tax' => $amount($tax),
            'shipping' => $amount(0),
            'discount' => $amount($discount),
            'refunded' => $amount($refunded),
            'payment_method' => 'Stripe',
            'created_via' => $subscription ? 'subscription' : 'invoice',
            'created_at' => $i['created'] ?? null,
            'paid_at' => $transitions['paid_at'] ?? null,
            'completed_at' => null,
            'updated_at' => null, // now: a later webhook (refund, retry) always replaces an earlier one
            'customer' => [
                'name' => $i['customer_name'] ?? null,
                'email' => $i['customer_email'] ?? null,
                'city' => $address['city'] ?? null,
                'country' => $address['country'] ?? null,
                'returning' => match ($reason) {
                    'subscription_cycle' => true,
                    'subscription_create' => false,
                    default => null,
                },
            ],
            'items' => $items,
            'source' => null,
            'admin_url' => isset($i['id']) ? 'https://dashboard.stripe.com/'.($live ? '' : 'test/').'invoices/'.$i['id'] : null,
        ];

        foreach ($extra as $key => $value) {
            $order[$key] = in_array($key, ['customer', 'source'], true) && is_array($value) && is_array($order[$key] ?? null)
                ? array_replace($order[$key], $value)
                : $value;
        }

        return self::normalise($order, $secret);
    }

    /** @return array<string, mixed> */
    private static function toArray(array|object $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (method_exists($value, 'toArray')) {
            return (array) $value->toArray();
        }

        return (array) json_decode((string) json_encode($value), true);
    }

    private static function decimals(string $currency): int
    {
        return in_array($currency, self::ZERO_DECIMALS, true) ? 0 : (in_array($currency, self::THREE_DECIMALS, true) ? 3 : 2);
    }

    private static function fromSmallest(int $amount, string $currency): string
    {
        $divisor = in_array($currency, self::STRIPE_ZERO, true) ? 1 : (in_array($currency, self::STRIPE_THREE, true) ? 1000 : 100);

        return number_format($amount / $divisor, self::decimals($currency), '.', '');
    }

    /** A decimal string in the currency's minor units ("86.40"), or null when it is not a number. */
    private static function money(mixed $value, string $currency): ?string
    {
        if (is_string($value)) {
            $value = trim(str_replace([',', ' '], ['', ''], $value));
        }
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }
        $out = number_format(round((float) $value, self::decimals($currency)), self::decimals($currency), '.', '');

        return $out === '-0' || preg_match('/^-0\.0+$/', $out) ? ltrim($out, '-') : $out;
    }

    /** ISO 8601 with offset, from Carbon / DateTime, a unix timestamp or any date string (read on the app's clock). */
    private static function date(mixed $value): ?string
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }
            if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit(trim($value)))) {
                $ts = (int) $value;
                $ts = $ts > 100000000000 ? intdiv($ts, 1000) : $ts; // milliseconds

                return $ts > 0 ? (new DateTimeImmutable('@'.$ts))->setTimezone(self::zone())->format(DATE_ATOM) : null;
            }
            if (is_string($value) && trim($value) !== '') {
                return (new DateTimeImmutable(trim($value), self::zone()))->format(DATE_ATOM);
            }
        } catch (Throwable) {
            // not a date
        }

        return null;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
