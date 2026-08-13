#!/usr/bin/env python3
"""Apply translations into a .po file in place, surgically.

Usage: po-apply.py <po_file> <json_map>
  json_map: { "<msgid value>": "<msgstr value>", ... }

Only updates entries that are currently untranslated (empty msgstr) or fuzzy —
never overwrites an existing, confirmed translation. Preserves all comments
(including `#. translators:`), `#:` locations, order, and formatting. Clears the
fuzzy flag on entries it fills. Reports counts.

This is the correct apply step for the translation pipeline. Do NOT use
`msgcat --use-first` for this — it replaces the whole entry and drops the
translator comments.
"""
import sys, json, re


def unescape(s):
    return (s.replace('\\n', '\n').replace('\\t', '\t')
             .replace('\\"', '"').replace('\\\\', '\\'))


def escape(s):
    return (s.replace('\\', '\\\\').replace('"', '\\"')
             .replace('\n', '\\n').replace('\t', '\\t'))


def read_quoted(lines, i):
    """Concatenate a keyword's quoted-string value starting at line i."""
    vals = []
    m = re.match(r'^\w+(?:\[\d+\])?\s+"(.*)"\s*$', lines[i])
    if m:
        vals.append(m.group(1))
    j = i + 1
    while j < len(lines) and re.match(r'^\s*"(.*)"\s*$', lines[j]):
        vals.append(re.match(r'^\s*"(.*)"\s*$', lines[j]).group(1))
        j += 1
    return unescape(''.join(vals)), j


def main():
    po_path, map_path = sys.argv[1], sys.argv[2]
    tmap = json.load(open(map_path, encoding='utf-8'))
    # Ignore empty values so a partially-filled map never blanks out an entry.
    tmap = {k: v for k, v in tmap.items() if v not in (None, '')}
    text = open(po_path, encoding='utf-8').read()
    blocks = text.split('\n\n')
    applied = skipped = 0
    not_found = set(tmap.keys())

    out = []
    for b in blocks:
        # Plural entries use msgstr[0]/msgstr[1]/… — this single-form apply would
        # corrupt them, so never touch them. Handle plurals separately.
        if re.search(r'^msgid_plural ', b, re.M):
            out.append(b)
            continue
        lines = b.split('\n')
        msgid_val = msgid_i = msgstr_i = None
        is_fuzzy = False
        for idx, l in enumerate(lines):
            if l.startswith('#,') and 'fuzzy' in l:
                is_fuzzy = True
            if l.startswith('msgid ') and msgid_i is None:
                msgid_val, _ = read_quoted(lines, idx)
                msgid_i = idx
            if (l.startswith('msgstr ') or l.startswith('msgstr[')) and msgstr_i is None:
                msgstr_i = idx
        if msgid_val is not None and msgid_val in tmap and msgstr_i is not None:
            cur, end = read_quoted(lines, msgstr_i)
            if cur == '' or is_fuzzy:
                lines = lines[:msgstr_i] + ['msgstr "%s"' % escape(tmap[msgid_val])] + lines[end:]
                for idx, l in enumerate(lines):
                    if l and l.startswith('#,') and 'fuzzy' in l:
                        flags = [f.strip() for f in l[2:].split(',')
                                 if f.strip() and f.strip() != 'fuzzy']
                        lines[idx] = '#, ' + ', '.join(flags) if flags else None
                lines = [l for l in lines if l is not None]
                applied += 1
            else:
                skipped += 1
            not_found.discard(msgid_val)
        out.append('\n'.join(lines))

    open(po_path, 'w', encoding='utf-8').write('\n\n'.join(out))
    print(f"applied={applied} skipped_already_translated={skipped} "
          f"not_found_in_po={len(not_found)}")
    for k in list(not_found)[:10]:
        print("  NOT FOUND:", k[:70])


if __name__ == '__main__':
    main()
