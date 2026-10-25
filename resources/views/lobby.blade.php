<!DOCTYPE html>
<html lang="es" class="bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite(['resources/css/app.css'])
    <title>Lobby - F1 Manager</title>
</head>
<body class="p-6">
    @php $me = auth('player')->user(); $myLivery = $me->livery ?? ['primary' => '#e10600', 'secondary' => '#111827']; @endphp

    <!-- Header: nombre + livrea del piloto -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Salas disponibles</h1>
        <div class="flex items-center gap-3">
            <span class="text-sm text-slate-400">Piloto:</span>
            <span id="player-name" class="font-bold text-sky-400">{{ $me->display_name }}</span>
            <button id="edit-name-btn" class="text-xs text-slate-500 hover:text-sky-400" title="Cambiar nombre">✏️</button>
            <button id="edit-livery-btn" class="text-xs bg-slate-800 px-3 py-1.5 rounded hover:bg-slate-700" title="Mi livrea">🎨 Livrea</button>
        </div>
    </div>

    @if(session('status'))
        <div class="bg-emerald-900/40 border border-emerald-700 text-emerald-300 text-sm px-4 py-2 rounded mb-4">{{ session('status') }}</div>
    @endif

    <!-- Modal: editar nombre -->
    <div id="name-modal" style="display:none" class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center">
        <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-80">
            <h3 class="font-bold mb-4">Tu nombre de piloto</h3>
            <form action="{{ route('player.name') }}" method="POST">
                @csrf
                <input name="display_name" value="{{ $me->display_name }}" minlength="2" maxlength="20" required
                       class="bg-slate-800 border border-slate-600 p-2 rounded w-full mb-3">
                @error('display_name')
                    <p class="text-red-400 text-xs mb-2">{{ $message }}</p>
                @enderror
                <div class="flex gap-2 justify-end">
                    <button type="button" class="close-modal px-3 py-1.5 rounded text-sm text-slate-400 hover:text-white">Cancelar</button>
                    <button class="bg-sky-600 px-4 py-1.5 rounded text-sm hover:bg-sky-500">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: editor de livrea -->
    <div id="livery-modal" style="display:none" class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center">
        <div class="bg-slate-900 border border-slate-700 rounded-xl p-6 w-96">
            <h3 class="font-bold mb-4">Elige tu livrea</h3>
            <form action="{{ route('player.livery') }}" method="POST" id="livery-form">
                @csrf
                <input type="hidden" name="primary" id="livery-primary" value="{{ $myLivery['primary'] ?? '#e10600' }}">
                <input type="hidden" name="secondary" id="livery-secondary" value="{{ $myLivery['secondary'] ?? '#111827' }}">

                <div id="livery-swatches" class="grid grid-cols-4 gap-2 mb-4">
                    <!-- JS fills this -->
                </div>

                <div class="flex items-center gap-3 mb-4">
                    <span class="text-xs text-slate-400">Vista previa:</span>
                    <div id="livery-preview" style="width: 66px; height: 36px; position: relative;">
                        <div id="preview-body" style="position:absolute; inset:0; border-radius: 6px;"></div>
                        <div id="preview-detail" style="position:absolute; top: 2px; left: 2px; width: 18px; height: 32px; border-radius: 4px;"></div>
                        <div id="preview-nose" style="position:absolute; top: 12px; right: 0; width: 20px; height: 12px; clip-path: polygon(0 0, 100% 50%, 0 100%);"></div>
                    </div>
                </div>

                <div class="flex gap-2 justify-end">
                    <button type="button" class="close-modal px-3 py-1.5 rounded text-sm text-slate-400 hover:text-white">Cancelar</button>
                    <button class="bg-sky-600 px-4 py-1.5 rounded text-sm hover:bg-sky-500">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="grid gap-4">
        @foreach($rooms as $room)
            @php $weather = \App\Services\WeatherService::fromSeed((int) $room->weather_seed); @endphp
            <div class="border border-slate-800 p-4 rounded flex justify-between items-center">
                <div>
                    <h2 class="font-bold">{{ $room->name }}</h2>
                    <p class="text-sm text-slate-400">{{ $room->track->name }} - {{ $room->lap_count }} vueltas</p>
                    <p class="text-sm mt-1" style="color: #cbd5e1;">{{ $weather['emoji'] }} {{ $weather['name'] }}</p>
                </div>
                <form action="{{ route('rooms.join', $room) }}" method="POST">
                    @csrf
                    <button class="bg-sky-600 px-4 py-2 rounded hover:bg-sky-500">Unirse</button>
                </form>
            </div>
        @endforeach
    </div>

    <form action="{{ route('rooms.store') }}" method="POST" class="mt-8 border-t border-slate-800 pt-6">
        @csrf
        <h2 class="font-bold mb-4">Crear sala</h2>
        <div class="flex gap-4 flex-wrap">
            <input name="name" placeholder="Nombre de sala" class="bg-slate-900 border border-slate-700 p-2 rounded w-full">
            <select name="track_id" class="bg-slate-900 border border-slate-700 p-2 rounded">
                @foreach($tracks as $track)
                    <option value="{{ $track->id }}">{{ $track->name }}</option>
                @endforeach
            </select>
            <input name="lap_count" type="number" min="2" max="10" value="5" class="bg-slate-900 border border-slate-700 p-2 rounded w-16">
            <select name="type" class="bg-slate-900 border border-slate-700 p-2 rounded">
                <option value="sprint">Sprint</option>
                <option value="practice">Práctica</option>
            </select>
            <select name="weather" class="bg-slate-900 border border-slate-700 p-2 rounded">
                <option value="">Clima: Aleatorio</option>
                @foreach($weathers as $key => $weather)
                    <option value="{{ $key }}">{{ \App\Services\WeatherService::EMOJI[$key] }} {{ $weather['name'] }}</option>
                @endforeach
            </select>
            <button class="bg-emerald-600 px-4 py-2 rounded">Crear</button>
        </div>
    </form>

    <script>
        const LIVERY_PRESETS = [
            { name: 'Ferrari',     primary: '#e10600', secondary: '#111827' },
            { name: 'Red Bull',    primary: '#1e41ff', secondary: '#f97316' },
            { name: 'Mercedes',   primary: '#00d2be', secondary: '#111827' },
            { name: 'McLaren',    primary: '#ff8000', secondary: '#4dd0e1' },
            { name: 'Alpine',     primary: '#0090c6', secondary: '#f91572' },
            { name: 'Aston',      primary: '#229971', secondary: '#ffd700' },
            { name: 'Williams',   primary: '#1868db', secondary: '#ffffff' },
            { name: 'RB',         primary: '#6692ff', secondary: '#ffffff' },
            { name: 'Kick',       primary: '#52e252', secondary: '#111827' },
            { name: 'Haas',       primary: '#b6babd', secondary: '#e10600' },
            { name: 'Blanco',     primary: '#f8fafc', secondary: '#0f172a' },
            { name: 'Negro',      primary: '#111827', secondary: '#e10600' },
        ];

        // Modal helpers
        const openModal = (id) => document.getElementById(id).style.display = 'flex';
        const closeModals = () => {
            document.getElementById('name-modal').style.display = 'none';
            document.getElementById('livery-modal').style.display = 'none';
        };
        document.getElementById('edit-name-btn').addEventListener('click', () => openModal('name-modal'));
        document.getElementById('edit-livery-btn').addEventListener('click', () => { renderSwatches(); openModal('livery-modal'); });
        document.querySelectorAll('.close-modal').forEach(btn => btn.addEventListener('click', closeModals));
        document.querySelectorAll('.fixed').forEach(el => el.addEventListener('click', (e) => { if (e.target === el) closeModals(); }));

        // Swatches
        const swatchContainer = document.getElementById('livery-swatches');
        const primaryInput = document.getElementById('livery-primary');
        const secondaryInput = document.getElementById('livery-secondary');
        const previewBody = document.getElementById('preview-body');
        const previewDetail = document.getElementById('preview-detail');
        const previewNose = document.getElementById('preview-nose');

        const updatePreview = () => {
            previewBody.style.background = primaryInput.value;
            previewDetail.style.background = secondaryInput.value;
            previewNose.style.background = primaryInput.value;
        };

        const renderSwatches = () => {
            swatchContainer.innerHTML = '';
            LIVERY_PRESETS.forEach((preset) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.title = preset.name;
                btn.style.cssText = 'width:100%; aspect-ratio:1.6; border-radius:6px; border:2px solid transparent; cursor:pointer; position:relative; overflow:hidden;';
                btn.innerHTML = `
                    <div style="position:absolute; inset:0; background:${preset.primary};"></div>
                    <div style="position:absolute; top:2px; left:2px; width:30%; height:calc(100% - 4px); background:${preset.secondary}; border-radius:3px;"></div>
                    <div style="position:absolute; top:0; right:0; width:0; height:0; border-top:${'16px'} solid transparent; border-bottom:${'16px'} solid transparent; border-right:0; border-left:14px solid ${preset.primary};"></div>
                `;
                if (preset.primary === primaryInput.value && preset.secondary === secondaryInput.value) {
                    btn.style.borderColor = '#38bdf8';
                }
                btn.addEventListener('click', () => {
                    primaryInput.value = preset.primary;
                    secondaryInput.value = preset.secondary;
                    updatePreview();
                    renderSwatches();
                });
                swatchContainer.appendChild(btn);
            });
            updatePreview();
        };

        renderSwatches();
    </script>
</body>
</html>
