<?php

namespace Reactll\Connect;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Reactll\Connect\Console\HeartbeatCommand;
use Reactll\Connect\Http\Middleware\InjectScript;

class ConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reactll-connect.php', 'reactll-connect');
        $this->app->singleton(Connect::class, fn () => new Connect(config('reactll-connect.token')));
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__.'/../config/reactll-connect.php' => config_path('reactll-connect.php')], 'reactll-connect');

        Blade::directive('reactllConnect', fn () => '<?php echo app(\Reactll\Connect\Connect::class)->script(); ?>');

        if (config('reactll-connect.inject', true)) {
            $router->pushMiddlewareToGroup('web', InjectScript::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([HeartbeatCommand::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('reactll:heartbeat')->hourly()->runInBackground()->withoutOverlapping(10);
            });
        }
    }
}
