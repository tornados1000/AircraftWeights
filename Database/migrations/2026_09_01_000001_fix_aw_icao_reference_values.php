<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Korrigiert falsche Werte in der ausgelieferten ICAO-Referenztabelle.
 *
 * Die Seed-Daten der ersten Migration trugen bei 17 Mustern falsche Gewichte —
 * bei 13 davon stand im Feld `mzfw` der MLW-Wert (A319: 62.500/62.500 statt
 * 58.500/62.500). Wer `sync()` gedrueckt hat, hat diese Werte auf seinen Bestand
 * geschrieben: die Flugzeuge nehmen dann mehr Zuladung an, als sie duerfen.
 *
 * Der reparierte Seed hilft nur NEUEN Installationen. Diese Migration zieht
 * bestehende nach.
 *
 * WICHTIG — sie aendert einen Wert nur dann, wenn dort noch genau der alte
 * falsche steht. Wer seine Tabelle selbst korrigiert oder auf eigene Varianten
 * angepasst hat, bleibt unangetastet. Ein Lauf auf einer bereits korrekten
 * Installation ist ein No-Op.
 *
 * Die Gewichte der FLUGZEUGE korrigiert diese Migration bewusst NICHT — dafuer
 * gibt es den Knopf "Sync" im Adminbereich, den der Betreiber selbst ausloest.
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:int,3:int}> ICAO, Feld, alt, neu */
    private array $fixes = [
        ['A306', 'mzfw', 140000, 130000],
        ['A310', 'mzfw', 123000, 114020],
        ['A318', 'mzfw',  57500,  54500],
        ['A319', 'mzfw',  62500,  58500],
        ['A332', 'dow',  120600, 116000],
        ['A332', 'mzfw', 170000, 168000],
        ['A332', 'mtow', 242000, 230000],
        ['A332', 'mlw',  187000, 180000],
        ['A339', 'mzfw', 191000, 181000],
        ['A343', 'mzfw', 190000, 178000],
        ['B38M', 'mzfw',  64300,  65952],
        ['B461', 'mzfw',  35608,  29484],
        ['B462', 'mzfw',  36878,  32205],
        ['B463', 'dow',   26090,  24600],
        ['B463', 'mzfw',  39554,  36741],
        ['B722', 'mzfw',  68900,  65317],
        ['B734', 'mzfw',  56290,  53070],
        ['B737', 'mzfw',  58060,  57153],
        ['B753', 'mzfw', 101600,  95255],
        ['DH8C', 'mzfw',  18600,  17917],
        ['MD11', 'mzfw', 218200, 181436],
    ];

    public function up(): void
    {
        $this->apply(fn ($fix) => [$fix[2], $fix[3]]);
    }

    public function down(): void
    {
        $this->apply(fn ($fix) => [$fix[3], $fix[2]]);
    }

    private function apply(callable $richtung): void
    {
        if (!Schema::hasTable('aw_icao_weights')) {
            return;
        }

        foreach ($this->fixes as $fix) {
            [$von, $nach] = $richtung($fix);

            DB::table('aw_icao_weights')
                ->where('icao', $fix[0])
                ->where($fix[1], $von)
                ->update([$fix[1] => $nach, 'updated_at' => now()]);
        }
    }
};
