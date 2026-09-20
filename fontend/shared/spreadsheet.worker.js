import ExcelJS from 'exceljs';
import { validateOfficeArchive } from './officeArchive';

/** 在独立线程读取工作表，限制返回单元格数量。 */
self.onmessage = async ({ data }) => {
  try {
    validateOfficeArchive(data);
    const workbook = new ExcelJS.Workbook(); await workbook.xlsx.load(data);
    let budget = 100000;
    const sheets = workbook.worksheets.map(sheet => {
      const columns = Math.min(sheet.columnCount, 100);
      const count = columns ? Math.min(sheet.rowCount, 2000, Math.floor(budget / columns)) : 0;
      budget -= count * columns;
      return { name: sheet.name, truncated: sheet.rowCount > count || sheet.columnCount > columns, rows: Array.from({ length: count }, (_, i) => Array.from({ length: columns }, (_, j) => sheet.getCell(i + 1,j + 1).text)) };
    });
    self.postMessage({ sheets });
  } catch(e) { self.postMessage({ error:e.message || '表格读取失败，请下载查看' }); }
};
