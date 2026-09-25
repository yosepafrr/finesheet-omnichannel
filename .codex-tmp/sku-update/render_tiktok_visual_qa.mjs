import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "C:/Bisnis/finesheet-omnichannel/outputs/shopee-to-tiktok/inspection/tiktok-final-visual-qa.xlsx";
const outputDir = "C:/Bisnis/finesheet-omnichannel/outputs/shopee-to-tiktok/inspection/final-previews";
await fs.mkdir(outputDir, { recursive: true });
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(inputPath));

for (let index = 1; index <= 5; index += 1) {
  const sheetName = `File ${index}`;
  const inspection = await workbook.inspect({
    kind: "table",
    range: `${sheetName}!A1:D20`,
    include: "values,formulas",
    tableMaxRows: 20,
    tableMaxCols: 4,
    maxChars: 10000,
  });
  console.log(`FILE ${index}\n${inspection.ndjson}`);
  const preview = await workbook.render({ sheetName, range: "A1:D20", scale: 1.2, format: "png" });
  await fs.writeFile(path.join(outputDir, `tiktok-${index}-final.png`), new Uint8Array(await preview.arrayBuffer()));
}
