<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests need an APP_KEY for the `encrypted` cast, but no key may be
     * committed (PRD A02). Generate one per test process instead; the test
     * database is throwaway, so a fresh key each run is harmless.
     */
    public function createApplication(): Application
    {
        if (($_SERVER['APP_KEY'] ?? '') === '' && getenv('APP_KEY') === false) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $_SERVER['APP_KEY'] = $key;
            $_ENV['APP_KEY'] = $key;
            putenv('APP_KEY='.$key);
        }

        return parent::createApplication();
    }
}
