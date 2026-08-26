@extends('admin.app')

@section('title', 'Aircraft Weights')

@section('content')
<div class="container-fluid">

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show py-2">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        {{ session('error') }}
    </div>
    @endif

    {{-- ICAO GEWICHTSTABELLE --}}
    <div class="card">
        <div class="card-header py-2">
            <strong>ICAO Gewichtstabelle</strong>
            <span class="ml-3 font-weight-bold text-danger" style="font-size:1rem">
                ⚠ ALLE WERTE MÜSSEN IN KILOGRAMM (KG) EINGEGEBEN WERDEN — KEINE LBS!
            </span>
            <small class="text-muted ml-2">MZFW leer = nicht veröffentlicht</small>
        </div>
        <div class="card-body py-2">
            <form method="POST" action="{{ route('aircraftweights.admin.save') }}">
                @csrf
                <input type="hidden" name="id" id="edit_id">
                <div class="form-row align-items-end">
                    <div class="col-auto">
                        <label class="mb-1 small">ICAO</label>
                        <input type="text" name="icao" id="edit_icao"
                            class="form-control form-control-sm" style="width:75px"
                            placeholder="A320" required>
                    </div>
                    <div class="col-auto">
                        <label class="mb-1 small">Engine</label>
                        <input type="text" name="engine_type" id="edit_engine"
                            class="form-control form-control-sm" style="width:110px"
                            placeholder="CFM/IAE">
                    </div>
                    <div class="col-auto">
                        <label class="mb-1 small">DOW (kg)</label>
                        <input type="number" name="dow" id="edit_dow"
                            class="form-control form-control-sm" style="width:105px">
                    </div>
                    <div class="col-auto">
                        <label class="mb-1 small">MZFW (kg)</label>
                        <input type="number" name="mzfw" id="edit_mzfw"
                            class="form-control form-control-sm" style="width:105px">
                    </div>
                    <div class="col-auto">
                        <label class="mb-1 small">MTOW (kg)</label>
                        <input type="number" name="mtow" id="edit_mtow"
                            class="form-control form-control-sm" style="width:105px">
                    </div>
                    <div class="col-auto">
                        <label class="mb-1 small">MLW (kg)</label>
                        <input type="number" name="mlw" id="edit_mlw"
                            class="form-control form-control-sm" style="width:105px">
                    </div>
                    <div class="col">
                        <label class="mb-1 small">Source URL</label>
                        <input type="text" name="source_url" id="edit_source"
                            class="form-control form-control-sm" placeholder="https://...">
                    </div>
                    <div class="col-auto">
                        <label class="mb-1 small">Notiz</label>
                        <input type="text" name="note" id="edit_note"
                            class="form-control form-control-sm" style="width:130px">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-success btn-sm">Speichern</button>
                        <button type="button" class="btn btn-secondary btn-sm ml-1" onclick="clearForm()">Neu</button>
                    </div>
                </div>
            </form>

            <hr class="my-2">

            <div class="table-responsive">
                <table class="table table-sm table-hover table-bordered mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>ID</th>
                            <th>ICAO</th>
                            <th>Engine</th>
                            <th class="text-right">DOW</th>
                            <th class="text-right">MZFW</th>
                            <th class="text-right">MTOW</th>
                            <th class="text-right">MLW</th>
                            <th>Source</th>
                            <th>Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($weights as $w)
                        <tr>
                            <td class="text-muted">{{ $w->id }}</td>
                            <td><strong>{{ $w->icao }}</strong></td>
                            <td>{{ $w->engine_type }}</td>
                            <td class="text-right">{{ $w->dow ? number_format($w->dow) : '' }}</td>
                            <td class="text-right {{ $w->mzfw ? 'text-warning' : 'text-muted' }}">
                                {{ $w->mzfw ? number_format($w->mzfw) : '—' }}
                            </td>
                            <td class="text-right">{{ $w->mtow ? number_format($w->mtow) : '' }}</td>
                            <td class="text-right">{{ $w->mlw ? number_format($w->mlw) : '' }}</td>
                            <td>
                                @if($w->source_url)
                                <a href="{{ $w->source_url }}" target="_blank">Link</a>
                                @endif
                            </td>
                            <td style="white-space:nowrap">
                                <button class="btn btn-xs btn-primary"
                                    onclick="editRow({{ $w->id }},'{{ $w->icao }}','{{ addslashes($w->engine_type) }}','{{ $w->dow }}','{{ $w->mzfw }}','{{ $w->mtow }}','{{ $w->mlw }}','{{ addslashes($w->source_url) }}','{{ addslashes($w->note) }}')">
                                    Bearbeiten
                                </button>
                                <form method="POST" action="{{ route('aircraftweights.admin.delete', $w->id) }}"
                                    style="display:inline"
                                    onsubmit="return confirm('{{ $w->icao }} löschen?')">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-danger">Löschen</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- FLUGZEUG ABGLEICH --}}
    <div class="card mt-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <div>
                <strong>Flugzeug Abgleich</strong>
                <small class="text-muted ml-2">Schreibt DOW/MZFW/MTOW/MLW direkt in die phpVMS Flugzeug-Datensätze</small>
            </div>
            <div class="d-flex">
                <form method="POST" action="{{ route('aircraftweights.admin.fix_lbs') }}"
                    onsubmit="return confirm('Alle Flugzeuge auf lbs-Fehler prüfen und ggf. nach kg konvertieren?')"
                    class="mr-2">
                    @csrf
                    <button type="submit" class="btn btn-warning btn-sm">lbs → kg prüfen &amp; korrigieren</button>
                </form>
                <form method="POST" action="{{ route('aircraftweights.admin.sync') }}"
                    onsubmit="return confirm('Gewichte für alle Flugzeuge mit ICAO-Treffer schreiben?')">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Alle synchronisieren</button>
                </form>
            </div>
        </div>
        <div class="card-body py-2">

            {{-- Datalist for ICAO autocomplete --}}
            <datalist id="icao-list">
                @foreach($weights as $w)
                <option value="{{ $w->icao }}">{{ $w->icao }} – {{ $w->engine_type }}</option>
                @endforeach
            </datalist>

            <div class="table-responsive">
                <table class="table table-sm table-hover table-bordered mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Registration</th>
                            <th>Subfleet</th>
                            <th>ICAO zuweisen</th>
                            <th class="text-right">DOW</th>
                            <th class="text-right">MZFW</th>
                            <th class="text-right">MTOW</th>
                            <th class="text-right">MLW</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($aircraft as $ac)
                        <tr>
                            <td><strong>{{ $ac->registration }}</strong></td>
                            <td class="text-muted">{{ $ac->subfleet_name }}</td>
                            <td>
                                <form method="POST" action="{{ route('aircraftweights.admin.assign') }}" class="form-inline">
                                    @csrf
                                    <input type="hidden" name="aircraft_id" value="{{ $ac->id }}">
                                    <input type="text" name="icao"
                                        list="icao-list"
                                        class="form-control form-control-sm mr-1"
                                        style="width:90px"
                                        value="{{ $ac->icao ?? '' }}"
                                        placeholder="ICAO"
                                        required>
                                    <button type="submit" class="btn btn-xs btn-primary">OK</button>
                                </form>
                            </td>
                            @if($ac->_dow || $ac->_mtow)
                            <td class="text-right">{{ $ac->_dow ? number_format($ac->_dow) : '' }}</td>
                            <td class="text-right {{ !$ac->_mzfw ? 'text-muted' : '' }}">
                                {{ $ac->_mzfw ? number_format($ac->_mzfw) : '—' }}
                            </td>
                            <td class="text-right">{{ $ac->_mtow ? number_format($ac->_mtow) : '' }}</td>
                            <td class="text-right">{{ $ac->_mlw ? number_format($ac->_mlw) : '' }}</td>
                            <td><span class="badge badge-success">Gesetzt</span></td>
                            @elseif($ac->icaoWeight)
                            <td class="text-right text-muted">{{ number_format($ac->icaoWeight->dow) }}</td>
                            <td class="text-right text-muted">{{ $ac->icaoWeight->mzfw ? number_format($ac->icaoWeight->mzfw) : '—' }}</td>
                            <td class="text-right text-muted">{{ number_format($ac->icaoWeight->mtow) }}</td>
                            <td class="text-right text-muted">{{ number_format($ac->icaoWeight->mlw) }}</td>
                            <td><span class="badge badge-warning">ICAO-Treffer (nicht sync)</span></td>
                            @else
                            <td colspan="4"></td>
                            <td><span class="badge badge-danger">Kein Treffer</span></td>
                            @endif
                        </tr>
                        @empty
                        <tr><td colspan="8" class="text-muted">Keine Flugzeuge gefunden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection

@section('scripts')
@parent
<script>
function editRow(id, icao, engine, dow, mzfw, mtow, mlw, source, note) {
    document.getElementById('edit_id').value     = id;
    document.getElementById('edit_icao').value   = icao;
    document.getElementById('edit_engine').value = engine;
    document.getElementById('edit_dow').value    = dow;
    document.getElementById('edit_mzfw').value   = mzfw;
    document.getElementById('edit_mtow').value   = mtow;
    document.getElementById('edit_mlw').value    = mlw;
    document.getElementById('edit_source').value = source;
    document.getElementById('edit_note').value   = note;
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function clearForm() {
    ['edit_id','edit_icao','edit_engine','edit_dow','edit_mzfw',
     'edit_mtow','edit_mlw','edit_source','edit_note'].forEach(id => {
        document.getElementById(id).value = '';
    });
}
</script>
@endsection
