<?php

namespace Modules\AircraftWeights\Providers;

use App\Services\ModuleService;
use Illuminate\Support\ServiceProvider;

class AW_ServiceProvider extends ServiceProvider
{
    protected $moduleSvc;

    public function boot(): void
    {
        $this->moduleSvc = app(ModuleService::class);

        $this->registerViews();
        $this->loadMigrationsFrom(module_path('AircraftWeights', 'Database/migrations'));
        $this->registerLinks();
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        $this->mergeConfigFrom(
            module_path('AircraftWeights', 'Config/config.php'),
            'aircraftweights'
        );
    }

    public function registerLinks(): void
    {
        $this->moduleSvc->addAdminLink('Aircraft Weights', '/admin/aircraftweights', 'pe-7s-plane');
    }

    protected function registerViews(): void
    {
        $viewPath   = resource_path('views/modules/aircraftweights');
        $sourcePath = module_path('AircraftWeights', 'Resources/views');

        $this->publishes([$sourcePath => $viewPath], 'views');

        $paths   = array_map(fn($p) => $p . '/modules/aircraftweights', \Config::get('view.paths'));
        $paths[] = $sourcePath;
        $this->loadViewsFrom($paths, 'aircraftweights');
    }

    public function provides(): array
    {
        return [];
    }
}
