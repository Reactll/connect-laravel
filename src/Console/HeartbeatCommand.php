<?php

namespace Reactll\Connect\Console;

use Illuminate\Console\Command;
use Reactll\Connect\Connect;

/** Hourly (scheduled by the package): tells Reactll what this app runs. Also a connection test. */
class HeartbeatCommand extends Command
{
    protected $signature = 'reactll:heartbeat';

    protected $description = 'Send this site\'s versions to Reactll and check the connection';

    public function handle(Connect $connect): int
    {
        if (! $connect->connected()) {
            $this->error('REACTLL_CONNECT_TOKEN is missing or not a Reactll Connect token.');

            return self::FAILURE;
        }
        $answer = $connect->heartbeat();
        if ($answer === null) {
            $this->error('Reactll did not accept the heartbeat — see the log.');

            return self::FAILURE;
        }
        $this->info('Connected to Reactll as '.($answer['site']['name'] ?? 'this site').'.');

        if ($retry = $connect->retry()) {
            $this->line($retry->ok ? 'Orders waiting for a retry were sent.' : 'Orders waiting for a retry could not be sent yet ('.$retry->reason.').');
        }

        return self::SUCCESS;
    }
}
