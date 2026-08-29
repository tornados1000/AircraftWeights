# AircraftWeights

phpVMS 7 module by German Sky Group. Holds a **reference table for aircraft
weights** (DOW, MZFW, MTOW, MLW) and writes them to the aircraft fleet.

> ⚠️ **All values in this module are in KILOGRAMS.**
> `phpvmsaircraft`, however, stores weights in **pounds** — the module
> converts on write (× 2.20462). Anyone entering values by hand enters
> kilograms.

## Two tables, and why there have to be two

| Table | Key | Purpose |
|---|---|---|
| `aw_icao_weights` | ICAO type | one weight set per type |
| `aw_subfleet_weights` | Fleet | override, takes **priority** |

A type doesn't have one weight set, it has several. The 767-300F carries
309,000 lb MZFW, while the 767-300ER of the same ICAO type carries only
272,932. Serving both from the type table alone means one of them gets the
other's numbers.

The override works **per field**: a NULL field falls back to the type table.
So a fleet can let just the DOW differ while pulling the rest from the type —
exactly the normal case for a freighter, whose airframe limits stay the same
while only the empty weight is lower.

## The bug that made this module expensive

Until **v1.1.0**, `sync()` read **only** the type table and wrote it to
**every** aircraft of that type. The override table existed, was populated —
and was read by **not a single line of code**.

Every click on "Sync" therefore erased all freighter and variant differences
in the fleet. On GSG-Live, this resulted in **70 freighter aircraft carrying
the passenger weight set of their type**, promising more cargo than they
could actually lift — the FedEx 777F, for instance, showing 102 t of cargo
fare against 66.6 t of actual payload.

⚠️ **The damage was invisible**, because `DB::table()->update()` doesn't
touch the `updated_at` column. The rows looked unchanged. It was only
discovered through an independent fleet audit.

Since v1.1.0, `sync()` and `fixLbs()` read the override with priority — not
as a lock, but as the **source**. Sync therefore doesn't just leave freighters
alone, it actively keeps them correct.

## Usage

Admin area → *Aircraft Weights*.

- **Sync** — writes the reference values to all aircraft. Reports back how
  many of them came from a fleet override instead of the type table.
- **Fix units** (`fixLbs`) — repairs aircraft where kilograms ended up in the
  pounds field (or vice versa). This also respects the override; without that,
  this button would destroy exactly the freighter weights that Sync just set.

## Verification query to run before every change

A cargo fare must never promise more than the airframe can lift:

```sql
SELECT * FROM (
  SELECT al.icao airline, s.type, CAST(sf.capacity AS UNSIGNED) cgo_kg,
         ROUND(AVG((a.zfw-a.dow)*0.453592)) payload_kg
  FROM phpvmssubfleet_fare sf
  JOIN phpvmssubfleets s ON s.id=sf.subfleet_id
  JOIN phpvmsairlines al ON al.id=s.airline_id
  JOIN phpvmsaircraft a ON a.subfleet_id=s.id
  WHERE sf.fare_id=7 AND a.dow>0 AND a.zfw>0 GROUP BY s.id) x
WHERE cgo_kg > payload_kg ORDER BY (cgo_kg-payload_kg) DESC;
```

Empty result = OK.

## Installation

Copy the folder to `modules/AircraftWeights`, then:

```bash
php artisan migrate
php artisan cache:clear && php artisan view:clear && php artisan route:cache
```
