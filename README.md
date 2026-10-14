# Reactll Connect for Laravel

Connects a Laravel site to its [Reactll](https://reactll.com) dashboard: visits, calls, WhatsApp and
directions clicks, form leads, sales and site health. Built and maintained by **Reactor Technology**.

## Install

```bash
composer require reactll/connect
```

```dotenv
REACTLL_CONNECT_TOKEN=rcc_…   # Reactll → Sites → the site → Install
```

```bash
php artisan reactll:heartbeat   # checks the connection
```

That's it: the script is added to every HTML page of the `web` group (not to your admin paths, not for
signed-in users). Reactll asks the app for its health every hour (`GET /api/reactll/health`, signed —
nothing else gets an answer), so no scheduler is needed; if one runs, `reactll:heartbeat` also reports hourly.

## Form leads

After your form is validated and saved:

```php
use Reactll\Connect\Facades\Connect;

Connect::submission('Contact', $request->only('name', 'email', 'phone', 'message'));
```

The script stamps every form with the page view id (`_rc_pv`), so Reactll ties the lead to the visit it
came from. It never throws; it returns `false` if Reactll could not be reached. To send it after the visitor
already has their response (recommended), use `Connect::submitAfterResponse(...)` with the same arguments.
`Connect::submit(...)` returns the whole answer (a `Reactll\Connect\Result`, see below) instead of a bool.

## Modules

Four switches, all on by default. A module switched off sends nothing (and Reactll drops anything that still
arrives). They are reported to Reactll with the hourly health answer and `reactll:heartbeat`, together with
`capabilities` (`forms`: the app sends its leads — `REACTLL_CONNECT_SENDS_LEADS`; `sales`: the sales module is on).

| Module | Off means |
|---|---|
| `visitors` | no script on the pages |
| `events` | the script counts page views only (`data-events="off"`: no call / WhatsApp / click events) |
| `forms` | `submission()` / `submitAfterResponse()` send nothing (`submit()` returns `reason: switched_off`) |
| `sales` | `order()`, `orders()`, `deleteOrders()`, `sendOrders()` send nothing (`reason: switched_off`) |

## Sales & orders

Custom shops and SaaS send their sales as orders (`POST /v1/orders`, contract: Reactll's CONNECT-ORDERS). The app
decides what an order is:

| What happened | Send |
|---|---|
| A one-off purchase / checkout | one order, `created_via: checkout`, items = what was bought |
| A subscription payment (first or renewal, e.g. a paid Stripe invoice) | one order per paid invoice, `id` = the invoice id, `created_via: subscription`, one item = the plan |
| A refund | the same order again with `refunded` set (`status: refunded` when fully refunded) — a newer `updated_at` replaces it |
| A failed payment | the order with `status: failed` (shows in the failed-payment alert, never as revenue) |
| A trial / free sign-up | **not an order** — send it as a submission (form `Signup`) |

Status: `completed` (paid), `pending`, `failed`, `refunded`, `cancelled`. Money as numbers or strings (sent as
decimal strings in the order's currency, never converted), dates as Carbon / DateTime / unix time / ISO strings
(sent as ISO 8601 with offset). `created_at` is required, `updated_at` defaults to now.

**Personal data stays in the app.** Pass the buyer's `email`: it becomes `customer.key` (HMAC-SHA256 of the
lowercased email with the site's secret — Reactll cannot reverse it) and is never sent. No email (Apple / TikTok
sign-in)? Pass `customer_id` (or `user_id`): hashed the same way with an `id:` prefix, never sent either; the email
wins when both are given. Only `First L.`, city and country go with it; phone, address, postcode and any key not in
the contract are dropped.

```php
use Reactll\Connect\Facades\Connect;

// A one-off checkout
Connect::order([
    'id' => $order->id,
    'status' => 'completed',
    'currency' => 'EUR',
    'total' => $order->total,          // 86.4 or "86.40"
    'tax' => $order->tax,
    'created_at' => $order->created_at,
    'paid_at' => $order->paid_at,
    'email' => $order->email,          // hashed, never sent
    'customer' => ['name' => $order->name, 'city' => $order->city, 'country' => 'DE'],
    'items' => [['name' => 'Pro plan', 'quantity' => 1, 'total' => $order->total, 'sku' => 'PRO']],
    'source' => ['type' => 'utm', 'source' => $user->utm_source, 'medium' => $user->utm_medium, 'campaign' => $user->utm_campaign],
    'admin_url' => route('admin.orders.show', $order),
]);

// A Stripe webhook (Cashier or your own): invoice.paid, invoice.payment_failed, credit_note.created / charge.refunded
// (send the invoice again). No stripe/stripe-php needed: an array or an object.
$invoice = $payload['data']['object'];
if ($order = Connect::fromStripeInvoice($invoice, ['customer' => ['returning' => null]])) {
    Connect::order($order);   // paid → completed, refunded → refunded, a failed attempt → failed
}

// A refund made in the app
Connect::order([...$sameOrder, 'refunded' => 86.40, 'status' => 'refunded', 'updated_at' => now()]);

// A failed payment
Connect::order([...$sameOrder, 'status' => 'failed', 'updated_at' => now()]);

// An order deleted in the app (a test order)
Connect::deleteOrders([$order->id]);

// A free sign-up is a lead, not an order
Connect::submitAfterResponse('Signup', ['plan' => 'trial', 'email' => $user->email]);
```

**Never slows the request.** `order()`, `orders()` and `deleteOrders()` normalise right away and send once the
response is out (`app()->terminating`, one request per 50 orders, each order once per request). From a command or a
queue worker they send at once; with `REACTLL_CONNECT_QUEUE=true` (or a connection name) they go through a queued
`Reactll\Connect\Jobs\SendOrders` job instead. What Reactll could not take (down, unreachable) waits in the cache
(up to 500 orders, 7 days) and is sent again after Reactll's hourly health call and by `reactll:heartbeat`.
`Connect::sendOrders($orders, $deleted = [], $backfill = false)` sends now and returns Reactll's answer.

Every call returns a `Reactll\Connect\Result` and never throws:

| Field | |
|---|---|
| `ok` | it arrived (or nothing had to be sent) |
| `stored`, `updated`, `deleted`, `skipped` | Reactll's counts (`skipped` also counts orders without id, created_at, currency or total) |
| `salesAllowed` | Reactll has the client's agreement for sales (`false` → nothing is stored yet) |
| `reason` | `queued`, `switched_off`, `not_allowed`, `host` (the app URL is not the site's in Reactll — a staging copy), `unreachable`, `refused`, `not_connected` |
| `queued` | orders and deletions waiting to be sent |

`site_url` is `config('app.url')`: it has to match the site's address in Reactll.

## Environment

| Variable | Default | |
|---|---|---|
| `REACTLL_CONNECT_TOKEN` | — | the connection token (Reactll → Sites → the site → Install) |
| `REACTLL_CONNECT_INJECT` | `true` | add the script to every HTML page of the `web` group |
| `REACTLL_CONNECT_COUNT_LOGGED_IN` | `false` | count signed-in users |
| `REACTLL_CONNECT_VISITORS` | `true` | visitors module |
| `REACTLL_CONNECT_EVENTS` | `true` | events module |
| `REACTLL_CONNECT_FORMS` | `true` | forms module |
| `REACTLL_CONNECT_SALES` | `true` | sales module |
| `REACTLL_CONNECT_SENDS_LEADS` | `true` | the app sends its form leads (reported as `capabilities.forms`) |
| `REACTLL_CONNECT_QUEUE` | `false` | send orders from a queued job (`true` or a connection name) — only when a worker runs |

## Options

`php artisan vendor:publish --tag=reactll-connect` — paths to skip, counting signed-in users, the modules, turning
the automatic script off (then put `@reactllConnect` in your layout's `<head>`).

## Content-Security-Policy

If the site sends a CSP header, allow Reactll Connect in it, or the browser blocks the script and its beacons:

```
script-src  … https://connect.reactll.com
connect-src … https://connect.reactll.com
```

## License

MIT © Reactor Technology
