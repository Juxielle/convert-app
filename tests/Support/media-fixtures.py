import json
from pathlib import Path
import subprocess
import sys

ffmpeg, directory = sys.argv[1], Path(sys.argv[2])
directory.mkdir(parents=True, exist_ok=True)


def run(arguments):
    subprocess.run([ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-threads', '1', *arguments], check=True, timeout=30)


run(['-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100:duration=2', '-c:a', 'pcm_s16le', '-ac', '2', str(directory / 'sample.wav')])
encoders = {'mp3': ['-c:a', 'libmp3lame'], 'aac': ['-c:a', 'aac'], 'm4a': ['-c:a', 'aac'], 'flac': ['-c:a', 'flac'], 'ogg': ['-c:a', 'libvorbis'], 'opus': ['-c:a', 'libopus', '-ar', '48000']}
for extension, codec in encoders.items():
    run(['-i', str(directory / 'sample.wav'), *codec, str(directory / ('sample.' + extension))])
run(['-f', 'lavfi', '-i', 'testsrc2=size=320x180:rate=24:duration=2', '-i', str(directory / 'sample.wav'), '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '10', '-pix_fmt', 'yuv420p', '-threads', '1', '-c:a', 'aac', '-shortest', str(directory / 'sample.mp4')])
for extension in ['mov', 'mkv', 'avi', 'webm']:
    codec = ['-c:v', 'libvpx-vp9', '-deadline', 'realtime', '-cpu-used', '6', '-c:a', 'libopus'] if extension == 'webm' else ['-c:v', 'mpeg4', '-c:a', 'libmp3lame'] if extension == 'avi' else ['-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac']
    run(['-i', str(directory / 'sample.mp4'), *codec, '-threads', '1', str(directory / ('sample.' + extension))])
run(['-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=24:duration=2', '-an', '-c:v', 'libx264', '-threads', '1', str(directory / 'silent.mp4')])
run(['-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=96000:duration=1', '-c:a', 'pcm_s16le', str(directory / 'high-rate.wav')])
print('media-fixtures-ready')
