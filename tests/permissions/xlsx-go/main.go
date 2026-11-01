// Test-only reader for bounded single-sheet Phorge OOXML exports.
// No formula execution, external relationships, extraction or network I/O.
package main

import (
	"archive/zip"
	"encoding/json"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"math"
	"os"
	"path"
	"regexp"
	"runtime"
	"strconv"
	"strings"
)

const maxPart = 8 << 20
const maxExpanded = 32 << 20

var reference = regexp.MustCompile(`^([A-Z]{1,3})([1-9][0-9]{0,6})$`)

type text struct {
	T    string `xml:"t"`
	Runs []struct {
		T string `xml:"t"`
	} `xml:"r"`
}

func (t text) value() string {
	s := t.T
	for _, r := range t.Runs {
		s += r.T
	}
	return s
}

type cellXML struct {
	Ref    string  `xml:"r,attr"`
	Type   string  `xml:"t,attr"`
	Style  int     `xml:"s,attr"`
	V      string  `xml:"v"`
	F      *string `xml:"f"`
	Inline text    `xml:"is"`
}
type cell struct {
	Column        int    `json:"column"`
	Type          string `json:"type"`
	Value         string `json:"value"`
	Numeric       bool   `json:"numeric"`
	DateFormatted bool   `json:"dateFormatted"`
	HasFormula    bool   `json:"hasFormula"`
}
type row struct {
	Index int    `json:"index"`
	Cells []cell `json:"cells"`
}
type result struct {
	GoVersion    string `json:"goVersion"`
	Rows         []row  `json:"rows"`
	FormulaCells int    `json:"formulaCells"`
}

func stringsXML(raw []byte) ([]string, error) {
	d := xml.NewDecoder(strings.NewReader(string(raw)))
	values := []string{}
	for {
		t, e := d.Token()
		if e == io.EOF {
			return values, nil
		}
		if e != nil {
			return nil, e
		}
		s, ok := t.(xml.StartElement)
		if !ok || s.Name.Local != "si" {
			continue
		}
		if len(values) >= 20000 {
			return nil, errors.New("shared string budget exceeded")
		}
		var v text
		if e = d.DecodeElement(&v, &s); e != nil {
			return nil, e
		}
		value := v.value()
		if len(value) > 131072 {
			return nil, errors.New("shared string size exceeded")
		}
		values = append(values, value)
	}
}
func stylesXML(raw []byte) ([]bool, error) {
	if len(raw) == 0 {
		return []bool{false}, nil
	}
	var s struct {
		Formats []struct {
			ID   int    `xml:"numFmtId,attr"`
			Code string `xml:"formatCode,attr"`
		} `xml:"numFmts>numFmt"`
		XFs []struct {
			ID int `xml:"numFmtId,attr"`
		} `xml:"cellXfs>xf"`
	}
	if e := xml.Unmarshal(raw, &s); e != nil {
		return nil, e
	}
	if len(s.Formats) > 512 || len(s.XFs) > 512 {
		return nil, errors.New("style budget exceeded")
	}
	formats := map[int]string{}
	for _, f := range s.Formats {
		formats[f.ID] = strings.ToLower(f.Code)
	}
	out := []bool{}
	for _, f := range s.XFs {
		code := formats[f.ID]
		out = append(out, (f.ID >= 14 && f.ID <= 22) || (strings.Contains(code, "y") && strings.Contains(code, "d")))
	}
	return out, nil
}
func sheetXML(raw []byte, shared []string, styles []bool) (result, error) {
	out := result{GoVersion: runtime.Version(), Rows: []row{}}
	d := xml.NewDecoder(strings.NewReader(string(raw)))
	lastRow := 0
	for {
		t, e := d.Token()
		if e == io.EOF {
			return out, nil
		}
		if e != nil {
			return out, e
		}
		start, ok := t.(xml.StartElement)
		if !ok || start.Name.Local != "row" {
			continue
		}
		var rr struct {
			Index int       `xml:"r,attr"`
			Cells []cellXML `xml:"c"`
		}
		if e = d.DecodeElement(&rr, &start); e != nil {
			return out, e
		}
		if rr.Index <= lastRow || rr.Index > 4096 || len(rr.Cells) > 128 {
			return out, errors.New("invalid or excessive row")
		}
		lastRow = rr.Index
		r := row{Index: rr.Index, Cells: []cell{}}
		lastCol := 0
		for _, v := range rr.Cells {
			m := reference.FindStringSubmatch(v.Ref)
			if len(m) != 3 {
				return out, errors.New("invalid cell reference")
			}
			ri, e := strconv.Atoi(m[2])
			if e != nil || ri != rr.Index {
				return out, errors.New("cell row mismatch")
			}
			col := 0
			for _, c := range m[1] {
				col = col*26 + int(c-'A'+1)
			}
			if col <= lastCol || col > 128 {
				return out, errors.New("duplicate or excessive column")
			}
			lastCol = col
			if v.Style < 0 || v.Style >= len(styles) {
				return out, errors.New("invalid cell style")
			}
			c := cell{Column: col, Type: v.Type, Value: v.V, HasFormula: v.F != nil}
			if c.Type == "" {
				c.Type = "n"
			}
			switch c.Type {
			case "s":
				i, e := strconv.Atoi(v.V)
				if e != nil || i < 0 || i >= len(shared) {
					return out, errors.New("invalid shared string index")
				}
				c.Value = shared[i]
			case "inlineStr":
				c.Value = v.Inline.value()
			case "str", "b":
			case "n":
				if v.V != "" {
					n, e := strconv.ParseFloat(v.V, 64)
					if e != nil || math.IsNaN(n) || math.IsInf(n, 0) {
						return out, errors.New("invalid numeric value")
					}
					c.Numeric = true
					c.DateFormatted = styles[v.Style]
				}
			default:
				return out, errors.New("unsupported export cell type")
			}
			if c.HasFormula {
				out.FormulaCells++
			}
			r.Cells = append(r.Cells, c)
		}
		out.Rows = append(out.Rows, r)
	}
}
func validatePackage(parts map[string][]byte) error {
	const relBase = "http://schemas.openxmlformats.org/officeDocument/2006/relationships/"
	var types struct {
		Overrides []struct {
			Name string `xml:"PartName,attr"`
			Type string `xml:"ContentType,attr"`
		} `xml:"Override"`
	}
	if e := xml.Unmarshal(parts["[Content_Types].xml"], &types); e != nil {
		return e
	}
	kinds := map[string]string{}
	for _, v := range types.Overrides {
		if _, ok := kinds[v.Name]; ok {
			return errors.New("duplicate content type")
		}
		kinds[v.Name] = v.Type
	}
	if kinds["/xl/workbook.xml"] != "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml" || kinds["/xl/worksheets/sheet1.xml"] != "application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml" {
		return errors.New("native XLSX content types required")
	}
	var book struct {
		Sheets []struct {
			ID string `xml:"http://schemas.openxmlformats.org/officeDocument/2006/relationships id,attr"`
		} `xml:"sheets>sheet"`
	}
	if e := xml.Unmarshal(parts["xl/workbook.xml"], &book); e != nil {
		return e
	}
	if len(book.Sheets) != 1 || book.Sheets[0].ID == "" {
		return errors.New("one defined worksheet required")
	}
	type relationship struct {
		ID     string `xml:"Id,attr"`
		Type   string `xml:"Type,attr"`
		Target string `xml:"Target,attr"`
		Mode   string `xml:"TargetMode,attr"`
	}
	for _, part := range []string{"_rels/.rels", "xl/_rels/workbook.xml.rels"} {
		var rels struct {
			Rows []relationship `xml:"Relationship"`
		}
		if e := xml.Unmarshal(parts[part], &rels); e != nil {
			return e
		}
		matched := 0
		for _, r := range rels.Rows {
			if part == "_rels/.rels" && r.Type == relBase+"officeDocument" {
				if r.Mode != "" || strings.TrimPrefix(r.Target, "/") != "xl/workbook.xml" {
					return errors.New("invalid workbook relationship")
				}
				matched++
			}
			if part != "_rels/.rels" && r.ID == book.Sheets[0].ID {
				target := strings.TrimPrefix(r.Target, "/")
				if !strings.HasPrefix(r.Target, "/") {
					target = path.Clean("xl/" + target)
				}
				if r.Mode != "" || r.Type != relBase+"worksheet" || target != "xl/worksheets/sheet1.xml" {
					return errors.New("invalid worksheet relationship")
				}
				matched++
			}
		}
		if matched != 1 {
			return errors.New("missing or duplicate workbook relationship")
		}
	}
	return nil
}

func readWorkbook(filename string) (result, error) {
	var out result
	info, err := os.Stat(filename)
	if err != nil || info.Size() > maxPart {
		return out, errors.New("compressed file budget exceeded")
	}
	z, e := zip.OpenReader(filename)
	if e != nil {
		return out, e
	}
	defer z.Close()
	if len(z.File) > 64 {
		return out, errors.New("ZIP entry budget exceeded")
	}
	parts := map[string][]byte{}
	total := uint64(0)
	sheets := 0
	for _, f := range z.File {
		if path.Clean(f.Name) != f.Name || strings.HasPrefix(f.Name, "/") || strings.HasPrefix(f.Name, "../") {
			return out, errors.New("unsafe ZIP entry")
		}
		if _, ok := parts[f.Name]; ok {
			return out, errors.New("duplicate ZIP entry")
		}
		total += f.UncompressedSize64
		if f.UncompressedSize64 > maxPart || total > maxExpanded {
			return out, errors.New("expanded ZIP budget exceeded")
		}
		if strings.HasPrefix(f.Name, "xl/worksheets/") && strings.HasSuffix(f.Name, ".xml") {
			sheets++
		}
		r, e := f.Open()
		if e != nil {
			return out, e
		}
		raw, e := io.ReadAll(io.LimitReader(r, maxPart+1))
		r.Close()
		if e != nil || len(raw) > maxPart {
			return out, errors.New("invalid or excessive ZIP part")
		}
		parts[f.Name] = raw
	}
	if sheets != 1 || len(parts["xl/worksheets/sheet1.xml"]) == 0 {
		return out, errors.New("one native export worksheet required")
	}
	if e := validatePackage(parts); e != nil {
		return out, e
	}
	shared, e := stringsXML(parts["xl/sharedStrings.xml"])
	if e != nil {
		return out, e
	}
	styles, e := stylesXML(parts["xl/styles.xml"])
	if e != nil {
		return out, e
	}
	return sheetXML(parts["xl/worksheets/sheet1.xml"], shared, styles)
}
func main() {
	if len(os.Args) != 2 {
		fmt.Fprintln(os.Stderr, "one owned XLSX path required")
		os.Exit(2)
	}
	r, e := readWorkbook(os.Args[1])
	if e != nil {
		fmt.Fprintln(os.Stderr, "bounded XLSX parsing failed")
		os.Exit(1)
	}
	if e = json.NewEncoder(os.Stdout).Encode(r); e != nil {
		os.Exit(1)
	}
}
