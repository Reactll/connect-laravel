# Reactll Connect for Laravel

Connects a Laravel site to its [Reactll](https://reactll.com) dashboard: visits, calls, WhatsApp and
directions clicks, form leads and site health. Built and maintained by **Reactor Technology**.

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

## Options

`php artisan vendor:publish --tag=reactll-connect` — paths to skip, counting signed-in users, turning
the automatic script off (then put `@reactllConnect` in your layout's `<head>`).

## License

MIT © Reactor Technology
