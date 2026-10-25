import { Howl } from 'howler';

/**
 * Audio del juego:
 *  - musica de fondo con Howler
 *  - motor: el WAV real (motor-loop.wav) en loop, con el pitch y el volumen
 *    siguiendo a la velocidad del auto
 *  - luces de salida: el audio del semaforo, con las 5 luces encendidas en
 *    el mismo instante en que suenan los tonos
 *  - efectos sueltos: KERS, patinazo, bandera, clic, choque
 *
 * Los navegadores bloquean el audio hasta el primer gesto del usuario, asi
 * que todo arranca recien cuando el jugador hace click.
 */

const MUTE_KEY = 'f1-muted';

export function createAudio() {
    let ctx = null;
    let muted = false;
    let theme = null;
    let unlocked = false;
    let lastSkid = 0;
    let lastFlag = 0;

    // Motor
    let engineBuffer = null;
    let engineSource = null;
    let engineGain = null;
    let engineLoading = false;

    // Luces de salida
    let lightsBuffer = null;
    let lightsTones = [];
    let lightsSource = null;
    let lightsHowl = null;
    let lightsTimers = [];

    try {
        muted = localStorage.getItem(MUTE_KEY) === '1';
    } catch (e) {
        muted = false;
    }

    const ensureContext = () => {
        if (!ctx) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return null;
            }
            ctx = new AudioContextClass();
        }

        if (ctx.state === 'suspended') {
            ctx.resume();
        }

        return ctx;
    };

    const makeNoiseBuffer = (context) => {
        const length = Math.floor(context.sampleRate * 2);
        const buffer = context.createBuffer(1, length, context.sampleRate);
        const data = buffer.getChannelData(0);

        for (let i = 0; i < length; i++) {
            data[i] = Math.random() * 2 - 1;
        }

        return buffer;
    };

    const fetchBuffer = async (url) => {
        const response = await fetch(url);

        if (!response.ok) {
            throw new Error('no se pudo cargar ' + url);
        }

        const raw = await response.arrayBuffer();
        const context = ensureContext();

        return context.decodeAudioData(raw);
    };

    /**
     * Detecta los tonos del audio de las luces por energia: cada pico es un
     * "beep" y cada beep prende una luz.
     */
    const detectarTones = (buffer) => {
        const data = buffer.getChannelData(0);
        const sr = buffer.sampleRate;
        const window = Math.max(1, Math.floor(sr * 0.02));
        const energies = [];

        for (let i = 0; i + window < data.length; i += window) {
            let sum = 0;
            for (let j = 0; j < window; j++) {
                sum += data[i + j] * data[i + j];
            }
            energies.push(Math.sqrt(sum / window));
        }

        const max = Math.max(...energies);
        const umbral = max * 0.22;
        const tones = [];
        let sonando = false;

        for (let i = 0; i < energies.length; i++) {
            if (! sonando && energies[i] > umbral) {
                tones.push((i * window) / sr);
                sonando = true;
            } else if (sonando && energies[i] < umbral * 0.5) {
                sonando = false;
            }
        }

        return tones;
    };

    const beep = (frequency, duration, volume = 0.12) => {
        const context = ensureContext();

        if (! context || muted) {
            return;
        }

        const osc = context.createOscillator();
        osc.type = 'triangle';
        osc.frequency.value = frequency;

        const gain = context.createGain();
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(volume, context.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + duration);

        osc.connect(gain).connect(context.destination);
        osc.start();
        osc.stop(context.currentTime + duration + 0.05);
    };

    const noiseBurst = (fromFreq, toFreq, duration, volume, type = 'bandpass', q = 1.2) => {
        const context = ensureContext();

        if (! context || muted) {
            return;
        }

        const src = context.createBufferSource();
        src.buffer = makeNoiseBuffer(context);

        const filter = context.createBiquadFilter();
        filter.type = type;
        filter.Q.value = q;
        filter.frequency.setValueAtTime(fromFreq, context.currentTime);
        filter.frequency.exponentialRampToValueAtTime(toFreq, context.currentTime + duration);

        const gain = context.createGain();
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(volume, context.currentTime + 0.04);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + duration);

        src.connect(filter).connect(gain).connect(context.destination);
        src.start();
        src.stop(context.currentTime + duration + 0.05);
    };

    const playTheme = () => {
        if (muted || theme) {
            return;
        }

        theme = new Howl({
            src: ['/audio/f1-theme.mp3'],
            loop: true,
            volume: 0.16,
            html5: false,
            onloaderror: () => {
                theme = null;
            },
        });

        theme.play();
    };

    const unlock = () => {
        if (unlocked) {
            return;
        }

        unlocked = true;
        ensureContext();
        playTheme();

        window.removeEventListener('pointerdown', unlock);
        window.removeEventListener('keydown', unlock);
    };

    window.addEventListener('pointerdown', unlock);
    window.addEventListener('keydown', unlock);

    return {
        isMuted: () => muted,

        /**
         * Precarga el motor y el audio de las luces. Se llama al montar la
         * pagina para que al largar la carrera ya esten listos.
         */
        preload: async () => {
            if (engineLoading) {
                return;
            }

            engineLoading = true;

            try {
                engineBuffer = await fetchBuffer('/audio/motor-loop.wav');
            } catch (e) {
                console.warn('no se pudo cargar el motor', e);
            }

            try {
                lightsBuffer = await fetchBuffer('/audio/luces%20semaforo.mp3');
                lightsTones = detectarTones(lightsBuffer);
            } catch (e) {
                console.warn('no se pudo cargar el semaforo', e);
            }

            engineLoading = false;
        },

        /**
         * Arranca el motor en loop. La velocidad cambia el pitch (playbackRate)
         * y el volumen.
         */
        startEngine: () => {
            const context = ensureContext();

            if (! context || ! engineBuffer || engineSource) {
                return;
            }

            engineSource = context.createBufferSource();
            engineSource.buffer = engineBuffer;
            engineSource.loop = true;

            engineGain = context.createGain();
            engineGain.gain.value = 0;

            engineSource.connect(engineGain).connect(context.destination);
            engineSource.start();
        },

        engine: (speed, gear, mode, boost, retired) => {
            if (! engineSource || ! engineGain) {
                return;
            }

            const now = engineSource.context.currentTime;
            const rate = retired ? 0.7 : 0.82 + Math.min(speed / 320, 0.45) + (boost ? 0.14 : 0);
            // Si el auto se salio, el motor queda en ralenti: no se apaga
            const volume = retired ? 0.14 : 0.42;

            engineSource.playbackRate.setTargetAtTime(rate, now, 0.12);
            engineGain.gain.setTargetAtTime(muted ? 0 : volume, now, 0.25);
        },

        stopEngine: () => {
            if (! engineSource || ! engineGain) {
                return;
            }

            const now = engineGain.context.currentTime;
            engineGain.gain.setTargetAtTime(0, now, 0.4);

            const source = engineSource;
            window.setTimeout(() => {
                try {
                    source.stop();
                } catch (e) {
                    // ya estaba detenido
                }
            }, 1500);

            engineSource = null;
            engineGain = null;
        },

        /**
         * Luces de salida: el audio arranca y cada luz se prende en el mismo
         * instante que suena su tono. `remainingMs` es lo que falta para la
         * salida: el audio se programo para que termine justo en ese momento.
         */
        startLights: (remainingMs, onLight, onGo) => {
            const context = ensureContext();

            this.cancelLights();

            // Duracion del audio: si no lo pudimos decodificar usamos Howler
            const duracion = lightsBuffer
                ? lightsBuffer.duration
                : (lightsHowl ? lightsHowl.duration() : 7.2);

            const delay = Math.max(0, remainingMs - duracion * 1000);

            if (! lightsBuffer && ! lightsHowl) {
                lightsHowl = new Howl({
                    src: ['/audio/luces%20semaforo.mp3'],
                    volume: 0.9,
                    onloaderror: () => {
                        lightsHowl = null;
                    },
                });
            }

            // Si no detectamos los tonos, las luces se reparten parejo
            const tonos = lightsTones.length >= 5
                ? lightsTones.slice(0, 5)
                : [0.08, 0.22, 0.36, 0.50, 0.64].map((f) => f * duracion);

            window.setTimeout(() => {
                if (lightsBuffer) {
                    if (! muted) {
                        lightsSource = context.createBufferSource();
                        lightsSource.buffer = lightsBuffer;
                        lightsSource.connect(context.destination);
                        lightsSource.start();
                    }
                } else {
                    lightsHowl?.play();
                }

                tonos.forEach((tono, indice) => {
                    lightsTimers.push(window.setTimeout(() => onLight?.(indice), tono * 1000));
                });

                lightsTimers.push(window.setTimeout(() => onGo?.(), duracion * 1000));
            }, delay);
        },

        cancelLights: () => {
            lightsTimers.forEach((timer) => window.clearTimeout(timer));
            lightsTimers = [];

            if (lightsSource) {
                try {
                    lightsSource.stop();
                } catch (e) {
                    // ya habia terminado
                }
                lightsSource = null;
            }

            lightsHowl?.stop();
        },

        kers: () => {
            noiseBurst(2800, 300, 0.5, 0.26);
        },

        skid: () => {
            const now = Date.now();

            if (now - lastSkid < 900) {
                return;
            }

            lastSkid = now;
            noiseBurst(1700, 3200, 0.35, 0.18, 'bandpass', 8);
        },

        flag: () => {
            const now = Date.now();

            if (now - lastFlag < 2000) {
                return;
            }

            lastFlag = now;
            beep(880, 0.12, 0.14);
            window.setTimeout(() => beep(1174, 0.24, 0.14), 140);
        },

        /**
         * Chocrhe: chirrido de neumaticos seguido del golpe.
         * El motor NO se apaga: queda en ralenti.
         */
        crash: () => {
            noiseBurst(2600, 900, 0.55, 0.3, 'bandpass', 6);
            window.setTimeout(() => {
                noiseBurst(1200, 120, 0.7, 0.34, 'lowpass', 1);
                beep(70, 0.5, 0.22);
            }, 420);
        },

        retire: () => {
            this.crash();
        },

        click: () => {
            beep(660, 0.05, 0.07);
        },

        toggleMute: () => {
            muted = ! muted;

            try {
                localStorage.setItem(MUTE_KEY, muted ? '1' : '0');
            } catch (e) {
                // sin localStorage el mute solo dura la sesion
            }

            if (muted) {
                theme?.pause();

                if (engineGain) {
                    engineGain.gain.setTargetAtTime(0, engineGain.context.currentTime, 0.05);
                }
            } else {
                theme?.play();
            }

            return muted;
        },
    };
}
