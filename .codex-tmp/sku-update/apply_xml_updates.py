import csv
import html
import re
import zipfile
from pathlib import Path


source_path = Path(r"C:\Bisnis\Vilion Apparel\Products SKUs\mass_update_sales_info_615078427_20260925103824.xlsx")
updates_path = Path(r"C:\Bisnis\finesheet-omnichannel\outputs\sku-update\inspection\proposed_sku_updates.csv")
output_path = Path(r"C:\Bisnis\finesheet-omnichannel\outputs\sku-update\mass_update_sales_info_615078427_20260925103824_SKU_updated.xlsx")

with updates_path.open("r", encoding="utf-8-sig", newline="") as handle:
    updates = {int(row["row"]): row["sku"] for row in csv.DictReader(handle)}

with zipfile.ZipFile(source_path, "r") as source_zip:
    sheet_xml = source_zip.read("xl/worksheets/sheet1.xml").decode("utf-8")
    original_sheet_xml = sheet_xml

    for row_number, sku in sorted(updates.items()):
        coordinate = f"F{row_number}"
        pattern = re.compile(
            rf'<c\b(?=[^>]*\br="{re.escape(coordinate)}")[^>]*>(?P<body>.*?)</c>',
            flags=re.DOTALL,
        )
        match = pattern.search(sheet_xml)
        if not match:
            raise RuntimeError(f"Cell {coordinate} was not found in the template XML")
        body = match.group("body")
        if re.search(r"<(?:v|is)\b", body):
            raise RuntimeError(f"Refusing to overwrite populated cell {coordinate}: {match.group(0)}")
        opening_tag = match.group(0).split(">", 1)[0] + ">"
        opening_tag = re.sub(r'\s+t="[^"]*"', "", opening_tag)
        opening_tag = opening_tag[:-1] + ' t="inlineStr">'
        replacement = f"{opening_tag}<is><t>{html.escape(str(sku))}</t></is></c>"
        sheet_xml = sheet_xml[: match.start()] + replacement + sheet_xml[match.end() :]

    if sheet_xml == original_sheet_xml:
        raise RuntimeError("No XML changes were made")

    output_path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output_path, "w") as output_zip:
        for item in source_zip.infolist():
            data = sheet_xml.encode("utf-8") if item.filename == "xl/worksheets/sheet1.xml" else source_zip.read(item.filename)
            output_zip.writestr(item, data)

print(f"Wrote {len(updates)} SKU values to {output_path}")
