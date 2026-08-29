<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weight override per FLEET.
 *
 * The type table `aw_icao_weights` knows exactly one weight set per ICAO.
 * That's not enough: the 767-300F carries 309,000 lb MZFW, while the
 * 767-300ER of the same ICAO carries only 272,932. Serving both from the
 * type table alone gives one of them the other's numbers.
 *
 * This table therefore holds its own set per fleet, which takes priority.
 * All values in KILOGRAMS as in `aw_icao_weights`; NULL means "the type
 * table still applies for this field".
 *
 * ⚠ This file was restored on 2026-08-26. The migration had already run on
 *   GSG-Live (entry present in `migrations`), but the file itself was no
 *   longer in the module — so a fresh install would not have gotten the
 *   table, and `sync()` has needed it since v1.1.0.
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
