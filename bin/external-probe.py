"""Validate a downloaded WebM within the existing isolated native media bounds."""
import importlib.util
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from media_limits import apply_limits, acquire_lease

try:
    task = json.loads(sys.stdin.buffer.read(32768))
    apply_limits(int(task['timeout']))
    lease = acquire_lease(task['lease'])
    spec = importlib.util.spec_from_file_location('media_worker', Path(__file__).with_name('media-worker.py'))
    worker = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(worker)
    data = worker.probe(task, task['input'])
    if not data['videos'] or data['duration'] > task['limits']['video_duration']:
        raise ValueError('incompatible')
    print(json.dumps({'status': 'OK'}), flush=True)
except Exception:
    print(json.dumps({'error': 'SOURCE_INCOMPATIBLE'}), flush=True)
    sys.exit(1)
