<?php

return [
    'enabled' => (bool) env('RECOGNITION_ENABLED', true),
    'python' => env('RECOGNITION_PYTHON', base_path(PHP_OS_FAMILY === 'Windows' ? '.tools/python/Scripts/python.exe' : '.tools/python/bin/python')),
    'tesseract' => env('RECOGNITION_TESSERACT', base_path('.tools/tesseract/tesseract.exe')),
    'tessdata' => env('RECOGNITION_TESSDATA', base_path('.tools/tesseract/tessdata')),
    'whisper' => env('RECOGNITION_WHISPER', base_path('.tools/whisper/Release/whisper-cli.exe')),
    'model' => env('RECOGNITION_MODEL', base_path('.tools/whisper/ggml-tiny.bin')),
    'max_image_bytes' => 10_000_000,
    'max_media_bytes' => 25_000_000,
    'max_pixels' => 6_000_000,
    'max_dimension' => 8192,
    'max_duration' => 60,
    'max_text_bytes' => 1_000_000,
    // Includes normalized image/WAV while the worker runs.
    'max_work_bytes' => 40_000_000,
    'max_running' => 1,
];
