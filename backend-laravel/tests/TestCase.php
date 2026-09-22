<?php

namespace Tests;

use App\Services\Outsource\Session\ArrayOutsourceSessionStore;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Public outsource session cookie is read as a raw identifier. API
        // routes are not always behind EncryptCookies in feature tests, so
        // keep cookies plaintext for deterministic session resolution.
        $this->disableCookieEncryption();

        $store = $this->app->make(OutsourceSessionStoreInterface::class);
        if ($store instanceof ArrayOutsourceSessionStore) {
            $store->flush();
        }
    }
}
