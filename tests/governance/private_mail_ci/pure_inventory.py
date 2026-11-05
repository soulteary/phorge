"""Source-derived expectations for the trusted current native test checkout."""
from pathlib import Path
import re


def inventory(source):
    source = Path(source)
    text = (source / 'tests/governance/run.php').read_text()
    if text.count('$classes = array(') != 1:
        raise ValueError('native_class_inventory_refused')
    block = text.split('$classes = array(', 1)[1].split(');', 1)[0]
    classes = re.findall(r"'(Phorge[A-Za-z0-9_]+TestCase)'", block)
    remainder = re.sub(r"'(Phorge[A-Za-z0-9_]+TestCase)'", '', block)
    if not classes or len(classes) != len(set(classes)) or remainder.replace(',', '').strip():
        raise ValueError('native_class_inventory_refused')
    ids = set()
    for name in classes:
        paths = list((source / 'src').rglob(name + '.php'))
        if len(paths) != 1:
            raise ValueError('native_class_source_refused')
        methods = re.findall(r'public\s+function\s+(test[A-Za-z0-9_]+)\s*\(', paths[0].read_text())
        if not methods or len(methods) != len(set(methods)):
            raise ValueError('native_method_inventory_refused')
        ids.update(name + '::' + method for method in methods)
    return ids


def validate_pure(receipt, ids, expected_count):
    if (type(expected_count) is not int or expected_count != len(ids) or
            receipt.get('passed') is not True or receipt.get('runtime') != '8.5.12' or
            type(receipt.get('expected_methods')) is not int or
            receipt['expected_methods'] != expected_count):
        return False
    rows = receipt.get('results')
    return (type(rows) is list and len(rows) == len(ids) and
            all(type(row) is dict and type(row.get('id')) is str and row.get('result') == 'pass' for row in rows) and
            len({row['id'] for row in rows}) == len(ids) and {row['id'] for row in rows} == ids)
