<?php

namespace Tests;

use App\Domain\Tenancy\DatabaseContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function dbContext(): DatabaseContext
    {
        return $this->app->make(DatabaseContext::class);
    }
}
