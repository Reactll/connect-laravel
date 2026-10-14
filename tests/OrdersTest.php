<?php

namespace Reactll\Connect\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Reactll\Connect\Connect;
use Reactll\Connect\Facades\Connect as ReactllConnect;
use Reactll\Connect\Jobs\SendOrders;
use Reactll\Connect\Orders;

class OrdersTest extends TestCase
{
    private function order(array $override = []): array
    {
        return array_replace([
            'id' => 4211,
            'status' => 'wc-processing',
            'currency' => 'usd',
            'total' => 86.4,
            'subtotal' => '78',
            'tax' => 6.9,
            'discount' => 5,
            'created_at' => Carbon::parse('2026-10-14 14:58:02', 'America/Chicago'),
            'email' => '  Maria.Lopez@Example.TEST ',
            'phone' => '+1 504 555 0100',
            'address' => '1 Royal St',
            'customer' => ['name' => 'maria lopez', 'city' => 'New Orleans', 'country' => 'us', 'postcode' => '70130', 'phone' => '555'],
            'items' => [['name' => 'Shrimp & Grits', 'quantity' => '2', 'total' => 38, 'sku' => 'SG-01', 'product_id' => 88]],
        ], $override);
    }

    private function ok(array $extra = []): array
    {
        return array_replace(['ok' => true, 'stored' => 1, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'sales_allowed' => true], $extra);
    }

    public function test_an_order_is_signed_and_posted_to_the_orders_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->ok())]);

        $result = ReactllConnect::sendOrders([$this->order()]);

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->stored);
        $this->assertTrue($result->salesAllowed);
        $this->assertNull($result->reason);
        Http::assertSent(function (Request $request) {
            $ts = $request->header('X-Reactll-Timestamp')[0];

            return $request->url() === 'https://connect.example.test/v1/orders'
                && $request->header('X-Reactll-Key')[0] === self::KEY
                && $request->header('X-Reactll-Signature')[0] === hash_hmac('sha256', $ts.'.'.$request->body(), self::SECRET)
                && $request['site_url'] === 'https://shop.example.test'
                && $request['backfill'] === false;
        });
    }

    public function test_the_order_is_normalised_and_the_email_never_leaves_the_app(): void
    {
        Http::fake(['*' => Http::response($this->ok())]);

        ReactllConnect::sendOrders([$this->order()]);

        $request = Http::recorded()[0][0];
        $this->assertStringNotContainsStringIgnoringCase('maria.lopez', $request->body());
        $this->assertStringNotContainsString('555', $request->body());
        $this->assertStringNotContainsString('Royal', $request->body());
        $this->assertStringNotContainsString('70130', $request->body());

        $o = $request['orders'][0];
        $this->assertSame('4211', $o['id']);
        $this->assertSame('4211', $o['number']);
        $this->assertSame('processing', $o['status']);
        $this->assertSame('USD', $o['currency']);
        $this->assertSame(['86.40', '78.00', '6.90', '0.00', '5.00', '0.00'], [$o['total'], $o['subtotal'], $o['tax'], $o['shipping'], $o['discount'], $o['refunded']]);
        $this->assertSame('2026-10-14T14:58:02-05:00', $o['created_at']);
        $this->assertNotNull($o['updated_at']);
        $this->assertSame('checkout', $o['created_via']);
        $this->assertSame([
            'label' => 'Maria L.',
            'city' => 'New Orleans',
            'country' => 'US',
            'key' => hash_hmac('sha256', 'maria.lopez@example.test', self::SECRET),
            'returning' => null,
        ], $o['customer']);
        $this->assertSame([['name' => 'Shrimp & Grits', 'quantity' => 2, 'total' => '38.00', 'sku' => 'SG-01', 'product_id' => '88']], $o['items']);
        $this->assertArrayNotHasKey('email', $o);
        $this->assertArrayNotHasKey('phone', $o);
        $this->assertArrayNotHasKey('address', $o);
    }

    public function test_normalising_twice_changes_nothing_and_invalid_orders_are_skipped(): void
    {
        $once = Orders::normalise($this->order(), self::SECRET);
        $this->assertSame($once, Orders::normalise($once, self::SECRET));
        $this->assertSame('1500', Orders::normalise($this->order(['currency' => 'JPY', 'total' => '1500.4']), self::SECRET)['total']);

        $this->assertNull(Orders::normalise(['id' => '1', 'currency' => 'USD', 'total' => 1], self::SECRET), 'no created_at');
        $this->assertNull(Orders::normalise($this->order(['currency' => 'dollars']), self::SECRET));
        $this->assertNull(Orders::normalise($this->order(['total' => 'free']), self::SECRET));

        Http::fake(['*' => Http::response($this->ok())]);
        $result = ReactllConnect::sendOrders([$this->order(), ['id' => 'x'], $this->order(['id' => 'draft', 'status' => 'checkout-draft'])]);
        $this->assertSame(2, $result->skipped);
        $this->assertCount(1, Http::recorded()[0][0]['orders']);
    }

    public function test_without_an_email_the_customer_id_is_the_key_and_never_sent(): void
    {
        $o = Orders::normalise($this->order(['email' => null, 'customer' => ['name' => 'Ayla'], 'customer_id' => ' Apple-000123.ABC ']), self::SECRET);
        $this->assertSame(hash_hmac('sha256', 'id:apple-000123.abc', self::SECRET), $o['customer']['key']);
        $this->assertStringNotContainsStringIgnoringCase('000123', json_encode($o));

        $o = Orders::normalise($this->order(['customer' => ['user_id' => 77, 'email' => 'A@B.test']]), self::SECRET);
        $this->assertSame(hash_hmac('sha256', 'a@b.test', self::SECRET), $o['customer']['key'], 'the email wins');
        $this->assertSame(hash_hmac('sha256', 'id:77', self::SECRET), Orders::normalise($this->order(['email' => null, 'customer' => ['user_id' => 77]]), self::SECRET)['customer']['key']);
    }

    public function test_orders_go_in_batches_of_fifty(): void
    {
        Http::fake(['*' => Http::response($this->ok(['stored' => 50]))]);

        $orders = array_map(fn ($i) => $this->order(['id' => 'o-'.$i]), range(1, 120));
        $result = ReactllConnect::sendOrders($orders, ['gone-1']);

        $sizes = array_map(fn ($pair) => count($pair[0]['orders']), Http::recorded()->all());
        $this->assertSame([50, 50, 20], $sizes);
        $this->assertSame(['gone-1'], Http::recorded()[0][0]['deleted']);
        $this->assertSame([], Http::recorded()[1][0]['deleted']);
        $this->assertSame(150, $result->stored);
    }

    public function test_a_switched_off_sales_module_sends_nothing(): void
    {
        config(['reactll-connect.modules.sales' => false]);
        Http::fake();

        foreach ([ReactllConnect::order($this->order()), ReactllConnect::orders([$this->order()]), ReactllConnect::deleteOrders(['1']), ReactllConnect::sendOrders([$this->order()])] as $result) {
            $this->assertTrue($result->ok);
            $this->assertSame('switched_off', $result->reason);
            $this->assertFalse($result->salesAllowed);
        }
        Http::assertNothingSent();
        $this->assertFalse(ReactllConnect::capabilities()['sales']);
    }

    public function test_reactll_not_allowing_sales_stops_the_rest(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'stored' => 0, 'sales_allowed' => false, 'reason' => 'not_allowed'])]);

        $result = ReactllConnect::sendOrders(array_map(fn ($i) => $this->order(['id' => 'o-'.$i]), range(1, 60)));

        $this->assertTrue($result->ok);
        $this->assertSame('not_allowed', $result->reason);
        $this->assertFalse($result->salesAllowed);
        Http::assertSentCount(1);
    }

    public function test_a_host_mismatch_stops_without_throwing(): void
    {
        Http::fake(['*' => Http::response(['error' => 'host'], 409)]);

        $result = ReactllConnect::sendOrders(array_map(fn ($i) => $this->order(['id' => 'o-'.$i]), range(1, 60)));

        $this->assertFalse($result->ok);
        $this->assertSame('host', $result->reason);
        Http::assertSentCount(1);
        $this->assertFalse(app(Connect::class)->hasRetry(), 'a staging copy is not retried');
    }

    public function test_any_other_409_means_already_delivered(): void
    {
        Http::fakeSequence()->push(['error' => 'replayed request'], 409)->push($this->ok(['stored' => 10]));

        $result = ReactllConnect::sendOrders(array_map(fn ($i) => $this->order(['id' => 'o-'.$i]), range(1, 60)));

        $this->assertTrue($result->ok);
        $this->assertNull($result->reason);
        $this->assertSame(10, $result->stored);
        Http::assertSentCount(2);
    }

    public function test_an_unreachable_reactll_keeps_the_orders_for_the_retry(): void
    {
        $down = true;
        Http::fake(function () use (&$down) {
            if ($down) {
                throw new ConnectionException('down');
            }

            return Http::response($this->ok(['stored' => 2, 'deleted' => 1]));
        });

        $result = ReactllConnect::sendOrders([$this->order(['id' => 'a']), $this->order(['id' => 'b'])], ['c']);

        $this->assertFalse($result->ok);
        $this->assertSame('unreachable', $result->reason);
        $this->assertTrue(app(Connect::class)->hasRetry());

        $down = false;
        $retry = ReactllConnect::retry();
        $this->assertTrue($retry->ok);
        $this->assertSame(2, $retry->stored);
        $sent = Http::recorded()[0][0];
        $this->assertSame(['a', 'b'], array_column($sent['orders'], 'id'));
        $this->assertSame(['c'], $sent['deleted']);
        $this->assertFalse(app(Connect::class)->hasRetry());
        $this->assertNull(ReactllConnect::retry());
    }

    public function test_bad_data_is_refused_and_not_retried(): void
    {
        Http::fake(['*' => Http::response(['message' => 'invalid'], 422)]);

        $result = ReactllConnect::sendOrders([$this->order()]);

        $this->assertFalse($result->ok);
        $this->assertSame('refused', $result->reason);
        $this->assertFalse(app(Connect::class)->hasRetry());
    }

    public function test_order_sends_at_once_from_a_command_or_worker(): void
    {
        Http::fake(['*' => Http::response($this->ok())]);

        $result = ReactllConnect::order($this->order());

        $this->assertTrue($result->ok);
        $this->assertSame('queued', $result->reason);
        $this->assertSame(1, $result->queued);
        Http::assertSentCount(1);
    }

    public function test_order_waits_for_the_end_of_a_web_request_and_sends_each_order_once(): void
    {
        $this->app->singleton(Connect::class, fn () => new class(self::token()) extends Connect {
            protected function inRequest(): bool
            {
                return true;
            }
        });
        Http::fake(['*' => Http::response($this->ok())]);

        ReactllConnect::order($this->order(['status' => 'pending']));
        ReactllConnect::order($this->order(['status' => 'completed']));
        ReactllConnect::deleteOrders([77]);
        Http::assertNothingSent();

        $this->app->terminate();

        Http::assertSentCount(1);
        $sent = Http::recorded()[0][0];
        $this->assertCount(1, $sent['orders']);
        $this->assertSame('completed', $sent['orders'][0]['status']);
        $this->assertSame(['77'], $sent['deleted']);
    }

    public function test_order_goes_to_the_queue_when_one_is_configured(): void
    {
        config(['reactll-connect.queue' => 'redis']);
        Bus::fake();
        Http::fake();

        ReactllConnect::order($this->order());

        Http::assertNothingSent();
        Bus::assertDispatched(SendOrders::class, fn (SendOrders $job) => $job->connection === 'redis'
            && $job->orders[0]['id'] === '4211'
            && ! str_contains(json_encode($job->orders), 'Example.TEST'));
    }

    public function test_the_queued_job_delivers(): void
    {
        Http::fake(['*' => Http::response($this->ok())]);

        (new SendOrders([Orders::normalise($this->order(), self::SECRET)], ['9']))->handle(app(Connect::class));

        Http::assertSentCount(1);
        $this->assertSame(['9'], Http::recorded()[0][0]['deleted']);
    }

    // ── Stripe ─────────────────────────────────────────────────────────────────────────────────────

    private function invoice(array $override = []): array
    {
        return array_replace([
            'id' => 'in_1Pabc',
            'object' => 'invoice',
            'number' => 'A1B2-0003',
            'status' => 'paid',
            'currency' => 'usd',
            'total' => 2900,
            'subtotal' => 2900,
            'amount_paid' => 2900,
            'amount_due' => 2900,
            'attempt_count' => 1,
            'billing_reason' => 'subscription_cycle',
            'subscription' => 'sub_123',
            'created' => 1760450282,
            'status_transitions' => ['paid_at' => 1760450290],
            'customer_email' => 'Buyer@Example.test',
            'customer_name' => 'Ayla Demir',
            'customer_phone' => '+90 555',
            'customer_address' => ['line1' => 'Bagdat Cd 1', 'postal_code' => '34000', 'city' => 'Istanbul', 'country' => 'TR'],
            'livemode' => false,
            'lines' => ['object' => 'list', 'data' => [['description' => '1 × Pro (at $29.00 / month)', 'amount' => 2900, 'quantity' => 1, 'price' => ['product' => 'prod_9']]]],
        ], $override);
    }

    public function test_a_paid_stripe_invoice_is_a_subscription_order(): void
    {
        $o = ReactllConnect::fromStripeInvoice($this->invoice());

        $this->assertSame('in_1Pabc', $o['id']);
        $this->assertSame('A1B2-0003', $o['number']);
        $this->assertSame('completed', $o['status']);
        $this->assertSame('USD', $o['currency']);
        $this->assertSame('29.00', $o['total']);
        $this->assertSame('subscription', $o['created_via']);
        $this->assertSame('2025-10-14T13:58:02+00:00', $o['created_at']);
        $this->assertSame('2025-10-14T13:58:10+00:00', $o['paid_at']);
        $this->assertSame([['name' => '1 × Pro (at $29.00 / month)', 'quantity' => 1, 'total' => '29.00', 'sku' => null, 'product_id' => 'prod_9']], $o['items']);
        $this->assertSame(['label' => 'Ayla D.', 'city' => 'Istanbul', 'country' => 'TR', 'key' => hash_hmac('sha256', 'buyer@example.test', self::SECRET), 'returning' => true], $o['customer']);
        $this->assertSame('https://dashboard.stripe.com/test/invoices/in_1Pabc', $o['admin_url']);
        $json = json_encode($o);
        foreach (['Buyer', 'example.test', '555', 'Bagdat', '34000'] as $pii) {
            $this->assertStringNotContainsString($pii, $json);
        }
    }

    public function test_refunded_failed_and_object_invoices(): void
    {
        $refunded = ReactllConnect::fromStripeInvoice($this->invoice(['post_payment_credit_notes_amount' => 2900]));
        $this->assertSame('refunded', $refunded['status']);
        $this->assertSame('29.00', $refunded['refunded']);

        $partly = ReactllConnect::fromStripeInvoice($this->invoice(['charge' => ['amount_refunded' => 1000]]));
        $this->assertSame('completed', $partly['status']);
        $this->assertSame('10.00', $partly['refunded']);

        $failed = ReactllConnect::fromStripeInvoice($this->invoice(['status' => 'open', 'amount_paid' => 0, 'attempt_count' => 2, 'billing_reason' => 'subscription_create']));
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('29.00', $failed['total']);
        $this->assertFalse($failed['customer']['returning']);

        $object = ReactllConnect::fromStripeInvoice(json_decode(json_encode($this->invoice(['currency' => 'jpy', 'total' => 3000, 'amount_paid' => 3000, 'subtotal' => 3000]))), ['source' => ['source' => 'google'], 'customer' => ['returning' => false]]);
        $this->assertSame('3000', $object['total']);
        $this->assertSame('google', $object['source']['source']);
        $this->assertFalse($object['customer']['returning']);

        $this->assertNull(ReactllConnect::fromStripeInvoice(['object' => 'invoice']));

        Http::fake(['*' => Http::response($this->ok())]);
        $this->assertTrue(ReactllConnect::order($refunded)->ok);
        $this->assertSame('refunded', Http::recorded()[0][0]['orders'][0]['status']);
    }
}
