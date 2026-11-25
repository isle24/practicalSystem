use super::{
    attachments::{decode_text, prefix},
    TEXT_BYTES,
};
use calamine::{Data, DataRef, Reader, SheetType, Xlsx};
use serde_json::{json, Value};
use std::io::{self, BufReader, Cursor, Read};

const SHEETS: usize = 6;
const ROWS: usize = 40;
const COLUMNS: usize = 16;
const CELL_BYTES: usize = 240;
const FOOTER_BYTES: usize = 180;
const EXPANDED_BYTES: u64 = 32 * 1024 * 1024;
const LIMITS: &str = "每张表最多读取 40 行、前 16 列，每个单元格最多 240 字节。";

struct Page {
    text: String,
    offset: usize,
    bytes: usize,
    last_row: Option<usize>,
    row_start: usize,
    next: Option<usize>,
    truncated: bool,
}

impl Page {
    fn new(offset: usize, bytes: usize) -> Self {
        Self {
            text: String::new(),
            offset,
            bytes,
            last_row: None,
            row_start: 0,
            next: None,
            truncated: false,
        }
    }

    fn cell(&mut self, row: usize, column: usize, value: &str) -> bool {
        if row < self.offset {
            return true;
        }
        if row >= self.offset.saturating_add(ROWS) {
            self.truncated = true;
            self.next = Some(row);
            return false;
        }
        if value.is_empty() {
            return true;
        }
        if column >= COLUMNS {
            self.truncated = true;
            return true;
        }
        let clipped = prefix(value, CELL_BYTES);
        let suffix = if clipped.len() < value.len() {
            self.truncated = true;
            "…"
        } else {
            ""
        };
        let label = char::from(b'A' + column as u8);
        let cell = format!("{label}{}={}{}", row + 1, json!(clipped), suffix);
        let same_row = self.last_row == Some(row);
        if self.text.len() + cell.len() + 2 > self.bytes {
            self.truncated = true;
            if same_row && self.row_start > 0 {
                self.text.truncate(self.row_start);
            }
            self.next = Some(row);
            return false;
        }
        if same_row {
            self.text.push_str("  ");
        } else {
            self.row_start = self.text.len();
            self.text.push('\n');
        }
        self.text.push_str(&cell);
        self.last_row = Some(row);
        true
    }

    fn render(self, output: &mut String, multiple_sheets: bool) {
        if self.text.is_empty() {
            output.push_str("\n当前范围内没有非空单元格。");
        } else {
            output.push_str(&self.text);
        }
        if self.truncated {
            output.push_str("\n这张表的预览已截断。");
        }
        if let Some(next) = self.next {
            if multiple_sheets {
                output.push_str(&format!(" 继续读取请指定此工作表 sheet 和 offset={next}。"));
            } else {
                output.push_str(&format!(" 继续读取可指定 offset={next}。"));
            }
        }
        output.push('\n');
    }
}

pub(super) fn csv(name: &str, bytes: &[u8], delimiter: u8, offset: usize) -> Result<Value, String> {
    let decoded = decode_text(bytes)?;
    let mut reader = csv::ReaderBuilder::new()
        .delimiter(delimiter)
        .has_headers(false)
        .flexible(true)
        .from_reader(decoded.as_bytes());
    let mut text = format!(
        "表格：{}\n{LIMITS}\n从第 {} 行读取。",
        json!(name),
        offset.saturating_add(1)
    );
    let mut page = Page::new(offset, TEXT_BYTES - text.len() - FOOTER_BYTES);
    'records: for (row, record) in reader.records().enumerate() {
        let record = record.map_err(|_| "CSV / TSV 表格格式无效")?;
        if row < offset {
            continue;
        }
        for (column, value) in record.iter().enumerate() {
            if !page.cell(row, column, value) {
                break 'records;
            }
        }
    }
    let (truncated, next) = (page.truncated, page.next);
    page.render(&mut text, false);
    Ok(json!({"name":name,"kind":"table","text":text,"truncated":truncated,"next_offset":next}))
}

fn check_workbook(bytes: &[u8]) -> Result<(), String> {
    let mut zip = xlsx_zip::ZipArchive::new(Cursor::new(bytes)).map_err(|_| "XLSX 文件格式无效")?;
    if zip.len() > 1024 {
        return Err("XLSX 文件条目超过 1024 个".into());
    }
    let mut size = 0u64;
    for i in 0..zip.len() {
        let mut entry = zip.by_index(i).map_err(|_| "XLSX 无法解压或需要密码")?;
        size = size.saturating_add(entry.size());
        if size > EXPANDED_BYTES {
            return Err("XLSX 展开后超过 32 MiB".into());
        }
        let name = entry
            .name()
            .map_err(|_| "XLSX 条目名称无效")?
            .to_ascii_lowercase();
        if name.ends_with(".xml") || name.ends_with(".rels") {
            let declared = entry.size();
            let mut bounded = (&mut entry).take(declared + 1);
            if name.ends_with("sharedstrings.xml") {
                // 工作簿解析器会按声明的唯一字符串数量预分配内存。
                let mut xml = quick_xml::Reader::from_reader(BufReader::new(&mut bounded));
                let mut buffer = Vec::new();
                loop {
                    use quick_xml::events::Event;
                    match xml
                        .read_event_into(&mut buffer)
                        .map_err(|_| "共享字符串表损坏")?
                    {
                        Event::Start(root) | Event::Empty(root)
                            if root.local_name().as_ref() == "sst" =>
                        {
                            if let Some(attr) = root
                                .try_get_attribute("uniqueCount")
                                .map_err(|_| "共享字符串数量无效")?
                            {
                                let count = attr
                                    .value
                                    .parse::<u64>()
                                    .map_err(|_| "共享字符串数量无效")?;
                                if count > (declared / 4).min(1_000_000) {
                                    return Err("XLSX 共享字符串数量超出可读范围".into());
                                }
                            }
                            break;
                        }
                        Event::Eof => break,
                        _ => buffer.clear(),
                    }
                }
            }
            io::copy(&mut bounded, &mut io::sink()).map_err(|_| "XLSX 内容损坏")?;
            let actual = declared + 1 - bounded.limit();
            if actual != declared {
                return Err("XLSX 条目大小无效".into());
            }
        }
    }
    Ok(())
}

fn cell_text(value: DataRef<'_>, formula: Option<String>) -> String {
    match value {
        DataRef::Empty => formula.map(|text| format!("={text}")).unwrap_or_default(),
        DataRef::DateTime(date) if date.is_datetime() => {
            let (year, month, day, hour, min, sec, ms) = date.to_ymd_hms_milli();
            if (hour, min, sec, ms) == (0, 0, 0, 0) {
                format!("{year:04}-{month:02}-{day:02}")
            } else {
                format!("{year:04}-{month:02}-{day:02} {hour:02}:{min:02}:{sec:02}.{ms:03}")
            }
        }
        value => Data::from(value).to_string(),
    }
}

pub(super) fn xlsx(
    name: &str,
    bytes: &[u8],
    offset: usize,
    selected: Option<&str>,
) -> Result<Value, String> {
    check_workbook(bytes)?;
    let mut workbook = Xlsx::new(Cursor::new(bytes)).map_err(|_| "无法读取 XLSX 工作簿")?;
    let names: Vec<_> = workbook
        .sheets_metadata()
        .iter()
        .filter(|sheet| sheet.typ == SheetType::WorkSheet)
        .map(|sheet| sheet.name.clone())
        .collect();
    let shown: Vec<_> = match selected {
        Some(sheet) => vec![names
            .iter()
            .find(|name| name.as_str() == sheet)
            .ok_or("工作表名称不存在")?],
        None => names.iter().take(SHEETS).collect(),
    };
    let mut text = format!(
        "工作簿包含 {} 张工作表。\n{LIMITS}\n从第 {} 行读取。\n",
        names.len(),
        offset.saturating_add(1)
    );
    if shown.len() != 1 {
        let mut index = String::new();
        let mut count = 0;
        for name in names.iter().take(32) {
            let label = json!(prefix(name, 128)).to_string();
            if index.len() + label.len() + 3 > 4096 {
                break;
            }
            if count > 0 {
                index.push('、');
            }
            index.push_str(&label);
            count += 1;
        }
        let label = if count < names.len() {
            format!("前 {count} 个表名")
        } else {
            "表名".to_owned()
        };
        text.push_str(&format!("{label}：{index}\n"));
    }
    let mut truncated = selected.is_none() && shown.len() < names.len();
    if truncated {
        text.push_str("已截断工作表范围，仅展示前 6 张。可以指定 sheet 读取其他工作表。\n");
    }
    let per_sheet = (TEXT_BYTES - text.len() - 16) / shown.len().max(1);
    let mut next = None;
    for sheet in &shown {
        let heading = format!("\n工作表：{}", json!(prefix(sheet, 128)));
        let mut page = Page::new(
            offset,
            per_sheet.saturating_sub(heading.len() + FOOTER_BYTES),
        );
        text.push_str(&heading);
        let mut cells = workbook
            .worksheet_cells_reader(sheet)
            .map_err(|_| "无法读取工作表")?;
        while let Some(cell) = cells
            .next_cell_with_formula()
            .map_err(|_| "无法解析工作表单元格")?
        {
            let (row, column) = cell.pos;
            if !page.cell(
                row as usize,
                column as usize,
                &cell_text(cell.value, cell.formula),
            ) {
                break;
            }
        }
        truncated |= page.truncated;
        if shown.len() == 1 {
            next = page.next;
        }
        page.render(&mut text, shown.len() > 1);
    }
    Ok(json!({"name":name,"kind":"table","text":text,"truncated":truncated,"next_offset":next}))
}
