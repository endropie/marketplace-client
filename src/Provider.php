<?php

namespace Virmata\MarketplaceClient;

use Generator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class Provider extends ServiceProvider
{

    public function register()
    {
        $this->app->singleton('marketplace', function ($app) {
            return new Facade($app);
        });

        $this->mergeConfigFrom(__DIR__.'/../config/marketplace.php', 'marketplace');
        $this->registerConfig();
        $this->registerMigrations();
        $this->registerAssets();
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'marketplace-client');
        Route::middleware('api')
            ->prefix(config('marketplace.route_prefix', 'marketplace'))
            ->group(__DIR__.'/../route.php');


        if ($this->app->environment('local', 'testing', 'staging')) {
            config()->set('marketplace.shopee.host', 'https://openplatform.sandbox.test-stable.shopee.sg');
        }

        $this->expectJsonResponse();
    }

    protected function expectJsonResponse()
    {
        if (request()->header('X-Marketplace')) {
            $session = (array) json_decode(decrypt(request()->header('X-Marketplace')));

            config()->set('marketplace.session.auth', (array) $session['auth']);
            config()->set('marketplace.session.db', (array) $session['db']);
        }
    }

    protected function registerConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/marketplace.php' => base_path('config/marketplace.php'),
            ], 'config');
        }
    }

    protected function registerAssets(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/public/js/marketplace-client.js' => base_path('public/js/marketplace-client.js'),
            ], 'assets');
        }
    }

    protected function registerMigrations(?string $directory = null): void
    {
        if (is_null($directory)) $directory = __DIR__.'/../database/migrations';

        if ($this->app->runningInConsole()) {
            $generator = function(string $directory): Generator {
                foreach ($this->app->make('files')->allFiles($directory) as $file) {
                    yield $file->getPathname() => $this->app->databasePath(
                        'migrations/' . now()->format('Y_m_d_His') . str($file->getFilename())->after('00_00_00_000000')
                    );
                }
            };

            $this->publishes(iterator_to_array($generator($directory)), 'migrations');
        }
    }
}
