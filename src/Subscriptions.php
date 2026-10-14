<?php

namespace Reactll\Connect;

/**
 * Subscriptions as the Reactll contract has them (POST /v1/subscriptions, or `subscription` on an order): what HQ
 * counts MRR, churn and trials from. The same minimal personal data as orders: a label, a country and a one-way key
 * (from `email`, else `customer_id` / `user_id`) — never the email or the id itself.
 */
final class Subscriptions
{
    public const STATUSES = ['trialing', 'active', 'past_due', 'paused', 'canceled'];

    public const INTERVALS = ['day', 'week', 'month', 'year'];

    /**
     * One subscription the app passed, as the contract has it; null when it cannot be one (no id, an unknown status or
     * interval, no currency or no amount). `amount` is the recurring price per interval after discounts, excl. tax.
     * Status aliases: cancelled / ended / expired → canceled, unpaid / incomplete → past_due, trial → trialing.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>|null
     */
    public static function normalise(array $s, ?string $secret): ?array
    {
        $id = Orders::text($s['id'] ?? null, 190);
        $currency = strtoupper(trim((string) ($s['currency'] ?? '')));
        $status = strtolower(trim((string) ($s['status'] ?? 'active')));
        $status = match ($status) {
            'cancelled', 'ended', 'expired', 'incomplete_expired' => 'canceled',
            'unpaid', 'incomplete' => 'past_due',
            'trial' => 'trialing',
            default => $status,
        };
        $interval = strtolower(trim((string) ($s['interval'] ?? 'month')));
        $interval = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year', 'annual' => 'year', 'annually' => 'year'][$interval] ?? $interval;
        $amount = preg_match('/^[A-Z]{3}$/', $currency) ? Orders::money($s['amount'] ?? null, $currency) : null;
        if ($id === null || $amount === null || ! in_array($status, self::STATUSES, true) || ! in_array($interval, self::INTERVALS, true)) {
            return null;
        }
        $customer = Orders::customer($s, $secret);

        return [
            'id' => $id,
            'plan' => Orders::text($s['plan'] ?? null, 190),
            'status' => $status,
            'currency' => $currency,
            'amount' => ltrim($amount, '-'),
            'interval' => $interval,
            'interval_count' => max(1, min(365, (int) ($s['interval_count'] ?? 1))),
            'quantity' => max(1, min(100000, (int) ($s['quantity'] ?? 1))),
            'started_at' => Orders::date($s['started_at'] ?? $s['created_at'] ?? null),
            'trial_ends_at' => Orders::date($s['trial_ends_at'] ?? null),
            'current_period_end' => Orders::date($s['current_period_end'] ?? null),
            'canceled_at' => Orders::date($s['canceled_at'] ?? null),
            'ends_at' => Orders::date($s['ends_at'] ?? null),
            'updated_at' => Orders::date($s['updated_at'] ?? null) ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'customer' => ['label' => $customer['label'], 'country' => $customer['country'], 'key' => $customer['key']],
        ];
    }

    /**
     * A Stripe Subscription (object or webhook array; no stripe/stripe-php needed) as a contract subscription.
     * Plan names and the customer usually are ids on the object: pass them in $extra (plan, email, customer).
     *
     * @param  array<string, mixed>|object  $subscription
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    public static function fromStripe(array|object $subscription, array $extra, ?string $secret): ?array
    {
        $s = json_decode(json_encode($subscription), true) ?: [];
        if (($s['object'] ?? 'subscription') !== 'subscription' || empty($s['id'])) {
            return null;
        }
        $item = $s['items']['data'][0] ?? [];
        $price = $item['price'] ?? $item['plan'] ?? [];
        $recurring = $price['recurring'] ?? $price;
        $currency = strtoupper((string) ($price['currency'] ?? $s['currency'] ?? ''));
        $unit = isset($price['unit_amount']) ? (int) $price['unit_amount'] : (isset($price['amount']) ? (int) $price['amount'] : null);
        $at = fn ($v) => is_numeric($v) && (int) $v > 0 ? gmdate(DATE_ATOM, (int) $v) : null;
        $ended = $s['ended_at'] ?? null;

        return self::normalise(array_replace([
            'id' => $s['id'],
            'plan' => $price['nickname'] ?? (is_string($price['product'] ?? null) ? null : ($price['product']['name'] ?? null)),
            'status' => $s['status'] ?? 'active',
            'currency' => $currency,
            'amount' => $unit !== null ? Orders::fromSmallest($unit, $currency) : null,
            'interval' => $recurring['interval'] ?? 'month',
            'interval_count' => $recurring['interval_count'] ?? 1,
            'quantity' => $item['quantity'] ?? $s['quantity'] ?? 1,
            'started_at' => $at($s['start_date'] ?? $s['created'] ?? null),
            'trial_ends_at' => $at($s['trial_end'] ?? null),
            'current_period_end' => $at($item['current_period_end'] ?? $s['current_period_end'] ?? null),
            'canceled_at' => $at($s['canceled_at'] ?? null),
            // Cancelled at period end: access (and MRR) runs to cancel_at; ended: it stopped then.
            'ends_at' => $at($ended ?? $s['cancel_at'] ?? null),
            'updated_at' => gmdate(DATE_ATOM),
        ], $extra), $secret);
    }
}
