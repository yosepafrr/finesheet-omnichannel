import fs from "node:fs/promises";
import path from "node:path";
import { Workbook } from "@oai/artifact-tool";

const dataPath = "C:/Bisnis/finesheet-omnichannel/outputs/tiktok-to-tiktok/inspection/before_preview_rows.json";
const outputDir = "C:/Bisnis/finesheet-omnichannel/outputs/tiktok-to-tiktok/inspection/before-previews";
const data = JSON.parse(await fs.readFile(dataPath, "utf8"));
await fs.mkdir(outputDir, { recursive: true });

const workbook = Workbook.create();
for (const item of data) {
  const sheet = workbook.worksheets.add(`Seosm ${item.index}`);
  const matrix = [["Baris", "ID Produk", "Nama Produk", "Variasi", "SKU Penjual"], ...item.rows];
  sheet.getRange("A1").write(matrix);
  sheet.getRange(`A1:E${matrix.length}`).format.font = { name: "Arial", size: 10 };
  sheet.getRange("A1:E1").format = {
    fill: "#1F4E78",
    font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" },
    verticalAlignment: "center",
  };
  sheet.getRange(`A1:E${matrix.length}`).format.verticalAlignment = "center";
  sheet.getRange(`A1:A${matrix.length}`).format.columnWidth = 10;
  sheet.getRange(`B1:B${matrix.length}`).format.columnWidth = 24;
  sheet.getRange(`C1:C${matrix.length}`).format.columnWidth = 42;
  sheet.getRange(`D1:D${matrix.length}`).format.columnWidth = 26;
  sheet.getRange(`E1:E${matrix.length}`).format.columnWidth = 28;
  sheet.freezePanes.freezeRows(1);
  const preview = await workbook.render({ sheetName: sheet.name, range: `A1:E${matrix.length}`, scale: 1.1, format: "png" });
  await fs.writeFile(path.join(outputDir, `seosm-${item.index}-before.png`), new Uint8Array(await preview.arrayBuffer()));
}
