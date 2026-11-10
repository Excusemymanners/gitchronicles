/**
 * Muzica de fundal a video-ului, compusă de acest program (nu e o piesă luată de undeva, deci nu are
 * probleme de licență): o buclă electronică liniștită în La minor, 104 bătăi pe minut, cu acordurile
 * La minor – Fa major – Do major – Sol major, câte unul pe măsură.
 *
 * Straturile, de jos în sus: o tobă mare moale pe fiecare bătaie, un bas pe nota de bază a acordului,
 * acordurile ținute (pad), un arpegiu scurt (notele acordului pe rând) cu ecou și un hi-hat discret.
 * Prima măsură nu are tobe (intrarea), iar ultima secundă și jumătate se stinge treptat.
 *
 * Totul e calculat din timp, cu un generator de zgomot cu sămânță fixă: aceeași durată dă mereu
 * același fișier. Vocea se pune peste muzică în ffmpeg (AudioMixer în Laravel), care o și coboară
 * cât timp vorbește vocea.
 *
 * Folosire: node music.mjs <durata în secunde> <fișier.wav>
 */

import { writeFileSync } from 'node:fs';

const [durationArgument, outputPath] = process.argv.slice(2);
const duration = Number(durationArgument);

if (!Number.isFinite(duration) || duration <= 0 || duration > 600 || !outputPath) {
    console.error('Folosire: node music.mjs <durata în secunde, cel mult 600> <fișier.wav>');
    process.exit(2);
}

const SAMPLE_RATE = 44100;
const BPM = 104;
const BEAT = 60 / BPM;
const BAR = BEAT * 4;
const FADE_IN = 0.6;
const FADE_OUT = 1.5;

/** Frecvența unei note MIDI (69 = La 440 Hz). */
const frequency = (note) => 440 * 2 ** ((note - 69) / 12);

/** Acordurile, ca note MIDI: La minor, Fa major, Do major, Sol major. */
const CHORDS = [
    [57, 60, 64, 67],
    [53, 57, 60, 64],
    [48, 52, 55, 60],
    [55, 59, 62, 67],
];

/** Ordinea notelor în arpegiu (indici în acord, la o octavă mai sus). */
const ARPEGGIO = [0, 1, 2, 3, 2, 1, 2, 3];

const length = Math.ceil(duration * SAMPLE_RATE);
const left = new Float32Array(length);
const right = new Float32Array(length);

/** Zgomot cu sămânță fixă (un generator liniar congruențial): același rezultat la fiecare rulare. */
let seed = 20261110;
const noise = () => {
    seed = (seed * 1664525 + 1013904223) >>> 0;

    return seed / 2 ** 31 - 1;
};

/** Adaugă un sunet (o funcție de timpul scurs de la începutul lui) în ambele canale, cu panoramare. */
function addSound(start, soundLength, pan, sample) {
    const first = Math.max(0, Math.floor(start * SAMPLE_RATE));
    const last = Math.min(length, Math.floor((start + soundLength) * SAMPLE_RATE));
    const leftGain = Math.cos(((pan + 1) * Math.PI) / 4);
    const rightGain = Math.sin(((pan + 1) * Math.PI) / 4);

    for (let index = first; index < last; index += 1) {
        const value = sample(index / SAMPLE_RATE - start);
        left[index] += value * leftGain;
        right[index] += value * rightGain;
    }
}

/** Un ton cald: fundamentala și două armonice slabe (ca un sintetizator cu filtrul închis). */
const warmTone = (hz, t) => Math.sin(2 * Math.PI * hz * t) + 0.3 * Math.sin(4 * Math.PI * hz * t) + 0.12 * Math.sin(6 * Math.PI * hz * t);

const bars = Math.ceil(duration / BAR);

for (let bar = 0; bar < bars; bar += 1) {
    const barStart = bar * BAR;
    const chord = CHORDS[bar % CHORDS.length];

    // Pad: acordul ținut toată măsura, cu intrare și ieșire lente, care se suprapun cu măsura vecină.
    chord.forEach((note, voice) => {
        const hz = frequency(note);
        const detune = 1 + (voice % 2 === 0 ? 0.0025 : -0.0025);
        addSound(barStart, BAR + 0.6, voice % 2 === 0 ? -0.35 : 0.35, (t) => {
            const envelope = Math.min(1, t / 0.5) * Math.min(1, (BAR + 0.6 - t) / 0.6);

            return 0.045 * envelope * (warmTone(hz, t) + warmTone(hz * detune, t)) * 0.5;
        });
    });

    for (let beat = 0; beat < 4; beat += 1) {
        const beatStart = barStart + beat * BEAT;
        const hasDrums = bar > 0;

        // Basul: nota de bază a acordului, două octave mai jos, pe fiecare bătaie.
        addSound(beatStart, BEAT, 0, (t) => 0.16 * Math.exp(-t * 3.2) * Math.min(1, t / 0.01) * Math.sin(2 * Math.PI * frequency(chord[0] - 24) * t));

        // Toba mare: o sinusoidă care coboară repede de la 140 la 50 Hz.
        if (hasDrums) {
            addSound(beatStart, 0.35, 0, (t) => {
                const phase = 2 * Math.PI * (50 * t + (90 / 22) * (1 - Math.exp(-22 * t)));

                return 0.32 * Math.exp(-t * 11) * Math.sin(phase);
            });
        }

        // Hi-hat-ul: zgomot foarte scurt, pe contratimp (între bătăi).
        if (hasDrums) {
            let previous = 0;
            addSound(beatStart + BEAT / 2, 0.08, 0.25, (t) => {
                const value = noise();
                const highPassed = value - previous;
                previous = value;

                return 0.035 * Math.exp(-t * 55) * highPassed;
            });
        }

        // Arpegiul: două note pe bătaie, scurte, cu ecou în canalul opus.
        for (let half = 0; half < 2; half += 1) {
            const step = beat * 2 + half;
            const hz = frequency(chord[ARPEGGIO[step]] + 12);
            const noteStart = beatStart + half * (BEAT / 2);
            const pan = step % 2 === 0 ? -0.5 : 0.5;
            const pluck = (gain) => (t) => gain * Math.exp(-t * 7) * Math.min(1, t / 0.004) * (Math.sin(2 * Math.PI * hz * t) + 0.18 * Math.sin(6 * Math.PI * hz * t));

            addSound(noteStart, 0.7, pan, pluck(0.075));
            addSound(noteStart + BEAT * 0.75, 0.7, -pan, pluck(0.03));
        }
    }
}

// Intrarea, ieșirea și aducerea vârfului la -1 dB, fără tăieri.
let peak = 0;

for (let index = 0; index < length; index += 1) {
    const t = index / SAMPLE_RATE;
    const fade = Math.min(1, t / FADE_IN) * Math.min(1, Math.max(0, (duration - t) / FADE_OUT));
    left[index] *= fade;
    right[index] *= fade;
    peak = Math.max(peak, Math.abs(left[index]), Math.abs(right[index]));
}

const gain = peak > 0 ? 10 ** (-1 / 20) / peak : 1;

// Fișierul WAV: antetul de 44 de octeți, apoi eșantioanele pe 16 biți, stânga și dreapta alternativ.
const data = Buffer.alloc(length * 4);

for (let index = 0; index < length; index += 1) {
    data.writeInt16LE(Math.round(left[index] * gain * 32767), index * 4);
    data.writeInt16LE(Math.round(right[index] * gain * 32767), index * 4 + 2);
}

const header = Buffer.alloc(44);
header.write('RIFF', 0);
header.writeUInt32LE(36 + data.length, 4);
header.write('WAVE', 8);
header.write('fmt ', 12);
header.writeUInt32LE(16, 16);
header.writeUInt16LE(1, 20);
header.writeUInt16LE(2, 22);
header.writeUInt32LE(SAMPLE_RATE, 24);
header.writeUInt32LE(SAMPLE_RATE * 4, 28);
header.writeUInt16LE(4, 32);
header.writeUInt16LE(16, 34);
header.write('data', 36);
header.writeUInt32LE(data.length, 40);

writeFileSync(outputPath, Buffer.concat([header, data]));
