import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "C:/Bisnis/finesheet-omnichannel/outputs/sku-update/working/mass_update_sales_sanitized.xlsx";
const outputPath = "C:/Bisnis/finesheet-omnichannel/outputs/sku-update/inspection/template-before.png";
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(inputPath));
const inspection = await workbook.inspect({
  kind: "table",
  range: "Sheet1!A1:O20",
  include: "values,formulas",
  tableMaxRows: 20,
  tableMaxCols: 15,
  maxChars: 12000,
});
console.log(inspection.ndjson);
const preview = await workbook.render({
  sheetName: "Sheet1",
  range: "A1:O35",
  scale: 1,
  format: "png",
});
await fs.writeFile(outputPath, new Uint8Array(await preview.arrayBuffer()));
console.log(outputPath);
