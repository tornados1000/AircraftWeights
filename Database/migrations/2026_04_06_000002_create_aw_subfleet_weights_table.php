<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gewichts-Uebersteuerung je FLOTTE.
 *
 * Die Mustertabelle `aw_icao_weights` kennt genau einen Gewichtssatz je ICAO.
 * Das reicht nicht: Die 767-300F traegt 309.000 lb MZFW, die 767-300ER
 * derselben ICAO nur 272.932. Wer beide aus der Mustertabelle bedient, gibt
 * einem der beiden die Zahlen des anderen.
 *
 * Diese Tabelle haelt deshalb pro Flotte einen eigenen Satz, der Vorrang hat.
 * Alle Werte in KILOGRAMM wie in `aw_icao_weights`; NULL bedeutet "fuer dieses
 * Feld gilt weiter die Mustertabelle".
 *
 * ⚠ Diese Datei wurde am 26.08.2026 nachtraeglich wiederhergestellt. Die
 *   Migration war auf GSG-Live gelaufen (Eintrag in `migrations` vorhanden),
 *   die Datei selbst aber im Modul nicht mehr da — eine Neuinstallation haette
 *   die Tabelle also nicht bekommen, und `sync()` braucht sie seit v1.1.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aw_subfleet_weights')) {
            return;
        }

        Schema::create('aw_subfleet_weights', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subfleet_id')->unique();
            $table->string('icao', 10)->nullable();
            $table->integer('dow')->nullable();
            $table->integer('mzfw')->nullable();
            $table->integer('mtow')->nullable();
            $table->integer('mlw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aw_subfleet_weights');
    }
};
