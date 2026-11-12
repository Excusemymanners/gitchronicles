"""
Vocea video-ului cu un motor neural: citește de pe intrarea standard o listă JSON de fraze
([{"text": "...", "path": "/.../voice-0-0.wav"}, ...]) și scrie fiecare frază într-un fișier WAV.

Un singur proces pentru toate frazele: modelul se încarcă o dată, nu o dată pe frază.
Laravel îl pornește din App\\Media\\Speech (KokoroVoice, EdgeVoice); Piper are programul lui.

    speak.py kokoro --model kokoro-v1.0.onnx --voices voices-v1.0.bin --voice am_michael --speed 1.0 --lang en-us
    speak.py chatterbox --exaggeration 0.7
    speak.py edge --voice ro-RO-EmilNeural --rate +0% --ffmpeg /usr/bin/ffmpeg

Fiecare frază poate avea „rate” și „pitch” (părți din 1, ex. -0.05 = 5% mai lent; vezi SpeechDelivery.php):
Edge le primește ca viteză și înălțime, Kokoro doar ca viteză.

- kokoro: Kokoro-82M (Apache-2.0) prin kokoro-onnx, local, fără internet;
- chatterbox: Chatterbox (MIT, engleză, GPU), singurul motor gratuit cu un control al emoției („exaggeration”,
  0,25–1,0). OPȚIONAL: rulează cu Python-ul unui mediu separat (config media.voice.chatterbox.python),
  nu cu cel din renderers/tts/.venv; marchează sunetul cu filigranul lui, nu îl scoatem;
- edge: vocile neurale gratuite ale Microsoft Edge prin pachetul edge-tts. ATENȚIE: e un serviciu online
  neoficial (nu are contract sau garanție); trimite textul frazelor la Microsoft. E un driver opțional.

La orice eroare iese cu cod 1 și scrie motivul pe stderr.
"""

import argparse
import json
import subprocess
import sys
import wave


def write_wav(path, samples, sample_rate):
    """Scrie eșantioanele (float între -1 și 1) ca WAV mono pe 16 biți."""
    import numpy

    pcm = (numpy.clip(samples, -1.0, 1.0) * 32767).astype('<i2')

    with wave.open(path, 'wb') as output:
        output.setnchannels(1)
        output.setsampwidth(2)
        output.setframerate(sample_rate)
        output.writeframes(pcm.tobytes())


def speak_kokoro(clips, options):
    from kokoro_onnx import Kokoro

    engine = Kokoro(options.model, options.voices)

    for clip in clips:
        samples, sample_rate = engine.create(clip['text'], voice=options.voice, speed=options.speed * (1 + clip.get('rate', 0.0)), lang=options.lang)
        write_wav(clip['path'], samples, sample_rate)


def speak_chatterbox(clips, options):
    import types

    try:
        import pkg_resources  # noqa: F401
    except ImportError:
        # Pachetul „perth” (filigranul Chatterbox) are nevoie doar de resource_filename; setuptools nou nu mai are pkg_resources.
        import importlib.util
        import os

        shim = types.ModuleType('pkg_resources')
        shim.resource_filename = lambda package, name: os.path.join(os.path.dirname(importlib.util.find_spec(package).origin), name)
        sys.modules['pkg_resources'] = shim

    from chatterbox.tts import ChatterboxTTS

    engine = ChatterboxTTS.from_pretrained(device='cuda')

    for clip in clips:
        audio = engine.generate(clip['text'], exaggeration=options.exaggeration, cfg_weight=options.cfg_weight)
        write_wav(clip['path'], audio.squeeze().cpu().numpy(), engine.sr)


def edge_rate(base, change):
    """Viteza de bază („+0%”) plus schimbarea frazei (-0.05), scrisă cum o cere edge-tts."""
    percent = round(float(base.rstrip('%')) + change * 100)
    return f'{percent:+d}%'


def edge_pitch(change):
    """Schimbarea înălțimii (0.06 = 6%) în Hz; o voce bărbătească are tonul de bază la circa 120 Hz."""
    return f'{round(change * 120):+d}Hz'


def speak_edge(clips, options):
    import asyncio

    import edge_tts

    async def one(clip):
        mp3 = clip['path'] + '.mp3'
        rate = edge_rate(options.rate, clip.get('rate', 0.0))
        pitch = edge_pitch(clip.get('pitch', 0.0))

        # Serviciul neoficial răspunde uneori „No audio was received”; aceeași cerere reușește la a doua-a treia încercare.
        for attempt in range(4):
            try:
                await edge_tts.Communicate(clip['text'], options.voice, rate=rate, pitch=pitch).save(mp3)
                break
            except edge_tts.exceptions.NoAudioReceived:
                if attempt == 3:
                    raise
                await asyncio.sleep(1.5 * (attempt + 1))

        # Vocea vine ca MP3; restul lanțului primește WAV, ca de la celelalte motoare.
        subprocess.run([options.ffmpeg, '-v', 'error', '-y', '-i', mp3, '-ac', '1', clip['path']], check=True)

    async def all_clips():
        for clip in clips:
            await one(clip)

    asyncio.run(all_clips())


def main():
    parser = argparse.ArgumentParser()
    engines = parser.add_subparsers(dest='engine', required=True)

    kokoro = engines.add_parser('kokoro')
    kokoro.add_argument('--model', required=True)
    kokoro.add_argument('--voices', required=True)
    kokoro.add_argument('--voice', required=True)
    kokoro.add_argument('--speed', type=float, default=1.0)
    kokoro.add_argument('--lang', default='en-us')

    chatterbox = engines.add_parser('chatterbox')
    chatterbox.add_argument('--exaggeration', type=float, default=0.5)
    chatterbox.add_argument('--cfg-weight', type=float, default=0.5)

    edge = engines.add_parser('edge')
    edge.add_argument('--voice', required=True)
    edge.add_argument('--rate', default='+0%')
    edge.add_argument('--pitch', default='+0Hz')
    edge.add_argument('--ffmpeg', default='ffmpeg')

    options = parser.parse_args()
    clips = json.load(sys.stdin)

    if not isinstance(clips, list) or not all(isinstance(clip, dict) and isinstance(clip.get('text'), str) and isinstance(clip.get('path'), str) for clip in clips):
        sys.exit('The input must be a JSON list of {"text", "path"}.')

    try:
        {'kokoro': speak_kokoro, 'edge': speak_edge, 'chatterbox': speak_chatterbox}[options.engine](clips, options)
    except Exception as error:  # noqa: BLE001 — orice eroare a motorului ajunge la Laravel ca motiv
        sys.exit(f'{options.engine} failed: {error}')


if __name__ == '__main__':
    main()
