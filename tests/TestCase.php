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

        // Les styles ne sont compilés que dans le job « interface ».
        $this->withoutVite();

        // Archives de bascule écrites par les tests : jamais dans storage/app/exports, que le site
        // local affiche (Admin → Reprise). Un dossier temporaire par test, vidé à la fin.
        $this->dossierExportsTest = sys_get_temp_dir().'/exports-test-'.bin2hex(random_bytes(4));
        config(['chamrousse.dossier_exports' => $this->dossierExportsTest]);
    }

    protected string $dossierExportsTest = '';

    protected function tearDown(): void
    {
        foreach (glob($this->dossierExportsTest.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($this->dossierExportsTest);

        parent::tearDown();
    }
}
