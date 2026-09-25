import re
import zipfile
from pathlib import Path

p = Path(r"C:\Bisnis\Vilion Apparel\Products SKUs\Shopee to tiktok\Tiktoksellercenter_batchedit_20260925_all_information_template\Tiktoksellercenter_batchedit_20260925_all_information_template_1.xlsx")
with zipfile.ZipFile(p) as z:
    x = z.read("xl/worksheets/sheet1.xml").decode("utf-8")
for coordinate in ["H6", "K6", "K7"]:
    pattern = re.compile(rf'<c\b(?=[^>]*\br="{coordinate}")[^>]*(?:/>|>.*?</c>)', re.DOTALL)
    match = pattern.search(x)
    print(coordinate, repr(match.group(0) if match else None))
