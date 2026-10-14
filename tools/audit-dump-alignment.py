"""Read-only structural scan; candidate differences require source verification."""
import re, json, pathlib, hashlib
root = pathlib.Path(__file__).resolve().parent.parent
out = root / 'docs' / 'schema-audit-2026-10-14'
out.mkdir(exist_ok=True)
def definitions(text):
    found = []
    for m in re.finditer(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?((?:sms2_|crad_)[a-zA-Z0-9_]+)`?\s*\((.*?)\)\s*ENGINE', text, re.I|re.S):
        cols = dict(re.findall(r'^\s*`([a-zA-Z0-9_]+)`\s+([^\r\n]+)', m[2], re.M))
        if not cols:
            cols = dict(re.findall(r'^\s*([a-zA-Z0-9_]+)\s+((?:INT|BIGINT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT|LONGTEXT|MEDIUMTEXT|DATETIME|TIMESTAMP|DATE|TIME|DECIMAL|ENUM|MEDIUMBLOB|BLOB|JSON|FLOAT|DOUBLE)\b[^\r\n]*)', m[2], re.I|re.M))
        found.append((m[1], cols, text[:m.start()].count('\n')+1))
    return found
paths = ['database/sms2_db.sql', 'modules/crad/database/crad_db.sql', 'sms2_db (1).sql']
inventory = {}
for p in paths:
    raw = (root/p).read_bytes()
    txt = raw.decode('utf-8-sig', errors='replace')
    inventory[p] = {'sha256': hashlib.sha256(raw).hexdigest(), 'definitions': definitions(txt)}
dump_tables = {}
duplicates = []
for p in paths[:2]:
    for table, cols, line in inventory[p]['definitions']:
        if table in dump_tables:
            previous = dump_tables[table]
            duplicates.append({'table':table, 'first':previous['source'], 'later':f'{p}:{line}', 'later_only_columns':sorted(set(cols)-set(previous['columns']))})
        else:
            dump_tables[table] = {'source':f'{p}:{line}', 'columns':cols}
refs = {}
runtime = {}
for file in root.rglob('*.php'):
    rel = file.relative_to(root).as_posix()
    if any(part.startswith('.') for part in file.relative_to(root).parts):
        continue
    if rel.startswith(('tests/', 'tools/', 'database/', 'PHPMailer/', 'modules/crad/database/')):
        continue
    txt = file.read_text(encoding='utf-8-sig',errors='replace')
    for m in re.finditer(r"const\s+\w*TABLE\w*\s*=\s*['\"]((?:sms2_|crad_)[a-zA-Z0-9_]+)['\"]",txt):
        refs.setdefault(m[1],set()).add(f'{rel}:{txt[:m.start()].count(chr(10))+1}')
    for m in re.finditer(r'\b(?:FROM|JOIN|INTO|UPDATE|TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?((?:sms2_|crad_)[a-zA-Z0-9_]+)',txt,re.I):
        refs.setdefault(m[1],set()).add(f'{rel}:{txt[:m.start()].count(chr(10))+1}')
    for table, cols, line in definitions(txt):
        runtime.setdefault(table,[]).append({'source':f'{rel}:{line}', 'columns':cols})
missing = [{'table':t,'references':sorted(v),'runtime_definitions':runtime.get(t,[])} for t,v in sorted(refs.items()) if t not in dump_tables]
gaps = []
for table, defs in runtime.items():
    if table in dump_tables:
        for d in defs:
            extra = sorted(set(d['columns'])-set(dump_tables[table]['columns']))
            if extra: gaps.append({'table':table,'source':d['source'],'columns':extra})
result={'dumps':inventory,'duplicate_create_definitions':duplicates,'first_create_columns':dump_tables,'referenced_tables_absent_from_create_definitions':missing,'runtime_create_column_candidates':gaps}
diagnostic = out/'import-diagnostics.json'
if diagnostic.exists():
    imported = json.loads(diagnostic.read_text())['runs']['combined']['columns']
    effective = {}
    for c in imported:
        effective.setdefault(c['TABLE_NAME'],set()).add(c['COLUMN_NAME'])
    candidates = []
    for file in root.rglob('*.php'):
        rel = file.relative_to(root).as_posix()
        if any(p.startswith('.') for p in file.relative_to(root).parts) or rel.startswith(('tests/','tools/','database/','PHPMailer/','modules/crad/database/')): continue
        txt = file.read_text(encoding='utf-8-sig',errors='replace')
        for m in re.finditer(r'INSERT\s+(?:IGNORE\s+)?INTO\s+`?((?:sms2_|crad_)\w+)`?\s*\(([^)]+)\)',txt,re.I):
            if m[1] not in effective: continue
            columns = [c.strip().strip('`') for c in m[2].split(',')]
            extra = [c for c in columns if re.fullmatch(r'\w+',c) and c not in effective[m[1]]]
            if extra: candidates.append({'kind':'INSERT','table':m[1],'columns':extra,'source':f'{rel}:{txt[:m.start()].count(chr(10))+1}'})
        aliases = {}
        for m in re.finditer(r'(?:FROM|JOIN)\s+`?((?:sms2_|crad_)\w+)`?\s+(?:AS\s+)?([a-z][a-z0-9_]*)\b',txt,re.I):
            if m[2].upper() not in {'WHERE','ON','SET','ORDER','LEFT','INNER','GROUP','LIMIT','AND','UNION','FOR'}:
                aliases.setdefault(m[2],set()).add(m[1])
        for alias,tables in aliases.items():
            if len(tables)!=1: continue
            table = next(iter(tables))
            if table not in effective: continue
            for m in re.finditer(r'\b'+re.escape(alias)+r'\.([a-zA-Z_]\w*)',txt):
                if m[1] not in effective[table]: candidates.append({'kind':'qualified alias candidate','table':table,'columns':[m[1]],'source':f'{rel}:{txt[:m.start()].count(chr(10))+1}'})
    result['effective_import_column_candidates']=candidates
    root_tables = {t:cols for t,cols,_ in inventory[paths[2]]['definitions']}
    result['root_snapshot_comparison']={'only_in_root':sorted(set(root_tables)-set(effective)), 'only_in_canonical_import':sorted(set(effective)-set(root_tables)), 'different_columns':[{'table':t,'root_only':sorted(set(root_tables[t])-effective[t]),'canonical_only':sorted(effective[t]-set(root_tables[t]))} for t in sorted(set(root_tables)&set(effective)) if set(root_tables[t])!=effective[t]]}
(out/'structural-scan.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps({'dump_table_definitions':{p:len(d['definitions']) for p,d in inventory.items()},'duplicate_create_definitions':duplicates,'missing_tables':[{'table':d['table'],'references':d['references'][:3]} for d in missing],'runtime_column_candidates':gaps},indent=2))
