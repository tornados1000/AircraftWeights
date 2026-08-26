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
     * Flotten-Uebersteuerungen aus `aw_subfleet_weights`, nach subfleet_id.
     *
     * WARUM DAS SEIN MUSS — der teuerste Fehler dieses Moduls:
     *
     * Die ICAO-Tabelle kennt genau EINEN Gewichtssatz je Muster. Ein Muster hat
     * aber mehrere: Die 767-300F traegt 309.000 lb MZFW, die 767-300ER derselben
     * ICAO nur 272.932. `sync()` schrieb bis zum 26.08.2026 den Musterwert auf
     * JEDES Flugzeug — und hat damit jedes Mal saemtliche Frachter- und
     * Variantenunterschiede im Bestand ausradiert.
     *
     * Der Schaden war unsichtbar, weil `DB::table()->update()` `updated_at` nicht
     * anfasst: Die Zeilen sahen unveraendert aus. Aufgefallen ist es erst ueber
     * die Flottenpruefung — 70 Frachter versprachen mehr Fracht, als sie heben
     * konnten (777F: 102 t Fare gegen 66,6 t Nutzlast), weil sie den
     * Passagier-Gewichtssatz ihres Musters trugen.
     *
     * Die Tabelle `aw_subfleet_weights` war fuer genau diesen Fall angelegt —
     * und wurde von KEINER Codestelle gelesen. Sie ist jetzt angeschlossen: Wo
     * eine Flotte einen Eintrag hat, gewinnt er. Nicht als Sperre, sondern als
     * Quelle — damit `sync()` die Frachter nicht nur in Ruhe laesst, sondern
     * aktiv richtig haelt.
     *
     * @return array<int,object>
     */
    private function subfleetOverrides(): array
    {
        return DB::table('aw_subfleet_weights')->get()->keyBy('subfleet_id')->all();
    }

    /**
     * Welcher Gewichtssatz gilt fuer dieses Flugzeug — in KG?
     *
     * Reihenfolge: Flotten-Uebersteuerung vor Muster-Referenz, Feld fuer Feld.
     * Eine Uebersteuerung darf also auch nur einzelne Werte setzen.
     *
     * @return array{dow:?float,mzfw:?float,mtow:?float,mlw:?float}|null
     */
    private function referenzFuer(object $aircraft, ?object $icao, array $overrides): ?array
    {
        $ov = $overrides[$aircraft->subfleet_id ?? 0] ?? null;

        if ($ov === null && $icao === null) {
            return null;
        }

        $feld = static function (string $k) use ($ov, $icao): ?float {
            if ($ov !== null && isset($ov->$k) && $ov->$k !== null && $ov->$k !== '') {
                return (float) $ov->$k;
            }

            return ($icao !== null && $icao->$k !== null) ? (float) $icao->$k : null;
        };

        return [
            'dow'  => $feld('dow'),
            'mzfw' => $feld('mzfw'),
            'mtow' => $feld('mtow'),
            'mlw'  => $feld('mlw'),
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

        return redirect()->route('aircraftweights.admin.index')->with('success', 'Gespeichert.');
    }

    public function delete(int $id)
    {
        AW_IcaoWeight::findOrFail($id)->delete();
        return redirect()->route('aircraftweights.admin.index')->with('success', 'Gelöscht.');
    }

    public function sync()
    {
        $colMap      = $this->weightColumns();
        $weightIndex = AW_IcaoWeight::all()->keyBy(fn($w) => strtoupper($w->icao));
        $overrides   = $this->subfleetOverrides();
        $aircraft    = DB::table('aircraft')->whereNotNull('icao')->where('icao', '!=', '')->get();

        $synced      = 0;
        $missing     = 0;
        $ausFlotte   = 0;

        foreach ($aircraft as $ac) {
            $icao = $weightIndex[strtoupper($ac->icao)] ?? null;
            $ref  = $this->referenzFuer($ac, $icao, $overrides);

            if ($ref === null) {
                $missing++;
                continue;
            }

            if (isset($overrides[$ac->subfleet_id ?? 0])) {
                $ausFlotte++;
            }

            $update = [];
            if ($colMap['dow']  && $ref['dow']  !== null) $update[$colMap['dow']]  = $this->kgToLbs($ref['dow']);
            if ($colMap['mzfw'] && $ref['mzfw'] !== null) $update[$colMap['mzfw']] = $this->kgToLbs($ref['mzfw']);
            if ($colMap['mtow'] && $ref['mtow'] !== null) $update[$colMap['mtow']] = $this->kgToLbs($ref['mtow']);
            if ($colMap['mlw']  && $ref['mlw']  !== null) $update[$colMap['mlw']]  = $this->kgToLbs($ref['mlw']);

            if ($update) {
                DB::table('aircraft')->where('id', $ac->id)->update($update);
            }

            $synced++;
        }

        $msg = "Sync: {$synced} Flugzeuge aktualisiert, {$missing} ohne Referenz.";
        if ($ausFlotte) {
            $msg .= " Davon {$ausFlotte} aus einer Flotten-Uebersteuerung statt aus der Mustertabelle.";
        }

        return redirect()->route('aircraftweights.admin.index')->with('success', $msg);
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
            // Auch hier gilt die Flotten-Uebersteuerung vor der Mustertabelle —
            // sonst korrigiert dieser Knopf die Einheiten und zerstoert dabei
            // genau die Frachtergewichte, die sync() gerade sauber gesetzt hat.
            $icao = $weightIndex[strtoupper($ac->icao)] ?? null;
            $ref  = $this->referenzFuer($ac, $icao, $overrides);

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

        $msg = "Einheiten-Korrektur: {$converted} Flugzeuge korrigiert";
        if ($skipped) $msg .= ", {$skipped} bereits korrekt";
        if ($noRef)   $msg .= ", {$noRef} ohne ICAO-Referenz";

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
                ->with('error', "ICAO '{$icao}' nicht in der Gewichtstabelle gefunden.");
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
            ->with('success', "Flugzeug mit ICAO '{$icao}' aktualisiert.");
    }
}
