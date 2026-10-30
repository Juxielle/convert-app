from pathlib import Path
import sys
import zipfile

from docx import Document
from pptx import Presentation
from pptx.util import Inches
from openpyxl import Workbook
from pypdf import PdfWriter
from pypdf.generic import DecodedStreamObject, NameObject

directory = Path(sys.argv[1])
directory.mkdir(parents=True, exist_ok=True)
document = Document()
document.add_heading("Document de contrôle", 0)
document.add_paragraph("Bonjour, ce document vérifie la conversion Office vers PDF.")
document.save(directory / "sample.docx")
book = Workbook()
sheet = book.active
sheet.title = "Données"
sheet.append(["Nom", "Valeur"])
sheet.append(["Café", 42])
sheet.append(["Négatif", -5])
second = book.create_sheet("Seconde")
second.append(["Formule", "Texte"])
second.append(["=1+2", "0012"])
book.save(directory / "sample.xlsx")
slides = Presentation()
slide = slides.slides.add_slide(slides.slide_layouts[5])
slide.shapes.title.text = "Présentation de contrôle"
textbox = slide.shapes.add_textbox(Inches(1), Inches(2), Inches(6), Inches(1))
textbox.text_frame.text = "Bonjour depuis PowerPoint."
slides.save(directory / "sample.pptx")
(directory / "sample.csv").write_text('Nom,Valeur\n"Café, noir",0012\n=1+2,42\n', encoding="utf-8")
for name, pages in [("one.pdf", 1), ("two.pdf", 2), ("too-many.pdf", 51), ("encrypted.pdf", 1), ("compressible.pdf", 1)]:
    writer = PdfWriter()
    for index in range(pages):
        page = writer.add_blank_page(width=300 + index * 10, height=200)
        if name == "compressible.pdf":
            stream = DecodedStreamObject()
            stream.set_data(b"q 1 0 0 1 0 0 cm Q\n" * 10000)
            page[NameObject("/Contents")] = writer._add_object(stream)
    if name == "encrypted.pdf":
        writer.encrypt("test-only-password")
    with open(directory / name, "wb") as output:
        writer.write(output)
    writer.close()
with zipfile.ZipFile(directory / "external.docx", "w") as output, zipfile.ZipFile(directory / "sample.docx") as source:
    for info in source.infolist():
        data = source.read(info)
        if info.filename == "word/_rels/document.xml.rels":
            data = data.replace(b"</Relationships>", b'<Relationship Id="remote" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="http://127.0.0.1/private.png" TargetMode="External"/></Relationships>')
        output.writestr(info, data)
print("fixtures-ready")
