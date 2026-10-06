<?php

declare(strict_types=1);

namespace Ux2Dev\GpsBulgaria\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class GpsBulgariaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/gps-bulgaria.php', 'gps-bulgaria');

        if (class_exists(HttpFactory::class)) {
            $this->app->bindIf(RequestFactoryInterface::class, HttpFactory::class);
            $this->app->bindIf(StreamFactoryInterface::class, HttpFactory::class);
        }

        $this->app->singleton(GpsBulgariaManager::class, static function (Application $app): GpsBulgariaManager {
            $repository = $app->make('config');
            $loaded = $repository instanceof Repository ? $repository->get('gps-bulgaria', []) : [];

            /** @var array<string, mixed> $config */
            $config = is_array($loaded) ? $loaded : [];

            // A PSR-18 client is only passed when the app bound one itself;
            // otherwise the manager builds Guzzle per tenant with its timeout.
            return new GpsBulgariaManager(
                $config,
                $app->bound(ClientInterface::class) ? $app->make(ClientInterface::class) : null,
                $app->bound(RequestFactoryInterface::class) ? $app->make(RequestFactoryInterface::class) : null,
                $app->bound(StreamFactoryInterface::class) ? $app->make(StreamFactoryInterface::class) : null,
            );
        });

        $this->app->alias(GpsBulgariaManager::class, 'gps-bulgaria');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/gps-bulgaria.php' => config_path('gps-bulgaria.php'),
            ], 'gps-bulgaria-config');
        }
    }
}
