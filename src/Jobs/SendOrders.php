<?php

namespace Reactll\Connect\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Reactll\Connect\Connect;

/**
 * Sends orders already normalised by ReactllConnect::order()/orders() (only when config reactll-connect.queue is
 * set). One try: what Reactll could not take goes to the package's own retry (cache), so a down Reactll never
 * fills the failed-jobs table.
 */
class SendOrders implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @param  array<int, string>  $deleted
     */
    public function __construct(public array $orders, public array $deleted = []) {}

    public function handle(Connect $connect): void
    {
        if ($connect->module('sales') && $connect->connected()) {
            $connect->deliver($this->orders, $this->deleted);
        }
    }
}
