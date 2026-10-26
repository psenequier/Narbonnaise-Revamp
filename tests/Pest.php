<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Convention : chaque test commence par l'identifiant du critère d'acceptation
 * qu'il prouve (« US-301-N1 … »). scripts/verifier-criteres.php s'en sert.
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');

pest()->group('browser')->in('Browser');
