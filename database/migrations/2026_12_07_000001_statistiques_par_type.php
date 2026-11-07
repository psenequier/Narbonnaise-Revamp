<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques (US-701 à US-703, décisions du 7 nov. 2026) : la vue v_occupation distingue
 * désormais ouverte, exclusive et attribuée, et donne le nombre de personnes et la source
 * (ancien ou nouveau site). Toujours sans membre ni lignée (ADR 012).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_occupation AS
            SELECT c.date,
                   c.type,
                   CASE
                       WHEN a.id IS NOT NULL THEN 'attribuee'
                       WHEN SUM(r.mode = 'exclusive') > 0 THEN 'exclusive'
                       WHEN COUNT(r.id) > 0 THEN 'ouverte'
                       ELSE 'libre'
                   END AS etat,
                   COALESCE(SUM(r.nombre_personnes), 0) AS personnes,
                   COALESCE(MAX(r.grr_id IS NOT NULL), 0) AS ancien_site,
                   COALESCE(MAX(r.id IS NOT NULL AND r.grr_id IS NULL), 0) AS nouveau_site
            FROM creneaux c
            LEFT JOIN attributions a ON a.creneau_id = c.id
            LEFT JOIN reservation_creneau rc ON rc.creneau_id = c.id
            LEFT JOIN reservations r ON r.id = rc.reservation_id AND r.annulee_le IS NULL
            GROUP BY c.id, c.date, c.type, a.id
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_occupation AS
            SELECT c.date,
                   c.type,
                   CASE
                       WHEN a.id IS NOT NULL THEN 'attribue'
                       WHEN EXISTS (
                           SELECT 1 FROM reservation_creneau rc
                           JOIN reservations r ON r.id = rc.reservation_id
                           WHERE rc.creneau_id = c.id AND r.annulee_le IS NULL
                       ) THEN 'occupe'
                       ELSE 'libre'
                   END AS etat
            FROM creneaux c
            LEFT JOIN attributions a ON a.creneau_id = c.id
            SQL);
    }
};
