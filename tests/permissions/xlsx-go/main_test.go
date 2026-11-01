package main

import (
	"archive/zip"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func workbook(t *testing.T, entries [][2]string) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), "owned.xlsx")
	f, e := os.Create(p)
	if e != nil {
		t.Fatal(e)
	}
	defaults := [][2]string{
		{"[Content_Types].xml", `<Types><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>`},
		{"xl/workbook.xml", `<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Native" sheetId="1" r:id="rId1"/></sheets></workbook>`},
		{"_rels/.rels", `<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`},
		{"xl/_rels/workbook.xml.rels", `<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>`},
	}
	for _, entry := range defaults {
		found := false
		for _, v := range entries {
			if v[0] == entry[0] {
				found = true
			}
		}
		if !found {
			entries = append(entries, entry)
		}
	}
	z := zip.NewWriter(f)
	for _, entry := range entries {
		w, e := z.Create(entry[0])
		if e != nil {
			t.Fatal(e)
		}
		if _, e = w.Write([]byte(entry[1])); e != nil {
			t.Fatal(e)
		}
	}
	if e = z.Close(); e != nil {
		t.Fatal(e)
	}
	if e = f.Close(); e != nil {
		t.Fatal(e)
	}
	return p
}
func TestReadNativeCellCoordinatesTypesAndLiteralText(t *testing.T) {
	p := workbook(t, [][2]string{{"xl/sharedStrings.xml", `<sst><si><t>标题</t></si><si><r><t>=1+2 </t></r><r><t>literal</t></r></si></sst>`}, {"xl/styles.xml", `<styleSheet><numFmts><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>`}, {"xl/worksheets/sheet1.xml", `<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2" s="1"><v>46000.5</v></c></row></sheetData></worksheet>`}})
	r, e := readWorkbook(p)
	if e != nil {
		t.Fatal(e)
	}
	if len(r.Rows) != 2 || r.Rows[0].Cells[0].Value != "标题" || r.Rows[1].Cells[0].Value != "=1+2 literal" || r.FormulaCells != 0 {
		t.Fatal("text/row interpretation differs")
	}
	c := r.Rows[1].Cells[1]
	if c.Column != 3 || !c.Numeric || !c.DateFormatted || c.Value != "46000.5" {
		t.Fatal("sparse numeric date changed")
	}
}
func TestRejectInvalidIndicesCoordinatesAndNumbers(t *testing.T) {
	for _, cells := range []string{`<c r="A2" t="s"><v>-1</v></c>`, `<c r="A2" t="s"><v>2</v></c>`, `<c r="A3"><v>1</v></c>`, `<c r="A2"/><c r="A2"/>`, `<c r="A2"><v>NaN</v></c>`, `<c r="A2" s="-1"/>`, `<c r="XFD2"/>`} {
		t.Run(cells, func(t *testing.T) {
			_, e := sheetXML([]byte(`<worksheet><sheetData><row r="2">`+cells+`</row></sheetData></worksheet>`), []string{"only"}, []bool{false})
			if e == nil {
				t.Fatal("malformed cell accepted")
			}
		})
	}
}
func TestRejectExcessiveOrRepeatedRows(t *testing.T) {
	for _, rows := range []string{`<row r="4097"/>`, `<row r="1"/><row r="1"/>`} {
		if _, e := sheetXML([]byte(`<worksheet><sheetData>`+rows+`</sheetData></worksheet>`), nil, []bool{false}); e == nil {
			t.Fatal("row budget/order accepted")
		}
	}
}
func TestFormulaIsReportedWithoutCalculation(t *testing.T) {
	r, e := sheetXML([]byte(`<worksheet><sheetData><row r="1"><c r="A1"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>`), nil, []bool{false})
	if e != nil || r.FormulaCells != 1 || !r.Rows[0].Cells[0].HasFormula || r.Rows[0].Cells[0].Value != "2" {
		t.Fatal("formula metadata/cached value lost")
	}
}
func TestRejectDuplicateAndUnsafeZIPParts(t *testing.T) {
	for _, name := range []string{"xl/worksheets/sheet1.xml", "../outside.xml"} {
		p := workbook(t, [][2]string{{"xl/worksheets/sheet1.xml", `<worksheet/>`}, {name, `<worksheet/>`}})
		if _, e := readWorkbook(p); e == nil {
			t.Fatal("ambiguous/unsafe archive accepted")
		}
	}
}
func TestRejectMultipleSheets(t *testing.T) {
	p := workbook(t, [][2]string{{"xl/worksheets/sheet1.xml", `<worksheet/>`}, {"xl/worksheets/sheet2.xml", `<worksheet/>`}})
	if _, e := readWorkbook(p); e == nil {
		t.Fatal("unexpected sheet accepted")
	}
}
func TestRejectExcessiveExpandedPart(t *testing.T) {
	p := workbook(t, [][2]string{{"xl/worksheets/sheet1.xml", strings.Repeat("x", maxPart+1)}})
	if _, e := readWorkbook(p); e == nil {
		t.Fatal("expansion budget ignored")
	}
}
func TestRejectNonZIPInput(t *testing.T) {
	p := filepath.Join(t.TempDir(), "bad.xlsx")
	if e := os.WriteFile(p, []byte("not a workbook"), 0600); e != nil {
		t.Fatal(e)
	}
	if _, e := readWorkbook(p); e == nil {
		t.Fatal("non-ZIP workbook accepted")
	}
}

func TestRejectExternalWorksheetRelationship(t *testing.T) {
	p := workbook(t, [][2]string{{"xl/worksheets/sheet1.xml", `<worksheet/>`}, {"xl/_rels/workbook.xml.rels", `<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="https://external.invalid/sheet.xml" TargetMode="External"/></Relationships>`}})
	if _, e := readWorkbook(p); e == nil {
		t.Fatal("external worksheet relationship accepted")
	}
}
func TestRejectWrongWorkbookContentType(t *testing.T) {
	p := workbook(t, [][2]string{{"xl/worksheets/sheet1.xml", `<worksheet/>`}, {"[Content_Types].xml", `<Types/>`}})
	if _, e := readWorkbook(p); e == nil {
		t.Fatal("non-XLSX package accepted")
	}
}
