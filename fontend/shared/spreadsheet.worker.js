import ExcelJS from 'exceljs';
import { validateOfficeArchive } from './officeArchive';

const MAX_COLUMNS = 100;
const MAX_ROWS = 2000;
const CELL_BUDGET = 100000;
const DEFAULT_COLUMN_WIDTH = 9.14;

function parseCellReference(reference) {
  const match = String(reference).trim().match(/^([A-Z]+)(\d+)$/i);
  if (!match) return null;
  let column = 0;
  for (const character of match[1].toUpperCase()) column = column * 26 + character.charCodeAt(0) - 64;
  return { row: Number(match[2]), column };
}

function parseMergeRange(range) {
  const [start, end] = String(range).split(':');
  const topLeft = parseCellReference(start);
  const bottomRight = parseCellReference(end || start);
  if (!topLeft || !bottomRight) return null;
  return {
    top: Math.min(topLeft.row, bottomRight.row),
    left: Math.min(topLeft.column, bottomRight.column),
    bottom: Math.max(topLeft.row, bottomRight.row),
    right: Math.max(topLeft.column, bottomRight.column),
  };
}

function colorValue(color) {
  if (!color) return '';
  const value = color.argb || color.rgb;
  if (!value) return '';
  const hex = String(value).replace(/^#/, '');
  if (hex.length === 8) return `#${hex.slice(2)}`;
  return hex.length === 6 ? `#${hex}` : '';
}

function cellStyle(cell) {
  const style = {};
  const alignment = cell.alignment || {};
  const horizontal = alignment.horizontal === 'centerContinuous' ? 'center' : alignment.horizontal;
  if (horizontal) style.textAlign = horizontal;
  if (alignment.vertical) style.verticalAlign = alignment.vertical === 'middle' ? 'middle' : alignment.vertical;
  if (alignment.wrapText) style.whiteSpace = 'pre-wrap';
  const fontColor = colorValue(cell.font?.color);
  const fillColor = colorValue(cell.fill?.fgColor);
  if (fontColor) style.color = fontColor;
  if (fillColor && cell.fill?.type === 'pattern') style.backgroundColor = fillColor;
  if (cell.font?.bold) style.fontWeight = '700';
  if (cell.font?.italic) style.fontStyle = 'italic';
  if (Number.isFinite(cell.font?.size)) style.fontSize = `${Math.max(10, Math.min(24, Math.round(cell.font.size * 1.333)))}px`;
  return style;
}

function columnLabel(index) {
  let value = '';
  for (let current = index; current > 0; current = Math.floor((current - 1) / 26)) {
    value = String.fromCharCode(65 + ((current - 1) % 26)) + value;
  }
  return value;
}

function columnWidth(sheet, index) {
  const width = Number(sheet.getColumn(index).width) || DEFAULT_COLUMN_WIDTH;
  return Math.max(48, Math.min(360, Math.round(width * 7 + 12)));
}

function rowHeight(sheet, index) {
  const height = Number(sheet.getRow(index).height) || 15;
  return Math.max(20, Math.min(200, Math.round(height * 1.333)));
}

function makeMerges(sheet, rows, columns) {
  return (sheet.model.merges || [])
    .map(parseMergeRange)
    .filter(Boolean)
    .map(merge => ({
      top: merge.top,
      left: merge.left,
      bottom: Math.min(merge.bottom, rows),
      right: Math.min(merge.right, columns),
    }))
    .filter(merge => merge.top <= merge.bottom && merge.left <= merge.right && merge.top <= rows && merge.left <= columns);
}

function makeRows(sheet, rowCount, columnCount, merges) {
  const covered = new Map();
  for (const merge of merges) {
    for (let row = merge.top; row <= merge.bottom; row += 1) {
      for (let column = merge.left; column <= merge.right; column += 1) {
        covered.set(`${row}:${column}`, merge);
      }
    }
  }
  return Array.from({ length: rowCount }, (_, rowIndex) => {
    const row = rowIndex + 1;
    const cells = [];
    for (let column = 1; column <= columnCount; column += 1) {
      const merge = covered.get(`${row}:${column}`);
      if (merge && (merge.top !== row || merge.left !== column)) continue;
      const cell = sheet.getCell(row, column);
      cells.push({
        key: `${row}:${column}`,
        text: cell.text || '',
        style: cellStyle(cell),
        rowspan: merge ? merge.bottom - merge.top + 1 : 1,
        colspan: merge ? merge.right - merge.left + 1 : 1,
      });
    }
    return cells;
  });
}

/** 在独立线程读取工作表，保留表格布局并限制预览规模。 */
self.onmessage = async ({ data }) => {
  try {
    validateOfficeArchive(data);
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.load(data);
    let budget = CELL_BUDGET;
    const sheets = workbook.worksheets.map(sheet => {
      const columns = Math.min(sheet.columnCount, MAX_COLUMNS);
      const count = columns ? Math.min(sheet.rowCount, MAX_ROWS, Math.floor(budget / columns)) : 0;
      budget -= count * columns;
      const merges = makeMerges(sheet, count, columns);
      return {
        name: sheet.name,
        columns,
        columnLabels: Array.from({ length: columns }, (_, index) => columnLabel(index + 1)),
        columnWidths: Array.from({ length: columns }, (_, index) => columnWidth(sheet, index + 1)),
        rowHeights: Array.from({ length: count }, (_, index) => rowHeight(sheet, index + 1)),
        truncated: sheet.rowCount > count || sheet.columnCount > columns,
        merges,
        rows: makeRows(sheet, count, columns, merges),
      };
    });
    self.postMessage({ sheets });
  } catch (error) {
    self.postMessage({ error: error.message || '表格读取失败，请下载查看' });
  }
};
