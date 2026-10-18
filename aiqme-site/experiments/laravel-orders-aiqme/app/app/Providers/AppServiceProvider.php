<?php

namespace App\Providers;

use Comparison\Bindings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Bindings::register($this->app);
    }

    public function boot(): void
    {
        View::addNamespace('comparison', base_path('../../orders-comparison/shared/views'));
    }
}
