import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "C:/Bisnis/finesheet-omnichannel/outputs/shopee-to-tiktok/Tiktoksellercenter_batchedit_20260925_all_information_template_3_SKU_updated.xlsx";
const outputPath = "C:/Bisnis/finesheet-omnichannel/outputs/shopee-to-tiktok/inspection/tiktok-3-final.png";
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(inputPath));
const inspection = await workbook.inspect({
  kind: "table",
  range: "Template!E1:M7",
  include: "values,formulas",
  tableMaxRows: 7,
  tableMaxCols: 9,
  maxChars: 9000,
});
console.log(inspection.ndjson);
const preview = await workbook.render({ sheetName: "Template", range: "E1:M7", scale: 1.1, format: "png" });
await fs.writeFile(outputPath, new Uint8Array(await preview.arrayBuffer()));
console.log(outputPath);
