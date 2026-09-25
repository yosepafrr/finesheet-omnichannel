import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const outputDir = "C:/Bisnis/finesheet-omnichannel/outputs/shopee-to-tiktok";
for (let index = 1; index <= 5; index += 1) {
  const fileName = `Tiktoksellercenter_batchedit_20260925_all_information_template_${index}_SKU_updated.xlsx`;
  const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(path.join(outputDir, fileName)));
  const errors = await workbook.inspect({
    kind: "match",
    searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!",
    options: { useRegex: true, maxResults: 100 },
    summary: `formula error scan file ${index}`,
  });
  console.log(`FILE ${index}\n${errors.ndjson}`);
}
