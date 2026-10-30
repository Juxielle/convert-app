"""Local U²-Net foreground matting. No downloads or network calls at runtime."""
import importlib.util
import json
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location('background_limits', Path(__file__).with_name('media_limits.py'))
limits = importlib.util.module_from_spec(spec)
spec.loader.exec_module(limits)


def main():
    task = json.loads(sys.stdin.buffer.read(16384))
    limits.apply_limits(task['timeout'])
    lease = limits.acquire_lease(task['lease'])
    try:
        import numpy as np
        import onnxruntime as ort
        from PIL import Image, ImageChops

        Image.MAX_IMAGE_PIXELS = task['max_pixels']
        with Image.open(task['input']) as image:
            w, h = image.size
            if w * h > task['max_pixels'] or max(w, h) > task['max_dimension'] or getattr(image, 'n_frames', 1) != 1:
                raise ValueError('IMAGE_TOO_COMPLEX')
            source = image.convert('RGBA')
        # The 320px inference preserves the original output size and existing alpha.
        rgb = source.convert('RGB').resize((320, 320), Image.Resampling.LANCZOS)
        data = np.asarray(rgb, dtype=np.float32)
        data /= max(float(data.max()), 1e-6)
        data = (data - np.array([.485, .456, .406], dtype=np.float32)) / np.array([.229, .224, .225], dtype=np.float32)
        data = data.transpose(2, 0, 1)[None].astype(np.float32)
        options = ort.SessionOptions()
        options.intra_op_num_threads = 1
        options.inter_op_num_threads = 1
        options.enable_cpu_mem_arena = False
        session = ort.InferenceSession(task['model'], sess_options=options, providers=['CPUExecutionProvider'])
        prediction = session.run([session.get_outputs()[0].name], {session.get_inputs()[0].name: data})[0][0, 0]
        low, high = float(prediction.min()), float(prediction.max())
        # A constant prediction must never trigger a divide by zero.
        prediction = (prediction - low) / (high - low) if high - low > 1e-6 else np.clip(prediction, 0, 1)
        mask = Image.fromarray((np.clip(prediction, 0, 1) * 255).astype('uint8')).resize(source.size, Image.Resampling.LANCZOS)
        source.putalpha(ImageChops.multiply(source.getchannel('A'), mask))

        class LimitedOutput:
            def __init__(self, path):
                self.file = open(path, 'wb')
                self.written = 0

            def write(self, chunk):
                self.written += len(chunk)
                if self.written > task['max_bytes']:
                    raise ValueError('OUTPUT_LIMIT_EXCEEDED')
                return self.file.write(chunk)

            def flush(self):
                self.file.flush()

        output = LimitedOutput(task['output'])
        try:
            # Rebuild to discard uploaded EXIF, ICC and text metadata.
            clean = Image.new('RGBA', source.size)
            clean.paste(source)
            clean.save(output, format='PNG', compress_level=6)
        finally:
            output.file.close()
    finally:
        lease.close()


if __name__ == '__main__':
    try:
        main()
        print('OK', end='')
    except Exception:
        print('ENGINE_FAILED', end='')
        sys.exit(1)
