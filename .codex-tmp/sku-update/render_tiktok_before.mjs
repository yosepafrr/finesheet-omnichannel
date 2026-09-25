import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputDir = "C:/Bisnis/Vilion Apparel/Products SKUs/Shopee to tiktok/Tiktoksellercenter_batchedit_20260925_all_information_template";
const outputDir = "C:/Bisnis/finesheet-omnichannel/outputs/shopee-to-tiktok/inspection/before";
await fs.mkdir(outputDir, { recursive: true });

for (let index = 1; index <= 5; index += 1) {
  const name = `Tiktoksellercenter_batchedit_20260925_all_information_template_${index}.xlsx`;
  const inputPath = path.join(inputDir, name);
  const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(inputPath));
  // Keep the preview focused on the target column. Rendering the product-description
  // column is unnecessarily expensive because it contains very long HTML strings.
  const range = index === 3 ? "I1:M7" : "I1:M15";
  const inspection = await workbook.inspect({
    kind: "table",
    range: `Template!${range}`,
    include: "values,formulas",
    tableMaxRows: 15,
    tableMaxCols: 5,
    maxChars: 14000,
  });
  console.log(`FILE ${index}\n${inspection.ndjson}`);
  const preview = await workbook.render({ sheetName: "Template", range, scale: 1.1, format: "png" });
  await fs.writeFile(path.join(outputDir, `tiktok-${index}-before.png`), new Uint8Array(await preview.arrayBuffer()));
}
