<?php

namespace Modules\AircraftWeights\Models;

use Illuminate\Database\Eloquent\Model;

class AW_IcaoWeight extends Model
{
    protected $table = 'aw_icao_weights';

    protected $fillable = [
        'icao',
        'engine_type',
        'dow',
        'mzfw',
        'mtow',
        'mlw',
        'source_url',
        'note',
        'fallback',
    ];

    protected $casts = [
        'dow'      => 'integer',
        'mzfw'     => 'integer',
        'mtow'     => 'integer',
        'mlw'      => 'integer',
        'fallback' => 'boolean',
    ];

    /**
     * Look up weights by ICAO type code (case-insensitive).
     */
    public static function findByIcao(string $icao): ?self
    {
        return static::whereRaw('UPPER(icao) = ?', [strtoupper($icao)])->first();
    }
}
