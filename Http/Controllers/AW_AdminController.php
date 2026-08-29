<?php

namespace Modules\AircraftWeights\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\AircraftWeights\Models\AW_IcaoWeight;

class AW_AdminController extends Controller
{
    /**
     * phpVMS stores all weights INTERNALLY IN LBS.
     * The admin UI and PaxStudio display them converted to kg via Measure->local(0).
     * When writing directly via DB::table(), we MUST convert kg → lbs first (× 2.20462).
     *
     * Column mapping (aircraft table):
     *   dow  = Dry Operating Weight
     *   zfw  = Max Zero Fuel Weight (phpVMS UI label: MZFW)
     *   mtow = Max Takeoff Weight
     *   mlw  = Max Landing Weight
     */
    private const LBS = 2.20462;

    private function weightColumns(): array
    {
        return [
            'dow'  => 'dow',
            'mzfw' => 'zfw',
            'mtow' => 'mtow',
            'mlw'  => 'mlw',
        ];
    }

    /** Convert kg reference value to lbs for storage in phpVMS aircraft table */
    private function kgToLbs(?float $kg): float
    {
        return round(($kg ?? 0) * self::LBS);
    }

    /**
     * Fleet overrides from `aw_subfleet_weights`, keyed by subfleet_id.
     *
     * WHY THIS HAS TO EXIST — the most expensive bug in this module:
     *
     * The ICAO table knows exactly ONE weight set per type. A type actually has
     * several: the 767-300F carries 309,000 lb MZFW, while the 767-300ER of the
     * same ICAO type carries only 272,932. Until 2026-08-26, `sync()` wrote the
     * type value onto EVERY aircraft — erasing all freighter and variant
     * differences in the fleet every single time.
     *
     * The damage was invisible because `DB::table()->update()` doesn't touch
     * `updated_at`: the rows looked unchanged. It only surfaced through a fleet
     * audit — 70 freighters promised more cargo than they could lift (777F:
     * 102 t fare against 66.6 t payload), because they carried the passenger
     * weight set of their type.
     *
     * The `aw_subfleet_weights` table was created for exactly this case — and
     * was read by NO code path. It is now wired in: wherever a fleet has an
     * entry, it wins. Not as a lock, but as the source — so `sync()` doesn't
     * just leave freighters alone, it actively keeps them correct.
     *
     * @return array<int,object>
     */
    private function subfleetOverrides(): array
    {
        return DB::table('aw_subfleet_weights')->get()->keyBy('subfleet_id')->all();
    }

    /**
     * Which weight set applies to this aircraft — in KG?
     *
     * Order: fleet override before type reference, field by field. So an
     * override may also set only individual values.
     *
     * @return array{dow:?float,mzfw:?float,mtow:?float,mlw:?float}|null
     */
    private function referenceFor(object $aircraft, ?object $icao, array $overrides): ?array
    {
        $ov = $overrides[$aircraft->subfleet_id ?? 0] ?? null;

        if ($ov === null && $icao === null) {
            return null;
        }

        $field = static function (string $k) use ($ov, $icao): ?float {
            if ($ov !== null && isset($ov->$k) && $ov->$k !== null && $ov->$k !== '') {
                return (float) $ov->$k;
            }

            return ($icao !== null && $icao->$k !== null) ? (float) $icao->$k : null;
        };

        return [
            'dow'  => $field('dow'),
            'mzfw' => $field('mzfw'),
            'mtow' => $field('mtow'),
            'mlw'  => $field('mlw'),
        ];
    }

    public function index()
    {
        $weights     = AW_IcaoWeight::orderBy('icao')->get();
        $weightIndex = $weights->keyBy(fn($w) => strtoupper($w->icao));
        $colMap      = $this->weightColumns();

        $aircraft = DB::table('aircraft')
            ->leftJoin('subfleets', 'aircraft.subfleet_id', '=', 'subfleets.id')
            ->select('aircraft.*', 'subfleets.name as subfleet_name')
            ->orderBy('aircraft.registration')
            ->get();

        foreach ($aircraft as $ac) {
            // Normalize weight fields to consistent names regardless of phpVMS version
            $ac->_dow  = $colMap['dow']  ? ($ac->{$colMap['dow']}  ?? null) : null;
            $ac->_mzfw = $colMap['mzfw'] ? ($ac->{$colMap['mzfw']} ?? null) : null;
            $ac->_mtow = $colMap['mtow'] ? ($ac->{$colMap['mtow']} ?? null) : null;
            $ac->_mlw  = $colMap['mlw']  ? ($ac->{$colMap['mlw']}  ?? null) : null;

            $ac->icaoWeight = ($ac->icao ?? null)
                ? ($weightIndex[strtoupper($ac->icao)] ?? null)
                : null;
        }

        return view('aircraftweights::admin.index', compact('weights', 'aircraft', 'colMap'));
    }

    public function save(Request $request)
    {
        $request->validate([
            'icao'        => 'required|string|max:10',
            'engine_type' => 'nullable|string|max:50',
            'dow'         => 'nullable|integer|min:0',
            'mzfw'        => 'nullable|integer|min:0',
            'mtow'        => 'nullable|integer|min:0',
            'mlw'         => 'nullable|integer|min:0',
            'source_url'  => 'nullable|string|max:500',
            'note'        => 'nullable|string|max:1000',
        ]);

        $data = [
            'icao'        => strtoupper(trim($request->input('icao'))),
            'engine_type' => $request->input('engine_type'),
            'dow'         => $request->input('dow'),
            'mzfw'        => $request->input('mzfw'),
            'mtow'        => $request->input('mtow'),
            'mlw'         => $request->input('mlw'),
            'source_url'  => $request->input('source_url'),
            'note'        => $request->input('note'),
        ];

        $id = $request->input('id');
        if ($id) {
            AW_IcaoWeight::findOrFail($id)->update($data);
        } else {
            AW_IcaoWeight::create($data);
        }

        return redirect()->route('aircraftweights.admin.index')->with('success', 'Saved.');
    }

    public function delete(int $id)
    {
        AW_IcaoWeight::findOrFail($id)->delete();
        return redirect()->route('aircraftweights.admin.index')->with('success', 'Deleted.');
    }

    /**
     * Bis zu wie vielen Pfund ein gespeicherter Wert als "gleich" gilt.
     *
     * Die Referenzen stehen in ganzen Kilogramm, phpVMS speichert Pfund. Ein
     * Kilogramm sind 2,2 lb — viele reale Pfundwerte (174.198 lb, 147.300 lb)
     * sind aus ganzen Kilogramm gar nicht erreichbar. Ohne Toleranz schrieb
     * jeder Sync bei 62 Flugzeugen 1–3 lb um und kippte damit die exakten
     * Gewichtssaetze, die die FleetDesk-Flottenpruefung als gewollt kennt.
     * Gleicher Wert wie dort (`GEWICHT_TOLERANZ_LB`).
     */
    private const TOLERANZ_LB = 10.0;

    public function sync()
    {
        $colMap      = $this->weightColumns();
        $weightIndex = AW_IcaoWeight::all()->keyBy(fn($w) => strtoupper($w->icao));
        $overrides   = $this->subfleetOverrides();
        $aircraft    = DB::table('aircraft')->whereNotNull('icao')->where('icao', '!=', '')->get();
        $gemischt    = $this->gemischteFlotten($aircraft, $colMap);

        $written      = 0;
        $unchanged    = 0;
        $missing      = 0;
        $fromOverride = 0;
        $skipped      = [];

        foreach ($aircraft as $ac) {
            $icao = $weightIndex[strtoupper($ac->icao)] ?? null;
            $ref  = $this->referenceFor($ac, $icao, $overrides);

            if ($ref === null) {
                $missing++;
                continue;
            }

            if (isset($overrides[$ac->subfleet_id ?? 0])) {
                $fromOverride++;
            }

            // Only fields that are empty or genuinely deviate — rounding is not a deviation.
            $update     = [];
            $overwrites = false;
            foreach (['dow', 'mzfw', 'mtow', 'mlw'] as $feld) {
                $spalte = $colMap[$feld];
                if (!$spalte || $ref[$feld] === null) {
                    continue;
                }

                $soll = $this->kgToLbs($ref[$feld]);
                $ist  = (float) ($ac->$spalte ?? 0);

                if ($ist <= 0) {
                    $update[$spalte] = $soll;
                } elseif (abs($soll - $ist) > self::TOLERANZ_LB) {
                    $update[$spalte] = $soll;
                    $overwrites      = true;
                }
            }

            if (!$update) {
                $unchanged++;
                continue;
            }

            // A fleet whose own aircraft carry different weights has deliberate variants
            // (DLH-A21N: D-AIEO vs. D-AIEA/D-AIEP). A type value — or even a fleet override,
            // which only knows ONE set — would be wrong there. Empty fields are still filled
            // in; existing values are not overwritten.
            if ($overwrites && isset($gemischt[$ac->subfleet_id ?? 0]) && empty($ac->deleted_at)) {
                $skipped[] = $ac->registration;
                continue;
            }

            DB::table('aircraft')->where('id', $ac->id)->update($update);
            $written++;
        }

        $msg = "Sync: {$written} aircraft changed, {$unchanged} already matched, {$missing} without a reference.";
        if ($fromOverride) {
            $msg .= " {$fromOverride} aircraft get their values from a fleet override.";
        }
        if ($skipped) {
            sort($skipped);
            $msg .= ' Not overwritten, because their fleet deliberately carries different weights: '
                . implode(', ', array_slice($skipped, 0, 20))
                . (count($skipped) > 20 ? ' …' : '')
                . ' — please assign these individually or split the fleet.';
        }

        return redirect()->route('aircraftweights.admin.index')->with('success', $msg);
    }

    /**
     * Flotten, deren lebende Flugzeuge untereinander verschiedene Gewichte tragen.
     *
     * Verglichen wird mit derselben Toleranz wie beim Schreiben: SP-LVA und SP-LVL
     * unterscheiden sich im MTOW um 2 lb (Rundung), im DOW aber um 68 lb — Letzteres
     * zaehlt.
     *
     * @return array<int,true>
     */
    private function gemischteFlotten($aircraft, array $colMap): array
    {
        $jeFlotte = [];
        foreach ($aircraft as $ac) {
            if (!empty($ac->deleted_at)) {
                continue;
            }
            $werte = [];
            foreach (['dow', 'mzfw', 'mtow', 'mlw'] as $feld) {
                $werte[] = $colMap[$feld] ? (float) ($ac->{$colMap[$feld]} ?? 0) : 0.0;
            }
            $jeFlotte[$ac->subfleet_id ?? 0][] = $werte;
        }

        $gemischt = [];
        foreach ($jeFlotte as $sf => $liste) {
            $erste = $liste[0];
            foreach ($liste as $werte) {
                foreach ($werte as $i => $v) {
                    if (abs($v - $erste[$i]) > self::TOLERANZ_LB) {
                        $gemischt[$sf] = true;
                        continue 3;
                    }
                }
            }
        }

        return $gemischt;
    }

    /**
     * Check all aircraft weight columns (DOW, ZFW/MZFW, MTOW, MLW).
     * If stored value × 0.453592 is within ±20% of the ICAO reference (kg),
     * the value was entered in lbs → convert to kg and save.
     */
    /**
     * Detect and fix wrong weight values:
     * phpVMS stores lbs. Correct lbs = ref_kg × 2.20462.
     *
     * Wrong case A: someone stored the raw kg value in the lbs field
     *   → stored ≈ ref_kg  (e.g. 79000 stored, should be 174165)
     *   → fix: stored × 2.20462
     *
     * Wrong case B: someone ran kg→lbs and stored the result as if it were lbs again
     *   → stored ≈ ref_kg × 0.453592  (e.g. 35834 stored, should be 174165)
     *   → fix: stored × (2.20462)²
     *
     * In both cases the safest fix is simply: overwrite with ref_kg × 2.20462 (same as sync).
     * This button catches aircraft whose ICAO IS set but values are wrong.
     */
    public function fixLbs()
    {
        $colMap      = $this->weightColumns();
        $weightIndex = AW_IcaoWeight::all()->keyBy(fn($w) => strtoupper($w->icao));
        $overrides   = $this->subfleetOverrides();
        $aircraft    = DB::table('aircraft')->whereNotNull('icao')->where('icao', '!=', '')->get();

        $converted = 0;
        $skipped   = 0;
        $noRef     = 0;

        foreach ($aircraft as $ac) {
            // Here too, the fleet override takes priority over the type table —
            // otherwise this button would fix the units while destroying exactly
            // the freighter weights that sync() just set correctly.
            $icao = $weightIndex[strtoupper($ac->icao)] ?? null;
            $ref  = $this->referenceFor($ac, $icao, $overrides);

            if ($ref === null || empty($ref['mtow'])) { $noRef++; continue; }

            $storedMtow  = $colMap['mtow'] ? ($ac->{$colMap['mtow']} ?? 0) : 0;
            $correctLbs  = $ref['mtow'] * self::LBS;        // what SHOULD be in DB
            $diffCorrect = abs($storedMtow - $correctLbs) / $correctLbs;

            // Already correct lbs value (within 2%)
            if ($diffCorrect < 0.02) { $skipped++; continue; }

            // Write correct lbs values
            $update = [];
            if ($colMap['dow']  && $ref['dow']  !== null) $update[$colMap['dow']]  = $this->kgToLbs($ref['dow']);
            if ($colMap['mzfw'] && $ref['mzfw'] !== null) $update[$colMap['mzfw']] = $this->kgToLbs($ref['mzfw']);
            if ($colMap['mtow'] && $ref['mtow'] !== null) $update[$colMap['mtow']] = $this->kgToLbs($ref['mtow']);
            if ($colMap['mlw']  && $ref['mlw']  !== null) $update[$colMap['mlw']]  = $this->kgToLbs($ref['mlw']);

            DB::table('aircraft')->where('id', $ac->id)->update($update);
            $converted++;
        }

        $msg = "Unit fix: {$converted} aircraft corrected";
        if ($skipped) $msg .= ", {$skipped} already correct";
        if ($noRef)   $msg .= ", {$noRef} without an ICAO reference";

        return redirect()->route('aircraftweights.admin.index')->with('success', $msg);
    }

    public function assign(Request $request)
    {
        $request->validate([
            'aircraft_id' => 'required|integer',
            'icao'        => 'required|string|max:10',
        ]);

        $icao = strtoupper(trim($request->input('icao')));
        $w    = AW_IcaoWeight::whereRaw('UPPER(icao) = ?', [$icao])->first();

        if (!$w) {
            return redirect()->route('aircraftweights.admin.index')
                ->with('error', "ICAO '{$icao}' not found in the weight table.");
        }

        $colMap = $this->weightColumns();
        // phpVMS stores lbs internally → convert from our kg reference values
        $update = ['icao' => $icao];
        if ($colMap['dow'])  $update[$colMap['dow']]  = $this->kgToLbs($w->dow);
        if ($colMap['mzfw']) $update[$colMap['mzfw']] = $this->kgToLbs($w->mzfw);
        if ($colMap['mtow']) $update[$colMap['mtow']] = $this->kgToLbs($w->mtow);
        if ($colMap['mlw'])  $update[$colMap['mlw']]  = $this->kgToLbs($w->mlw);

        DB::table('aircraft')->where('id', $request->input('aircraft_id'))->update($update);

        return redirect()->route('aircraftweights.admin.index')
            ->with('success', "Aircraft updated with ICAO '{$icao}'.");
    }
}
