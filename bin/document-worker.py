"""Local-file conversion worker. JSON stdin is constructed by DocumentEngine only."""
import csv
import io
import json
import logging
import math
import os
from pathlib import Path
import signal
import subprocess
import sys
import zipfile

import openpyxl
import pypdf
from pypdf import PdfReader, PdfWriter
import pypdfium2 as pdfium
from defusedxml import ElementTree as SafeET

logging.disable(logging.CRITICAL)
csv.field_size_limit(32767)
pypdf.filters.ZLIB_MAX_OUTPUT_LENGTH = 16_000_000
pypdf.filters.LZW_MAX_OUTPUT_LENGTH = 16_000_000
pypdf.filters.JBIG2_MAX_OUTPUT_LENGTH = 16_000_000


class Invalid(Exception):
    pass


def ensure(condition, code="INVALID_DOCUMENT"):
    if not condition:
        raise Invalid(code)


class LimitedFile:
    def __init__(self, path, limit):
        self.file = open(path, "w+b")
        self.limit = limit

    def write(self, data):
        ensure(self.file.tell() + len(data) <= self.limit, "OUTPUT_LIMIT_EXCEEDED")
        return self.file.write(data)

    def __getattr__(self, name):
        return getattr(self.file, name)


def zip_guard(path, limits):
    archive = zipfile.ZipFile(path)
    infos = archive.infolist()
    ensure(0 < len(infos) <= limits["max_zip_entries"], "DOCUMENT_TOO_COMPLEX")
    ensure(len({info.filename for info in infos}) == len(infos))
    total = 0
    for info in infos:
        name = info.filename.replace("\\", "/")
        ensure(not name.startswith("/") and ".." not in name.split("/") and ":" not in name)
        ensure(not (info.flag_bits & 1), "PROTECTED_DOCUMENT")
        total += info.file_size
        ensure(total <= limits["max_unpacked_bytes"], "DOCUMENT_TOO_COMPLEX")
        ensure(info.file_size <= max(1, info.compress_size) * 200, "DOCUMENT_TOO_COMPLEX")
        ensure(not name.lower().endswith((".exe", ".js", ".vbs")), "UNSAFE_DOCUMENT")
        if '/media/' in name.lower():
            ensure(name.lower().endswith(('.png', '.jpg', '.jpeg')), 'UNSAFE_DOCUMENT')
            from PIL import Image
            with Image.open(io.BytesIO(archive.read(info))) as image:
                ensure(image.width * image.height <= limits['max_render_pixels'], 'DOCUMENT_TOO_COMPLEX')
        if name.lower().endswith('.bin'):
            ensure('/printersettings/' in name.lower() and info.file_size <= 65536, "UNSAFE_DOCUMENT")
        if name.endswith((".xml", ".rels")):
            data = archive.read(info)
            ensure(b"<!DOCTYPE" not in data.upper() and b"<!ENTITY" not in data.upper(), "UNSAFE_DOCUMENT")
            root = SafeET.fromstring(data)
            instructions = ''.join((node.text or '') for node in root.iter() if node.tag.endswith('}instrText')).upper()
            ensure(not any(word in ''.join(instructions.split()) for word in ('DDE', 'INCLUDETEXT', 'INCLUDEPICTURE', 'DATABASE')), 'UNSAFE_DOCUMENT')
            for node in root.iter():
                if node.tag.endswith(('}instrText', '}fldSimple')):
                    instruction = ' '.join([node.text or '', *node.attrib.values()]).upper()
                    ensure(not any(word in instruction for word in ('DDE', 'INCLUDETEXT', 'INCLUDEPICTURE', 'DATABASE', 'LINK ')), 'UNSAFE_DOCUMENT')
                if node.tag.endswith('}f'):
                    formula = (node.text or '').upper()
                    ensure(not any(word in formula for word in ('WEBSERVICE(', 'DDE(', 'RTD(', 'IMAGE(', 'HTTP:', 'HTTPS:', 'FILE:')), 'UNSAFE_DOCUMENT')
                if node.attrib.get("TargetMode") == "External":
                    ensure(node.attrib.get("Type", "").endswith("/hyperlink"), "EXTERNAL_REFERENCE")
                ensure(not node.tag.endswith(("}attachedTemplate", "}oleObject", "}altChunk")), "UNSAFE_DOCUMENT")
    return archive


def pdf_read(path, limits):
    reader = PdfReader(path, strict=True)
    ensure(not reader.is_encrypted, "PROTECTED_DOCUMENT")
    ensure(0 < len(reader.pages) <= limits["max_pages"], "DOCUMENT_TOO_COMPLEX")
    root = reader.trailer["/Root"]
    ensure(not any(key in root for key in ("/OpenAction", "/AA", "/AcroForm")), "UNSAFE_DOCUMENT")
    names = root.get("/Names", {})
    ensure(not any(key in names for key in ("/JavaScript", "/EmbeddedFiles")), "UNSAFE_DOCUMENT")
    total = 0
    for page in reader.pages:
        w, h = float(page.mediabox.width), float(page.mediabox.height)
        ensure(math.isfinite(w) and math.isfinite(h) and 0 < w <= 3000 and 0 < h <= 3000, "DOCUMENT_TOO_COMPLEX")
        ensure(not page.get("/AA"), "UNSAFE_DOCUMENT")
        for annotation in page.get("/Annots", []):
            action = annotation.get_object().get("/A", {})
            ensure(not action or action.get("/S") == "/URI", "UNSAFE_DOCUMENT")
        resources = page.get("/Resources", {})
        resources = resources.get_object() if hasattr(resources, 'get_object') else resources
        objects = resources.get("/XObject", {})
        objects = objects.get_object() if hasattr(objects, 'get_object') else objects
        for item in objects.values():
            obj = item.get_object()
            if obj.get("/Subtype") == "/Image":
                ensure(int(obj.get("/Width", 0)) * int(obj.get("/Height", 0)) <= limits["max_render_pixels"], "DOCUMENT_TOO_COMPLEX")
        # Decode content with pypdf's bounded filters before native rendering.
        contents = page.get_contents()
        total += len(contents.get_data()) if contents else 0
        ensure(total <= limits["max_unpacked_bytes"], "DOCUMENT_TOO_COMPLEX")
    return reader


def spreadsheet(path, task):
    limits = task["limits"]
    if task["source"] == "csv":
        wb = openpyxl.Workbook(write_only=True)
        sheet = wb.create_sheet("Données")
        with open(path, encoding="utf-8-sig", newline="") as source:
            sample = source.read(8192)
            ensure("\x00" not in sample)
            source.seek(0)
            try:
                dialect = csv.Sniffer().sniff(sample, delimiters=",;\t")
            except csv.Error:
                dialect = csv.excel
            for number, row in enumerate(csv.reader(source, dialect), 1):
                ensure(number <= limits["max_rows"] and len(row) <= limits["max_columns"], "DOCUMENT_TOO_COMPLEX")
                cells = []
                for value in row:
                    ensure(len(value) <= 32767 and "\x00" not in value, "DOCUMENT_TOO_COMPLEX")
                    cell = openpyxl.cell.WriteOnlyCell(sheet, value=value)
                    cell.data_type = "s"  # Never create executable spreadsheet formulas from CSV.
                    cells.append(cell)
                sheet.append(cells)
        sink = LimitedFile(task["output"], task["max_bytes"])
        try:
            wb.save(sink)
        finally:
            sink.close()
    else:
        zip_guard(path, limits).close()
        wb = openpyxl.load_workbook(path, read_only=True, data_only=False, keep_links=False)
        try:
            sheet_number = task["options"].get("sheet", 1)
            ensure(1 <= sheet_number <= len(wb.worksheets), "INVALID_OPTIONS")
            sheet = wb.worksheets[sheet_number - 1]
            ensure((sheet.max_row or 0) <= limits["max_rows"] and (sheet.max_column or 0) <= limits["max_columns"], "DOCUMENT_TOO_COMPLEX")
            with open(task["output"], "w", encoding="utf-8-sig", newline="") as destination:
                writer = csv.writer(destination)
                for number, row in enumerate(sheet.iter_rows(), 1):
                    ensure(number <= limits["max_rows"] and len(row) <= limits["max_columns"], "DOCUMENT_TOO_COMPLEX")
                    values = []
                    for cell in row:
                        value = "" if cell.value is None else str(cell.value)
                        ensure(len(value) <= 32767, "DOCUMENT_TOO_COMPLEX")
                        if isinstance(cell.value, str) and value.lstrip().startswith(("=", "+", "-", "@", "\t", "\r")):
                            value = "'" + value  # Prevent formula injection when CSV is reopened.
                        values.append(value)
                    writer.writerow(values)
                    destination.flush()
                    ensure(destination.tell() <= task["max_bytes"], "OUTPUT_LIMIT_EXCEEDED")
        finally:
            wb.close()


def convert(task):
    path, output, limits = task["input"], task["output"], task["limits"]
    tool = task["tool"]
    if tool in ("csv-vers-xlsx", "xlsx-vers-csv"):
        spreadsheet(path, task)
        if task["target"] == "xlsx":
            zip_guard(output, limits).close()
            wb = openpyxl.load_workbook(output, read_only=True)
            wb.close()
        return
    if tool == "office-vers-pdf":
        archive = zip_guard(path, limits)
        required = {"docx": "word/document.xml", "xlsx": "xl/workbook.xml", "pptx": "ppt/presentation.xml"}[task["source"]]
        ensure(required in archive.namelist())
        archive.close()
        if task['source'] == 'xlsx':
            workbook = openpyxl.load_workbook(path, read_only=True, keep_links=False)
            try:
                ensure(len(workbook.worksheets) <= 20, 'DOCUMENT_TOO_COMPLEX')
                for sheet in workbook.worksheets:
                    ensure((sheet.max_row or 0) <= limits['max_rows'] and (sheet.max_column or 0) <= limits['max_columns'], 'DOCUMENT_TOO_COMPLEX')
            finally:
                workbook.close()
        profile = Path(path).parent / "lo-profile"
        profile.mkdir(exist_ok=True)
        (profile / "user").mkdir(exist_ok=True)
        (profile / "user" / "registrymodifications.xcu").write_text('''<?xml version="1.0"?><oor:items xmlns:oor="http://openoffice.org/2001/registry"><item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item><item oor:path="/org.openoffice.Office.Common/Load"><prop oor:name="UpdateDocMode" oor:op="fuse"><value>0</value></prop></item></oor:items>''', encoding="utf-8")
        native = Path(path).parent / "lo-output"
        native.mkdir(exist_ok=True)
        filters = {"docx": "writer_pdf_Export", "xlsx": "calc_pdf_Export", "pptx": "impress_pdf_Export"}
        command = [task["libreoffice"], "-env:UserInstallation=" + profile.resolve().as_uri(), "--headless", "--nologo", "--nodefault", "--norestore", "--unaccept=all", "--convert-to", "pdf:" + filters[task["source"]], "--outdir", str(native), path]
        child = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=os.name != "nt")
        def stop_child(*_):
            if child.poll() is None:
                if os.name == "nt":
                    child.kill()
                else:
                    os.killpg(child.pid, signal.SIGKILL)
            raise Invalid("PROCESS_TIMEOUT")
        if os.name != "nt":
            signal.signal(signal.SIGTERM, stop_child)
        try:
            child.wait(timeout=max(1, task["timeout"] - 2))
            ensure(child.returncode == 0)
            result = native / (Path(path).stem + ".pdf")
            ensure(result.is_file() and result.stat().st_size <= task["max_bytes"], "OUTPUT_LIMIT_EXCEEDED")
            pdf_read(str(result), limits)
            result.replace(output)
        finally:
            if child.poll() is None:
                stop_child()
            # Profile and temporary output are bounded and removed before releasing the job's reservation.
            import shutil
            shutil.rmtree(profile, ignore_errors=True)
            shutil.rmtree(native, ignore_errors=True)
        return
    if tool == "fusionner-pdf":
        archive = zip_guard(path, limits)
        writer = PdfWriter()
        sources = []
        with archive:
            for name in archive.namelist():
                ensure(name.endswith(".pdf"))
                source = io.BytesIO(archive.read(name))
                reader = pdf_read(source, limits)
                sources.append((source, reader))
                for page in reader.pages:
                    ensure(len(writer.pages) < limits["max_pages"], "DOCUMENT_TOO_COMPLEX")
                    writer.add_page(page)
    else:
        reader = pdf_read(path, limits)
        if tool == "pdf-vers-images":
            document = pdfium.PdfDocument(path)
            try:
                pixels = 0
                with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_STORED) as archive:
                    for index in range(len(document)):
                        page = document[index]
                        scale = task["options"].get("dpi", 96) / 72
                        w, h = page.get_size()
                        pixels += math.ceil(w * scale) * math.ceil(h * scale)
                        ensure(pixels <= limits["max_render_pixels"], "DOCUMENT_TOO_COMPLEX")
                        bitmap = page.render(scale=scale)
                        image = bitmap.to_pil()
                        data = io.BytesIO()
                        image.save(data, format="PNG")
                        ensure(os.path.getsize(output) + len(data.getvalue()) + 65536 <= task["max_bytes"], "OUTPUT_LIMIT_EXCEEDED")
                        archive.writestr(f"page-{index + 1:03d}.png", data.getvalue())
                        image.close(); bitmap.close(); page.close()
            finally:
                document.close()
            return
        writer = PdfWriter()
        first, last = task["options"].get("first_page", 1), task["options"].get("last_page", len(reader.pages))
        if tool == "diviser-pdf":
            ensure(1 <= first <= last <= len(reader.pages), "INVALID_OPTIONS")
            pages = list(reader.pages)[first - 1:last]
        else:
            pages = reader.pages
        for page in pages:
            writer.add_page(page)
        if tool == "tourner-pdf":
            for page in writer.pages:
                page.rotate(task["options"].get("angle", 90))
        if tool == "compresser-pdf":
            for page in writer.pages:
                page.compress_content_streams(level=6)
    sink = LimitedFile(output, task["max_bytes"])
    try:
        writer.write(sink)
    finally:
        sink.close()
        writer.close()
    pdf_read(output, limits)


def main():
    task = json.loads(sys.stdin.buffer.read(32768))
    task["options"] = task.get("options") or {}
    ensure(isinstance(task["options"], dict), "INVALID_OPTIONS")
    ensure(os.getuid() != 0 if hasattr(os, "getuid") else True, "PRIVILEGED_WORKER")
    if os.name != "nt":
        import resource
        resource.setrlimit(resource.RLIMIT_AS, (512 * 1024 * 1024, 512 * 1024 * 1024))
        resource.setrlimit(resource.RLIMIT_CPU, (task["timeout"], task["timeout"] + 1))
    else:
        windows_job_limits()
    import socket
    class NoNetwork(socket.socket):
        def __new__(cls, *args, **kwargs):
            raise Invalid("NETWORK_DISABLED")
    socket.socket = NoNetwork
    lease = open(task["lease"], "a+b")
    if os.name == "nt":
        import ctypes
        import msvcrt
        kernel = ctypes.WinDLL("kernel32", use_last_error=True)
        class Overlapped(ctypes.Structure):
            _fields_ = [("Internal", ctypes.c_size_t), ("InternalHigh", ctypes.c_size_t), ("Offset", ctypes.c_ulong), ("OffsetHigh", ctypes.c_ulong), ("hEvent", ctypes.c_void_p)]
        kernel.LockFileEx.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.POINTER(Overlapped)]
        overlap = Overlapped()
        ensure(kernel.LockFileEx(msvcrt.get_osfhandle(lease.fileno()), 1, 0, 0xFFFFFFFF, 0xFFFFFFFF, ctypes.byref(overlap)), "WORKER_LEASE_FAILED")
    else:
        import fcntl
        fcntl.flock(lease, fcntl.LOCK_SH | fcntl.LOCK_NB)
    try:
        convert(task)
        ensure(0 < os.path.getsize(task["output"]) <= task["max_bytes"], "INVALID_OUTPUT")
    finally:
        lease.close()


_job_handle = None


def windows_job_limits():
    """Bound native parsers and all LibreOffice descendants, including after parent death."""
    import ctypes
    from ctypes import wintypes
    class Basic(ctypes.Structure):
        _fields_ = [("ProcessTime", ctypes.c_longlong), ("JobTime", ctypes.c_longlong), ("Flags", wintypes.DWORD), ("MinWorking", ctypes.c_size_t), ("MaxWorking", ctypes.c_size_t), ("Active", wintypes.DWORD), ("Affinity", ctypes.c_size_t), ("Priority", wintypes.DWORD), ("Scheduling", wintypes.DWORD)]
    class Io(ctypes.Structure):
        _fields_ = [(name, ctypes.c_ulonglong) for name in ("ReadOps", "WriteOps", "OtherOps", "ReadBytes", "WriteBytes", "OtherBytes")]
    class Extended(ctypes.Structure):
        _fields_ = [("Basic", Basic), ("Io", Io), ("ProcessMemory", ctypes.c_size_t), ("JobMemory", ctypes.c_size_t), ("PeakProcess", ctypes.c_size_t), ("PeakJob", ctypes.c_size_t)]
    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateJobObjectW.restype = wintypes.HANDLE
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    kernel.SetInformationJobObject.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD]
    kernel.AssignProcessToJobObject.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    global _job_handle
    _job_handle = kernel.CreateJobObjectW(None, None)
    settings = Extended()
    settings.Basic.Flags = 0x2000 | 0x100 | 0x200 | 0x8  # kill on close, per-process/job memory, process count
    settings.Basic.Active = 8
    settings.ProcessMemory = 512 * 1024 * 1024
    settings.JobMemory = 768 * 1024 * 1024
    ensure(_job_handle and kernel.SetInformationJobObject(_job_handle, 9, ctypes.byref(settings), ctypes.sizeof(settings)), "WORKER_LIMITS_FAILED")
    ensure(kernel.AssignProcessToJobObject(_job_handle, kernel.GetCurrentProcess()), "WORKER_LIMITS_FAILED")


if __name__ == "__main__":
    try:
        main()
        print("OK", end="")
    except Invalid as error:
        print(str(error), end="")
        sys.exit(1)
    except Exception:
        print("INVALID_DOCUMENT", end="")
        sys.exit(1)
