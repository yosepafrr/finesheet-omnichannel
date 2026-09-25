import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "C:/Bisnis/finesheet-omnichannel/outputs/sku-update/working/mass_update_sales_updated_sanitized_for_verification.xlsx";
const outputDir = "C:/Bisnis/finesheet-omnichannel/outputs/sku-update/inspection";
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(inputPath));

for (const range of ["A100:I125", "A320:I350", "A1505:I1555"]) {
  const inspection = await workbook.inspect({
    kind: "table",
    range: `Sheet1!${range}`,
    include: "values,formulas",
    tableMaxRows: 60,
    tableMaxCols: 9,
    maxChars: 18000,
  });
  console.log(inspection.ndjson);
  const preview = await workbook.render({ sheetName: "Sheet1", range, scale: 1.4, format: "png" });
  const fileName = `final-${range.replace(":", "-")}.png`;
  await fs.writeFile(path.join(outputDir, fileName), new Uint8Array(await preview.arrayBuffer()));
}

const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!",
  options: { useRegex: true, maxResults: 300 },
  summary: "final formula error scan",
});
console.log(errors.ndjson);
