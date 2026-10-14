<?php

namespace Reactll\Connect;

use JsonSerializable;

/**
 * What became of a send to Reactll. Never an exception: `ok` false means it did not arrive, and `reason` says why.
 *
 * Reasons: `switched_off` (the module is off here or in Reactll — nothing sent, ok), `not_allowed` (the client has not
 * agreed to sales in Reactll — nothing stored, ok), `queued` (sent after the response / by the queue, ok),
 * `host` (this site_url is not the site's — a staging copy with the live token; nothing stored), `unreachable`
 * (network error or Reactll down — kept for a retry), `refused` (Reactll will never take it: bad token, invalid data),
 * `not_connected` (no REACTLL_CONNECT_TOKEN).
 */
final class Result implements JsonSerializable
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $stored = 0,
        public readonly int $updated = 0,
        public readonly int $deleted = 0,
        public readonly int $skipped = 0,
        public readonly ?bool $salesAllowed = null,
        public readonly ?string $reason = null,
        public readonly int $queued = 0,
    ) {}

    public static function switchedOff(): self
    {
        return new self(true, salesAllowed: false, reason: 'switched_off');
    }

    public static function notConnected(): self
    {
        return new self(false, reason: 'not_connected');
    }

    /** @return array{ok: bool, stored: int, updated: int, deleted: int, skipped: int, sales_allowed: bool|null, reason: string|null, queued: int} */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'stored' => $this->stored,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'skipped' => $this->skipped,
            'sales_allowed' => $this->salesAllowed,
            'reason' => $this->reason,
            'queued' => $this->queued,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
