#!/usr/bin/env python3
"""Emit the CORE strings that still need translation in a given locale .po.

Usage: po-core-worklist.py <locale.po> <coreonly.pot> <out.po>

Writes a .po fragment containing every entry that (a) belongs to the core
plugin (present in coreonly.pot) and (b) is untranslated or fuzzy in the locale,
with its `#. translators:` comment intact and an empty msgstr — ready to
translate. Prints the count.
"""
import sys, re, json


def unescape(s):
    return (s.replace('\\n', '\n').replace('\\t', '\t')
             .replace('\\"', '"').replace('\\\\', '\\'))


def msgid_value(block):
    m = re.search(r'^msgid ((?:".*"\s*)+)', block, re.M)
    if not m:
        return ''
    parts = re.findall(r'"(.*)"', m.group(1))
    return unescape(''.join(parts))


def parse(path):
    return re.split(r'\n\n+', open(path, encoding='utf-8').read().strip())


def key(block):
    mc = re.search(r'^msgctxt ((?:".*"\s*)+)', block, re.M)
    mi = re.search(r'^msgid ((?:".*"\s*)+)', block, re.M)
    if not mi:
        return None
    return ((mc.group(1).strip() if mc else ''), mi.group(1).strip())


def main():
    locale_po, corepot, out = sys.argv[1], sys.argv[2], sys.argv[3]
    core = {}
    for b in parse(corepot):
        k = key(b)
        if k and k[1] != '""':
            core[k] = b
    need = []
    for b in parse(locale_po):
        k = key(b)
        if not k or k[1] == '""' or k not in core:
            continue
        # Skip plural entries — the single-form apply can't represent them.
        # (Rare; handle plurals manually if any need translating.)
        if re.search(r'^msgid_plural ', b, re.M):
            continue
        fuzzy = bool(re.search(r'^#, .*fuzzy', b, re.M))
        mstr = re.search(r'^msgstr "(.*)"', b, re.M)
        untrans = (bool(mstr) and mstr.group(1) == ''
                   and not re.search(r'^msgstr\[', b, re.M)
                   and not re.search(r'\nmsgstr ""\n"', b))
        if fuzzy or untrans:
            need.append(k)
    skeleton = {}
    with open(out, 'w', encoding='utf-8') as f:
        f.write('msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n')
        for k in need:
            b = re.sub(r'\nmsgstr .*(\n".*")*', '\nmsgstr ""', core[k])
            f.write(b + '\n\n')
            skeleton[msgid_value(core[k])] = ""
    json.dump(skeleton, open(out + '.json', 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f"core strings needing translation: {len(need)} "
          f"(fragment: {out}, skeleton: {out}.json)")


if __name__ == '__main__':
    main()
