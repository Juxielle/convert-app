from pathlib import Path
import subprocess
import sys
from PIL import Image, ImageDraw, ImageFont

directory = Path(sys.argv[1]); directory.mkdir(parents=True, exist_ok=True)
font_path = Path('C:/Windows/Fonts/arial.ttf') if sys.platform == 'win32' else Path('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')
font = ImageFont.truetype(str(font_path), 44)
image = Image.new('RGB', (1500, 350), 'white')
ImageDraw.Draw(image).multiline_text((50, 60), 'Bonjour le monde.\nUn résumé français : été, café, numéro 2026.\nPrivate text extraction test.', font=font, fill='black', spacing=15)
for extension in ('png', 'jpg', 'webp'):
    image.save(directory / ('text.' + extension))
Image.new('RGB', (300, 200), 'white').save(directory / 'blank.png')

ffmpeg, fixtures = sys.argv[2:]
audio = Path(fixtures) / 'english.wav'
for extension in ('mp3', 'flac', 'ogg', 'opus', 'm4a', 'aac'):
    subprocess.run([ffmpeg, '-v', 'error', '-nostdin', '-y', '-i', str(audio), '-threads', '1', str(directory / ('speech.' + extension))], check=True, timeout=30)
for extension in ('mp4', 'mov', 'mkv', 'avi', 'webm'):
    codec = 'libvpx-vp9' if extension == 'webm' else 'mpeg4' if extension == 'avi' else 'libx264'
    acodec = 'libopus' if extension == 'webm' else 'libmp3lame' if extension == 'avi' else 'aac'
    subprocess.run([ffmpeg, '-v', 'error', '-nostdin', '-y', '-f', 'lavfi', '-i', 'color=size=320x180:rate=15', '-i', str(audio), '-shortest', '-c:v', codec, '-threads', '1', '-c:a', acodec, str(directory / ('speech.' + extension))], check=True, timeout=30)
subprocess.run([ffmpeg, '-v', 'error', '-nostdin', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=16000:cl=mono', '-t', '2', str(directory / 'silence.wav')], check=True, timeout=10)
