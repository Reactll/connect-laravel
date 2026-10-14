<?php

namespace Reactll\Connect\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool submission(string $form, array $fields, ?\Illuminate\Http\Request $request = null, ?string $id = null)
 * @method static \Reactll\Connect\Result submit(string $form, array $fields, ?\Illuminate\Http\Request $request = null, ?string $id = null)
 * @method static void submitAfterResponse(string $form, array $fields, ?\Illuminate\Http\Request $request = null, ?string $id = null)
 * @method static \Reactll\Connect\Result order(array $order)
 * @method static \Reactll\Connect\Result orders(array $orders, array $deleted = [])
 * @method static \Reactll\Connect\Result deleteOrders(array $ids)
 * @method static \Reactll\Connect\Result sendOrders(array $orders, array $deleted = [], bool $backfill = false)
 * @method static array|null fromStripeInvoice(array|object $invoice, array $extra = [])
 * @method static \Reactll\Connect\Result|null retry()
 * @method static array modules()
 * @method static bool module(string $name)
 * @method static array capabilities()
 * @method static array|null heartbeat()
 * @method static string script()
 * @method static bool connected()
 *
 * @see \Reactll\Connect\Connect
 */
class Connect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Reactll\Connect\Connect::class;
    }
}
