<?php

namespace Reactll\Connect\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool submission(string $form, array $fields, ?\Illuminate\Http\Request $request = null, ?string $id = null)
 * @method static void submitAfterResponse(string $form, array $fields, ?\Illuminate\Http\Request $request = null, ?string $id = null)
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
