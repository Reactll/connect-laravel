<?php

namespace Reactll\Connect\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Reactll\Connect\Facades\Connect as ReactllConnect;
use Reactll\Connect\Subscriptions;

class SubscriptionsTest extends TestCase
{
    private function sub(array $override = []): array
    {
        return array_replace([
            'id' => 'sub_1', 'plan' => 'Pro · Monthly', 'status' => 'active', 'currency' => 'usd', 'amount' => 9.99,
            'interval' => 'monthly', 'started_at' => Carbon::parse('2026-09-01 10:00', 'UTC'), 'updated_at' => Carbon::parse('2026-10-01 10:00', 'UTC'),
            'email' => ' Ana@Example.TEST ', 'customer' => ['name' => 'ana ruiz', 'country' => 'us', 'phone' => '555'],
        ], $override);
    }

    public function test_a_subscription_is_normalised_and_the_email_never_leaves_the_app(): void
    {
        $s = Subscriptions::normalise($this->sub(), 'secret');
        $this->assertSame(['sub_1', 'active', 'USD', '9.99', 'month', 1, 1], [$s['id'], $s['status'], $s['currency'], $s['amount'], $s['interval'], $s['interval_count'], $s['quantity']]);
        $this->assertSame(['label' => 'Ana R.', 'country' => 'US', 'key' => hash_hmac('sha256', 'ana@example.test', 'secret')], $s['customer']);
        $this->assertStringNotContainsString('Example', json_encode($s));
        $this->assertStringNotContainsString('555', json_encode($s));
        $this->assertSame('canceled', Subscriptions::normalise($this->sub(['status' => 'cancelled']), 'secret')['status']);
        $this->assertSame('past_due', Subscriptions::normalise($this->sub(['status' => 'unpaid']), 'secret')['status']);
        $this->assertNull(Subscriptions::normalise($this->sub(['status' => 'weird']), 'secret'));
        $this->assertNull(Subscriptions::normalise($this->sub(['amount' => null]), 'secret'));
        $noEmail = Subscriptions::normalise($this->sub(['email' => null, 'customer_id' => 'User-7']), 'secret');
        $this->assertSame(hash_hmac('sha256', 'id:user-7', 'secret'), $noEmail['customer']['key']);
    }

    public function test_subscriptions_are_signed_and_posted_and_an_order_can_carry_one(): void
    {
        Http::fake(['*/subscriptions' => Http::response(['ok' => true, 'stored' => 1, 'updated' => 0, 'skipped' => 0, 'sales_allowed' => true]),
            '*/orders' => Http::response(['ok' => true, 'stored' => 1, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'sales_allowed' => true])]);
        $result = ReactllConnect::sendSubscriptions([$this->sub(), ['id' => 'bad']]);
        $this->assertTrue($result->ok);
        Http::assertSentCount(1);
        $this->assertSame([1, 1], [$result->stored, $result->skipped], 'the invalid one is skipped, not sent');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions') && $r->hasHeader('X-Reactll-Signature')
            && count($r['subscriptions']) === 1 && $r['subscriptions'][0]['id'] === 'sub_1');

        ReactllConnect::sendOrders([['id' => 'in_1', 'currency' => 'USD', 'total' => 9.99, 'created_at' => now(), 'created_via' => 'subscription', 'email' => 'ana@example.test',
            'subscription' => ['id' => 'sub_1', 'status' => 'active', 'amount' => 9.99, 'interval' => 'month', 'updated_at' => now()]]]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/orders') && ($r['orders'][0]['subscription']['id'] ?? null) === 'sub_1'
            && $r['orders'][0]['subscription']['currency'] === 'USD' && $r['orders'][0]['subscription']['customer']['key'] === $r['orders'][0]['customer']['key']);
    }

    public function test_a_stripe_subscription_maps_to_the_contract(): void
    {
        $s = ReactllConnect::fromStripeSubscription(['id' => 'sub_9', 'object' => 'subscription', 'status' => 'active', 'start_date' => 1759312800, 'cancel_at' => 1762000000,
            'canceled_at' => 1760000000, 'items' => ['data' => [['quantity' => 3, 'current_period_end' => 1762000000,
                'price' => ['unit_amount' => 2900, 'currency' => 'eur', 'nickname' => 'Team', 'recurring' => ['interval' => 'year', 'interval_count' => 1]]]]]],
            ['email' => 'team@example.test']);
        $this->assertSame(['sub_9', 'Team', 'EUR', '29.00', 'year', 3], [$s['id'], $s['plan'], $s['currency'], $s['amount'], $s['interval'], $s['quantity']]);
        $this->assertNotNull($s['ends_at']);
        $this->assertNotNull($s['customer']['key']);
        $this->assertNull(ReactllConnect::fromStripeSubscription(['object' => 'invoice', 'id' => 'in_1']));
    }

    public function test_switched_off_sends_nothing(): void
    {
        Http::fake();
        config(['reactll-connect.modules.sales' => false]);
        $this->assertSame('switched_off', ReactllConnect::sendSubscriptions([$this->sub()])->reason);
        Http::assertNothingSent();
    }
}
