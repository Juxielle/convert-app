"""FFmpeg local-file operations. The PHP engine constructs the entire stdin manifest."""
import json
import math
import os
from pathlib import Path
import signal
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from media_limits import apply_limits, acquire_lease

FORMATS = 'mov,matroska,webm,avi,mp3,wav,aac,flac,ogg'
AUDIO = {'mp3': ['-c:a', 'libmp3lame'], 'wav': ['-c:a', 'pcm_s16le'], 'aac': ['-c:a', 'aac'], 'm4a': ['-c:a', 'aac'], 'flac': ['-c:a', 'flac', '-sample_fmt', 's16'], 'ogg': ['-c:a', 'libvorbis'], 'opus': ['-c:a', 'libopus']}
VIDEO = {'mp4': 'libx264', 'mov': 'libx264', 'mkv': 'libx264', 'avi': 'mpeg4', 'webm': 'libvpx-vp9'}
VIDEO_DECODERS = {'h264', 'mpeg4', 'vp8', 'vp9'}
AUDIO_DECODERS = {'mp3', 'pcm_s16le', 'aac', 'flac', 'vorbis', 'opus'}
_child = None


class Invalid(Exception):
    pass


def ensure(condition, code='INVALID_MEDIA'):
    if not condition:
        raise Invalid(code)


def emit(data):
    print(json.dumps(data, separators=(',', ':')), flush=True)


def stop_children(*_):
    if _child and _child.poll() is None:
        if os.name == 'nt':
            _child.kill()
        else:
            os.killpg(_child.pid, signal.SIGKILL)
        _child.wait(timeout=5)
    raise Invalid('PROCESS_TIMEOUT')


def local_input(path):
    ensure(Path(path).is_file() and not Path(path).is_symlink())
    options = ['-protocol_whitelist', 'file', '-format_whitelist', FORMATS, '-probesize', '5000000', '-analyzeduration', '5000000']
    if Path(path).suffix.lower() in ('.mp4', '.mov', '.m4a'):
        options += ['-enable_drefs', '0', '-use_absolute_path', '0']
    return options


def probe(task, path):
    global _child
    command = [task['ffprobe'], '-v', 'error', '-max_alloc', '67108864', '-threads', '1', *local_input(path), '-show_entries', 'format=duration,format_name:stream=index,codec_type,codec_name,codec_tag_string,width,height,avg_frame_rate,sample_rate,channels:stream_disposition=attached_pic', '-of', 'json', path]
    _child = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, start_new_session=os.name != 'nt')
    try:
        data, _ = _child.communicate(timeout=8)
        ensure(_child.returncode == 0 and len(data) <= 65536)
        info = json.loads(data)
    finally:
        if _child.poll() is None:
            _child.kill(); _child.wait(timeout=5)
    streams = info.get('streams', [])
    ensure(0 < len(streams) <= task['limits']['max_streams'], 'MEDIA_TOO_COMPLEX')
    duration = float(info.get('format', {}).get('duration', 0))
    ensure(math.isfinite(duration) and duration > 0)
    videos, audios = [], []
    for stream in streams:
        ensure(stream.get('codec_tag_string') not in ('encv', 'enca'), 'PROTECTED_MEDIA')
        kind = stream.get('codec_type')
        ensure(kind in ('audio', 'video'), 'MEDIA_TOO_COMPLEX')
        if kind == 'audio':
            ensure(stream.get('codec_name') in AUDIO_DECODERS, 'UNSUPPORTED_MEDIA_CODEC')
            ensure(1 <= int(stream.get('channels', 0)) <= 2 and 1 <= int(stream.get('sample_rate', 0)) <= 48000, 'MEDIA_TOO_COMPLEX')
            audios.append(stream)
        else:
            width, height = int(stream.get('width', 0)), int(stream.get('height', 0))
            ensure(0 < width <= task['limits']['max_width'] and 0 < height <= task['limits']['max_height'] and width * height <= task['limits']['max_pixels'], 'MEDIA_TOO_COMPLEX')
            if stream.get('disposition', {}).get('attached_pic'):
                continue
            ensure(stream.get('codec_name') in VIDEO_DECODERS, 'UNSUPPORTED_MEDIA_CODEC')
            rate = stream.get('avg_frame_rate', '0/1').split('/')
            fps = float(rate[0]) / max(1, float(rate[1]))
            ensure(math.isfinite(fps) and 0 < fps <= task['limits']['max_fps'], 'MEDIA_TOO_COMPLEX')
            videos.append(stream)
    ensure(len(videos) <= 1 and len(audios) <= 1, 'MEDIA_TOO_COMPLEX')
    return {'duration': duration, 'videos': videos, 'audios': audios}


def convert(task):
    global _child
    options = task.get('options') or {}
    source = probe(task, task['input'])
    audio = task['tool'] in ('convertir-audio', 'extraire-audio')
    ensure(bool(source['audios']) if audio else bool(source['videos']), 'MISSING_MEDIA_STREAM')
    if task['tool'] == 'convertir-audio':
        ensure(not source['videos'], 'INVALID_MEDIA')
    if task['tool'] == 'extraire-audio':
        ensure(bool(source['videos']), 'MISSING_MEDIA_STREAM')
    limit = task['limits']['audio_duration'] if not source['videos'] else task['limits']['video_duration']
    ensure(source['duration'] <= limit, 'MEDIA_TOO_COMPLEX')
    start = float(options.get('start', 0))
    requested = float(options.get('duration', source['duration'] - start))
    ensure(math.isfinite(start) and math.isfinite(requested) and 0 <= start < source['duration'] and 0.1 <= requested <= limit, 'INVALID_OPTIONS')
    duration = min(requested, source['duration'] - start)
    command = [task['ffmpeg'], '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-max_alloc', '67108864', '-threads', '1', '-filter_threads', '1', '-filter_complex_threads', '1', *local_input(task['input'])]
    if task.get('realtime'):
        command += ['-re']
    command += ['-i', task['input'], '-ss', str(start), '-t', str(duration), '-map_metadata', '-1', '-map_chapters', '-1', '-sn', '-dn']
    if audio:
        ensure(task['target'] in AUDIO)
        command += ['-map', '0:a:0', '-vn', *AUDIO[task['target']], '-ar', '48000' if task['target'] == 'opus' else str(options.get('sample_rate', 44100)), '-ac', str(options.get('channels', 2))]
        if task['target'] not in ('wav', 'flac'):
            command += ['-b:a', str(options.get('bitrate', 128)) + 'k']
    else:
        ensure(task['target'] in VIDEO)
        profile = options.get('profile', 'balanced')
        ensure(profile in ('light', 'balanced', 'quality'), 'INVALID_OPTIONS')
        default = {'light': 480, 'balanced': 720, 'quality': 720}[profile]
        height = int(options.get('resolution', default))
        ensure(height in (360, 480, 720), 'INVALID_OPTIONS')
        width = {360: 640, 480: 854, 720: 1280}[height]
        fps = int(options.get('fps', 30))
        ensure(fps in (15, 24, 30), 'INVALID_OPTIONS')
        codec = VIDEO[task['target']]
        command += ['-map', '0:v:0', '-map', '0:a:0?', '-vf', f"scale=w='min(iw,{width})':h='min(ih,{height})':force_original_aspect_ratio=decrease:force_divisible_by=2,fps={fps}", '-c:v', codec, '-pix_fmt', 'yuv420p', '-threads', '1']
        if codec == 'libx264':
            command += ['-preset', 'veryfast', '-crf', str({'light': 32, 'balanced': 28, 'quality': 23}[profile]), '-maxrate', str({'light': 900, 'balanced': 1800, 'quality': 3000}[profile]) + 'k', '-bufsize', '6000k']
        elif codec == 'libvpx-vp9':
            command += ['-deadline', 'realtime', '-cpu-used', '6', '-row-mt', '0', '-b:v', str({'light': 700, 'balanced': 1400, 'quality': 2200}[profile]) + 'k']
        else:
            command += ['-q:v', str({'light': 12, 'balanced': 8, 'quality': 4}[profile])]
        audio_codec = 'libopus' if task['target'] == 'webm' else 'libmp3lame' if task['target'] == 'avi' else 'aac'
        command += ['-c:a', audio_codec, '-b:a', '128k', '-ac', '2', '-ar', '48000' if audio_codec == 'libopus' else '44100']
        if task['target'] in ('mp4', 'mov'):
            command += ['-movflags', '+faststart']
    command += ['-fs', str(task['max_bytes']), '-progress', 'pipe:1', '-stats_period', '0.25', '-nostats', task['output']]
    _child = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, start_new_session=os.name != 'nt')
    try:
        for line in _child.stdout:
            if line.startswith('out_time_us='):
                try:
                    timestamp = float(line.split('=', 1)[1]) / 1_000_000
                    if math.isfinite(timestamp):
                        emit({'progress': max(0, min(99, int(timestamp / duration * 100)))})
                except ValueError:
                    pass
        ensure(_child.wait(timeout=5) == 0)
    finally:
        if _child.poll() is None:
            _child.kill(); _child.wait(timeout=5)
    ensure(Path(task['output']).is_file() and 0 < os.path.getsize(task['output']) < task['max_bytes'], 'OUTPUT_LIMIT_EXCEEDED')
    result = probe(task, task['output'])
    ensure(abs(result['duration'] - duration) <= 0.3, 'OUTPUT_LIMIT_EXCEEDED')
    expected_audio = {'mp3': 'mp3', 'wav': 'pcm_s16le', 'aac': 'aac', 'm4a': 'aac', 'flac': 'flac', 'ogg': 'vorbis', 'opus': 'opus'}
    if audio:
        ensure(not result['videos'] and len(result['audios']) == 1 and result['audios'][0]['codec_name'] == expected_audio[task['target']], 'INVALID_OUTPUT')
    else:
        expected = {'mp4': 'h264', 'mov': 'h264', 'mkv': 'h264', 'avi': 'mpeg4', 'webm': 'vp9'}
        ensure(len(result['videos']) == 1 and result['videos'][0]['codec_name'] == expected[task['target']], 'INVALID_OUTPUT')
        ensure(result['videos'][0]['width'] <= 1280 and result['videos'][0]['height'] <= 720, 'INVALID_OUTPUT')


def main():
    task = json.loads(sys.stdin.buffer.read(32768))
    apply_limits(int(task['timeout']))
    if os.name != 'nt':
        signal.signal(signal.SIGTERM, stop_children)
    lease = acquire_lease(task['lease'])
    try:
        convert(task)
    finally:
        if _child and _child.poll() is None:
            _child.kill(); _child.wait(timeout=5)
        lease.close()


if __name__ == '__main__':
    try:
        main()
        emit({'status': 'OK'})
    except (Invalid, RuntimeError) as error:
        emit({'error': str(error) if str(error) in {'INVALID_MEDIA', 'MEDIA_TOO_COMPLEX', 'UNSUPPORTED_MEDIA_CODEC', 'PROTECTED_MEDIA', 'MISSING_MEDIA_STREAM', 'INVALID_OPTIONS', 'OUTPUT_LIMIT_EXCEEDED', 'INVALID_OUTPUT', 'PROCESS_TIMEOUT', 'WORKER_LIMITS_FAILED', 'WORKER_LEASE_FAILED', 'PRIVILEGED_WORKER'} else 'INVALID_MEDIA'})
        sys.exit(1)
    except Exception:
        emit({'error': 'INVALID_MEDIA'})
        sys.exit(1)
