"""Private, offline OCR and speech extraction. No browser arguments or external URLs."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import signal

sys.path.insert(0, str(Path(__file__).resolve().parent))
from recognition_limits import apply_limits, acquire_lease


class Invalid(Exception):
    pass


_child = None
LANGUAGES = json.loads(Path(__file__).resolve().parent.parent.joinpath('config/recognition-languages.json').read_text(encoding='utf-8'))
OCR_LANGUAGES = {item['code']: item['ocr'] for item in LANGUAGES} | {'fr-en': 'fra+eng'}
SPEECH_LANGUAGES = {item['code']: item['speech'] for item in LANGUAGES if item['speech']} | {'auto': 'auto'}


def terminate(*_):
    if _child and _child.poll() is None:
        _child.kill(); _child.wait(timeout=5)
    raise Invalid('PROCESS_TIMEOUT')


def run(command, timeout, env=None):
    global _child
    process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
    _child = process
    try:
        if process.wait(timeout=timeout) != 0:
            raise Invalid('RECOGNITION_FAILED')
    finally:
        if process.poll() is None:
            process.kill(); process.wait(timeout=5)


def main(task):
    apply_limits(int(task['timeout']), task['tool'] != 'ocr-image')
    if os.name != 'nt':
        signal.signal(signal.SIGTERM, terminate)
    lease = acquire_lease(task['lease'])
    source, output = Path(task['input']), Path(task['output'])
    options = task.get('options') or {}
    work = source.parent
    env = dict(os.environ, OMP_THREAD_LIMIT='1', OMP_NUM_THREADS='1', OPENBLAS_NUM_THREADS='1')
    try:
        if task['tool'] == 'ocr-image':
            from PIL import Image, ImageOps, UnidentifiedImageError
            Image.MAX_IMAGE_PIXELS = task['limits']['max_pixels']
            try:
                with Image.open(source) as image:
                    if image.format not in ('JPEG', 'PNG', 'WEBP') or getattr(image, 'n_frames', 1) != 1:
                        raise Invalid('INVALID_IMAGE')
                    width, height = image.size
                    if max(width, height) > task['limits']['max_dimension'] or width * height > task['limits']['max_pixels']:
                        raise Invalid('IMAGE_TOO_COMPLEX')
                    image.load()
                    rgba = ImageOps.exif_transpose(image).convert('RGBA')
                    clean = Image.new('RGB', rgba.size, 'white')
                    clean.paste(rgba, mask=rgba.getchannel('A'))
                    clean = ImageOps.grayscale(clean)
                    normalized = work / 'ocr-normalized.png'
                    clean.save(normalized)
            except (Image.DecompressionBombError, Image.DecompressionBombWarning):
                raise Invalid('IMAGE_TOO_COMPLEX')
            except (UnidentifiedImageError, OSError):
                raise Invalid('INVALID_IMAGE')
            language = OCR_LANGUAGES.get(options.get('language', 'fr-en'))
            if not language:
                raise Invalid('INVALID_OPTIONS')
            layout = options.get('layout', 'auto')
            if layout not in ('auto', 'block', 'sparse'):
                raise Invalid('INVALID_OPTIONS')
            run([task['tesseract'], str(normalized), str(work / 'recognized'), '--tessdata-dir', task['tessdata'], '-l', language, '--psm', {'auto': '3', 'block': '6', 'sparse': '11'}[layout], '-c', 'user_defined_dpi=300', 'txt'], task['timeout'], env)
            text_file = work / 'recognized.txt'
        else:
            spec = importlib.util.spec_from_file_location('media_worker', Path(__file__).with_name('media-worker.py'))
            media = importlib.util.module_from_spec(spec); spec.loader.exec_module(media)
            info = media.probe(task, str(source))
            if info['duration'] > task['limits']['max_duration']:
                raise Invalid('MEDIA_TOO_COMPLEX')
            if not info['audios']:
                raise Invalid('MISSING_MEDIA_STREAM')
            language = SPEECH_LANGUAGES.get(options.get('language', 'auto'))
            if not language:
                raise Invalid('INVALID_OPTIONS')
            normalized = work / 'speech.wav'
            run([task['ffmpeg'], '-hide_banner', '-v', 'error', '-nostdin', '-y', '-max_alloc', '67108864', '-threads', '1', *media.local_input(str(source)), '-i', str(source), '-t', str(task['limits']['max_duration'] + 0.25), '-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-threads', '1', '-fs', '2000000', str(normalized)], min(15, task['timeout']), env)
            # Refuse digital silence rather than generating a hallucinated transcript.
            import wave
            import array
            with wave.open(str(normalized), 'rb') as wav:
                duration = wav.getnframes() / wav.getframerate()
                if duration > task['limits']['max_duration'] + 0.1:
                    raise Invalid('INVALID_MEDIA')
                samples = array.array('h', wav.readframes(wav.getnframes()))
            if not samples or max(abs(sample) for sample in samples) < 100:
                raise Invalid('NO_SPEECH')
            run([task['whisper'], '-m', task['model'], '-f', str(normalized), '-l', language, '-t', '1', '-p', '1', '-bo', '1', '-bs', '1', '-nf', '-ng', '-np', '-otxt', '-of', str(work / 'recognized')], task['timeout'], env)
            text_file = work / 'recognized.txt'
        if not text_file.is_file() or text_file.stat().st_size > task['limits']['max_text_bytes']:
            raise Invalid('OUTPUT_LIMIT_EXCEEDED')
        text = text_file.read_text(encoding='utf-8-sig').strip()
        if not text:
            raise Invalid('NO_TEXT_FOUND' if task['tool'] == 'ocr-image' else 'NO_SPEECH')
        output.write_text(text + '\n', encoding='utf-8')
        text_file.unlink(missing_ok=True)
        normalized.unlink(missing_ok=True)
    finally:
        lease.close()


if __name__ == '__main__':
    try:
        main(json.loads(sys.stdin.buffer.read(32768)))
        print(json.dumps({'status': 'OK'}), flush=True)
    except Exception as error:
        code = 'PROCESS_TIMEOUT' if isinstance(error, subprocess.TimeoutExpired) else str(error)
        allowed = {'PROCESS_TIMEOUT', 'INVALID_IMAGE', 'IMAGE_TOO_COMPLEX', 'INVALID_OPTIONS', 'MEDIA_TOO_COMPLEX', 'MISSING_MEDIA_STREAM', 'NO_SPEECH', 'NO_TEXT_FOUND', 'OUTPUT_LIMIT_EXCEEDED', 'UNSUPPORTED_MEDIA_CODEC', 'PROTECTED_MEDIA', 'INVALID_MEDIA', 'WORKER_LIMITS_FAILED', 'WORKER_LEASE_FAILED', 'PRIVILEGED_WORKER'}
        print(json.dumps({'error': code if code in allowed else 'RECOGNITION_FAILED'}), flush=True)
        sys.exit(1)
