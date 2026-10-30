"""Dedicated recognition budget; independent of the smaller media conversion sandbox."""
import os
from media_limits import acquire_lease

_handle = None


def apply_limits(timeout, speech):
    if not speech:
        from media_limits import apply_limits as bounded
        bounded(timeout)
        return
    if os.name != 'nt':
        if os.getuid() == 0:
            raise RuntimeError('PRIVILEGED_WORKER')
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (1536 * 1024 * 1024, 1536 * 1024 * 1024))
        resource.setrlimit(resource.RLIMIT_CPU, (timeout, timeout + 1))
        return
    import ctypes
    from ctypes import wintypes
    class Basic(ctypes.Structure):
        _fields_ = [('ProcessTime', ctypes.c_longlong), ('JobTime', ctypes.c_longlong), ('Flags', wintypes.DWORD), ('MinWorking', ctypes.c_size_t), ('MaxWorking', ctypes.c_size_t), ('Active', wintypes.DWORD), ('Affinity', ctypes.c_size_t), ('Priority', wintypes.DWORD), ('Scheduling', wintypes.DWORD)]
    class Io(ctypes.Structure):
        _fields_ = [(name, ctypes.c_ulonglong) for name in ('ReadOps', 'WriteOps', 'OtherOps', 'ReadBytes', 'WriteBytes', 'OtherBytes')]
    class Extended(ctypes.Structure):
        _fields_ = [('Basic', Basic), ('Io', Io), ('ProcessMemory', ctypes.c_size_t), ('JobMemory', ctypes.c_size_t), ('PeakProcess', ctypes.c_size_t), ('PeakJob', ctypes.c_size_t)]
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    kernel.CreateJobObjectW.restype = wintypes.HANDLE
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    kernel.SetInformationJobObject.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD]
    kernel.AssignProcessToJobObject.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    global _handle
    _handle = kernel.CreateJobObjectW(None, None)
    settings = Extended()
    settings.Basic.Flags = 0x2000 | 0x100 | 0x200 | 0x8
    settings.Basic.Active = 4
    settings.ProcessMemory = 1024 * 1024 * 1024
    settings.JobMemory = 1280 * 1024 * 1024
    if not (_handle and kernel.SetInformationJobObject(_handle, 9, ctypes.byref(settings), ctypes.sizeof(settings)) and kernel.AssignProcessToJobObject(_handle, kernel.GetCurrentProcess())):
        raise RuntimeError('WORKER_LIMITS_FAILED')
