import zipfile
from pathlib import Path


source = Path(r"C:\Bisnis\Vilion Apparel\Products SKUs\mass_update_sales_info_615078427_20260925103824.xlsx")
destination = Path(r"C:\Bisnis\finesheet-omnichannel\outputs\sku-update\working\mass_update_sales_sanitized.xlsx")
destination.parent.mkdir(parents=True, exist_ok=True)

with zipfile.ZipFile(source, "r") as source_zip, zipfile.ZipFile(destination, "w") as destination_zip:
    for item in source_zip.infolist():
        data = source_zip.read(item.filename)
        if item.filename == "xl/worksheets/sheet1.xml":
            data = data.replace(b'activePane="bottom_left"', b'activePane="bottomLeft"')
        destination_zip.writestr(item, data)

print(destination)
