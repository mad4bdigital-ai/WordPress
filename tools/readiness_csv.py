"""Prevent spreadsheet formula evaluation in downloaded audit CSV files."""
import csv

def safe_csv_cell(value):
    if not isinstance(value, str):
        return value
    content = value.lstrip("\ufeff \t\r\n")
    return "'" + value if content.startswith(("=", "+", "-", "@")) else value

class SafeCSVWriter:
    def __init__(self, stream):
        self._writer = csv.writer(stream)
    def writerow(self, row):
        return self._writer.writerow([safe_csv_cell(x) for x in row])
    def writerows(self, rows):
        for row in rows:
            self.writerow(row)

def safe_writer(stream):
    return SafeCSVWriter(stream)

def self_test():
    for value in ("=2+1", "  =danger", "\t+2", "-SUM(1)", "@IMPORT", "\ufeff=1"):
        assert safe_csv_cell(value).startswith("'")
    for value in ("MCP-01", "/src/path.php", None, 41):
        assert safe_csv_cell(value) == value
    print("READINESS_CSV_FORMULA_INJECTION_SELF_TEST: PASS")

if __name__ == "__main__":
    self_test()
