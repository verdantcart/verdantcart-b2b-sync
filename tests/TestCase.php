<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * SQLite tests always run in memory. phpunit.xml lets the shell override the database
     * settings (CI's PostgreSQL job needs that), so a terminal with DB_DATABASE set for a
     * second local installation would otherwise make the tests write a SQLite file by that name.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app['config']->get('database.default') === 'sqlite') {
            $app['config']->set('database.connections.sqlite.database', ':memory:');
        }

        return $app;
    }
}
