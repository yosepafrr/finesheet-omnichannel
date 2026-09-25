import re
import zipfile
from pathlib import Path


source_dir = Path(r"C:\Bisnis\Vilion Apparel\Products SKUs")
for file_path in sorted(source_dir.glob("*.xlsx")):
    print(f"FILE: {file_path.name}")
    with zipfile.ZipFile(file_path) as archive:
        for name in archive.namelist():
            if not re.fullmatch(r"xl/worksheets/sheet\d+\.xml", name):
                continue
            text = archive.read(name).decode("utf-8")
            panes = re.findall(r"<pane\b[^>]*/?>", text)
            if panes:
                print(f"  {name}: {panes}")
