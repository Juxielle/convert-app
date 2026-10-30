"""Bounded HTTP test server; native termination avoids taskkill/WMI under Windows sandboxing."""
from pathlib import Path
import subprocess
import sys
import time

directory, php, address, router = sys.argv[1:]
child = subprocess.Popen([php, '-S', address, router], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    deadline = time.monotonic() + 45
    while child.poll() is None and time.monotonic() < deadline and not Path(directory, 'server.stop').exists():
        time.sleep(0.05)
finally:
    if child.poll() is None:
        child.kill()
    child.wait(timeout=5)
