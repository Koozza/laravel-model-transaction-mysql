<?php namespace Koozza\ModelTransaction;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(ModelTransaction::class);

        Event::listen(['eloquent.saving: *'], function($event, $model) {
            return ModelTransaction::register($model[0]);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
