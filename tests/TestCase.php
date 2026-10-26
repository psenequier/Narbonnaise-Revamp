<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aucune requête réseau réelle en test : toute source externe est simulée.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }
}
