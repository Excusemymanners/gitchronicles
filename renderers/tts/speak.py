"""
Vocea video-ului cu un motor neural: citește de pe intrarea standard o listă JSON de fraze
([{"text": "...", "path": "/.../voice-0-0.wav"}, ...]) și scrie fiecare frază într-un fișier WAV.

Un singur proces pentru toate frazele: modelul se încarcă o dată, nu o dată pe frază.
Laravel îl pornește din App\\Media\\Speech (KokoroVoice, EdgeVoice); Piper are programul lui.

    speak.py kokoro --model kokoro-v1.0.onnx --voices voices-v1.0.bin --voice am_michael --speed 1.0 --lang en-us
    speak.py edge --voice ro-RO-EmilNeural --rate +0% --ffmpeg /usr/bin/ffmpeg

- kokoro: Kokoro-82M (Apache-2.0) prin kokoro-onnx, local, fără internet;
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
        samples, sample_rate = engine.create(clip['text'], voice=options.voice, speed=options.speed, lang=options.lang)
        write_wav(clip['path'], samples, sample_rate)


def speak_edge(clips, options):
    import asyncio

    import edge_tts

    async def one(clip):
        mp3 = clip['path'] + '.mp3'
        await edge_tts.Communicate(clip['text'], options.voice, rate=options.rate, pitch=options.pitch).save(mp3)
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
        {'kokoro': speak_kokoro, 'edge': speak_edge}[options.engine](clips, options)
    except Exception as error:  # noqa: BLE001 — orice eroare a motorului ajunge la Laravel ca motiv
        sys.exit(f'{options.engine} failed: {error}')


if __name__ == '__main__':
    main()
