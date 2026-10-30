"""Resource and file-lease controls for the media worker; no application dependencies."""
import os

_job_handle = None


def apply_limits(timeout):
    if os.name != 'nt':
        if os.getuid() == 0:
            raise RuntimeError('PRIVILEGED_WORKER')
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (512 * 1024 * 1024, 512 * 1024 * 1024))
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
    global _job_handle
    _job_handle = kernel.CreateJobObjectW(None, None)
    settings = Extended()
    settings.Basic.Flags = 0x2000 | 0x100 | 0x200 | 0x8
    settings.Basic.Active = 4
    settings.ProcessMemory = 512 * 1024 * 1024
    settings.JobMemory = 768 * 1024 * 1024
    if not (_job_handle and kernel.SetInformationJobObject(_job_handle, 9, ctypes.byref(settings), ctypes.sizeof(settings)) and kernel.AssignProcessToJobObject(_job_handle, kernel.GetCurrentProcess())):
        raise RuntimeError('WORKER_LIMITS_FAILED')


def acquire_lease(path):
    handle = open(path, 'a+b')
    if os.name == 'nt':
        import ctypes
        import msvcrt
        class Overlapped(ctypes.Structure):
            _fields_ = [('Internal', ctypes.c_size_t), ('InternalHigh', ctypes.c_size_t), ('Offset', ctypes.c_ulong), ('OffsetHigh', ctypes.c_ulong), ('hEvent', ctypes.c_void_p)]
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        kernel.LockFileEx.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.POINTER(Overlapped)]
        overlap = Overlapped()
        if not kernel.LockFileEx(msvcrt.get_osfhandle(handle.fileno()), 1, 0, 0xFFFFFFFF, 0xFFFFFFFF, ctypes.byref(overlap)):
            handle.close()
            raise RuntimeError('WORKER_LEASE_FAILED')
    else:
        import fcntl
        fcntl.flock(handle, fcntl.LOCK_SH | fcntl.LOCK_NB)
    return handle
