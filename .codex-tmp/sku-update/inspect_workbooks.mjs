import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const sourceDir = "C:/Bisnis/Vilion Apparel/Products SKUs";
const outputDir = "C:/Bisnis/finesheet-omnichannel/outputs/sku-update/inspection";
await fs.mkdir(outputDir, { recursive: true });

const entries = (await fs.readdir(sourceDir, { withFileTypes: true }))
  .filter((entry) => entry.isFile() && /\.xlsx$/i.test(entry.name))
  .map((entry) => entry.name)
  .sort();

const summaries = [];
for (const name of entries) {
  const fullPath = path.join(sourceDir, name);
  const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(fullPath));
  const sheets = workbook.worksheets.items.map((sheet) => {
    const used = sheet.getUsedRange();
    const values = used?.values ?? [];
    const formulas = used?.formulas ?? [];
    const maxRows = Math.min(values.length, 20);
    const maxCols = Math.min(values.reduce((m, row) => Math.max(m, row.length), 0), 40);
    return {
      name: sheet.name,
      usedAddress: used?.address ?? null,
      sampleValues: values.slice(0, maxRows).map((row) => row.slice(0, maxCols)),
      sampleFormulas: formulas.slice(0, maxRows).map((row) => row.slice(0, maxCols)),
    };
  });
  summaries.push({ file: name, sheets });

  if (/^mass_update_sales/i.test(name)) {
    for (const sheet of workbook.worksheets.items) {
      const used = sheet.getUsedRange();
      if (!used) continue;
      const preview = await workbook.render({
        sheetName: sheet.name,
        range: used.address,
        scale: 1,
        format: "png",
      });
      const safeName = sheet.name.replace(/[<>:"/\\|?*]/g, "_");
      await fs.writeFile(
        path.join(outputDir, `template-${safeName}.png`),
        new Uint8Array(await preview.arrayBuffer()),
      );
    }
  }
}

await fs.writeFile(
  path.join(outputDir, "summary.json"),
  JSON.stringify(summaries, null, 2),
  "utf8",
);
console.log(JSON.stringify(summaries, null, 2));
