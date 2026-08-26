# AircraftWeights

phpVMS-7-Modul der German Sky Group. Hält eine **Referenztabelle für
Flugzeuggewichte** (DOW, MZFW, MTOW, MLW) und schreibt sie auf den
Flugzeugbestand.

> ⚠️ **Alle Werte in diesem Modul stehen in KILOGRAMM.**
> `phpvmsaircraft` speichert dagegen in **Pfund** — das Modul rechnet beim
> Schreiben um (× 2,20462). Wer Werte von Hand einträgt, trägt Kilogramm ein.

## Zwei Tabellen, und warum es zwei sein müssen

| Tabelle | Schlüssel | Zweck |
|---|---|---|
| `aw_icao_weights` | ICAO-Muster | ein Gewichtssatz je Muster |
| `aw_subfleet_weights` | Flotte | Übersteuerung, hat **Vorrang** |

Ein Muster hat nicht einen Gewichtssatz, sondern mehrere. Die 767-300F trägt
309.000 lb MZFW, die 767-300ER derselben ICAO nur 272.932. Wer beide aus der
Mustertabelle bedient, gibt einer von beiden die Zahlen der anderen.

Die Übersteuerung wirkt **feldweise**: Ein NULL-Feld fällt auf die
Mustertabelle zurück. Eine Flotte kann also nur das DOW abweichen lassen und
den Rest aus dem Muster ziehen — genau der Normalfall beim Frachter, dessen
Zellenlimits gleich bleiben und nur das Leergewicht niedriger ist.

## Der Fehler, der dieses Modul teuer gemacht hat

Bis **v1.1.0** las `sync()` **nur** die Mustertabelle und schrieb sie auf
**jedes** Flugzeug des Musters. Die Übersteuerungstabelle existierte, war
befüllt — und wurde von **keiner einzigen Codestelle gelesen**.

Jeder Klick auf „Sync" löschte damit sämtliche Frachter- und
Variantenunterschiede im Bestand. Auf GSG-Live führte das dazu, dass **70
Frachtflugzeuge den Passagier-Gewichtssatz ihres Musters trugen** und damit
mehr Fracht versprachen, als sie heben konnten — die FedEx-777F etwa 102 t
Fracht-Fare gegen 66,6 t tatsächliche Nutzlast.

⚠️ **Der Schaden war unsichtbar**, weil `DB::table()->update()` die Spalte
`updated_at` nicht anfasst. Die Zeilen sahen unverändert aus. Gefunden wurde es
erst über eine unabhängige Flottenprüfung.

Seit v1.1.0 lesen `sync()` und `fixLbs()` die Übersteuerung mit Vorrang — nicht
als Sperre, sondern als **Quelle**. Sync lässt die Frachter also nicht nur in
Ruhe, sondern hält sie aktiv richtig.

## Bedienung

Adminbereich → *Aircraft Weights*.

- **Sync** — schreibt die Referenzwerte auf alle Flugzeuge. Meldet zurück,
  wie viele davon aus einer Flotten-Übersteuerung statt aus der Mustertabelle
  kamen.
- **Einheiten korrigieren** (`fixLbs`) — repariert Flugzeuge, bei denen
  Kilogramm im Pfund-Feld gelandet sind (oder umgekehrt). Beachtet die
  Übersteuerung ebenfalls; ohne das würde dieser Knopf genau die
  Frachtergewichte zerstören, die Sync gerade gesetzt hat.

## Prüfrezept vor jeder Änderung

Eine Fracht-Fare darf nie mehr versprechen, als die Zelle hebt:

```sql
SELECT * FROM (
  SELECT al.icao airline, s.type, CAST(sf.capacity AS UNSIGNED) cgo_kg,
         ROUND(AVG((a.zfw-a.dow)*0.453592)) nutzlast_kg
  FROM phpvmssubfleet_fare sf
  JOIN phpvmssubfleets s ON s.id=sf.subfleet_id
  JOIN phpvmsairlines al ON al.id=s.airline_id
  JOIN phpvmsaircraft a ON a.subfleet_id=s.id
  WHERE sf.fare_id=7 AND a.dow>0 AND a.zfw>0 GROUP BY s.id) x
WHERE cgo_kg > nutzlast_kg ORDER BY (cgo_kg-nutzlast_kg) DESC;
```

Leeres Ergebnis = in Ordnung.

## Installation

Ordner nach `modules/AircraftWeights`, dann:

```bash
php artisan migrate
php artisan cache:clear && php artisan view:clear && php artisan route:cache
```
