<?php
namespace Gbhorwood\Pollinate;

use Illuminate\Support\ServiceProvider;
use Gbhorwood\Pollinate\Pollinate;

class PollinateServiceProvider extends ServiceProvider
{
  public function register()
  {
  }

  public function boot()
  {
    if ($this->app->runningInConsole()) {
        $this->commands([
            Pollinate::class,
        ]);
    }
  }
}