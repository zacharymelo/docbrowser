#!/usr/bin/env python3
"""
Build script for the Document Browser module.
Usage: python3 bin/build.py <version>
  e.g. python3 bin/build.py 0.1.1

1. Updates the version string in the module descriptor (before zipping, never after).
2. Builds bin/module_docbrowser-<version>.zip with `docbrowser/` as the top-level folder,
   and checks the version inside the zip.
"""

import re
import sys
import zipfile
import pathlib

ROOT = pathlib.Path(__file__).parent.parent
MODULE = ROOT / 'module'
BIN = ROOT / 'bin'

DESCRIPTOR = MODULE / 'core' / 'modules' / 'modDocBrowser.class.php'
VERSION_PATTERN = (r"(\$this->version\s*=\s*')[^']+(')", r'\g<1>{version}\g<2>')
EXCLUDE_NAMES = {'.DS_Store'}


def set_version(version):
    pattern, replacement = VERSION_PATTERN
    text = DESCRIPTOR.read_text()
    new_text = re.sub(pattern, replacement.format(version=version), text)
    if new_text == text:
        print(f'  WARNING: no version string changed in {DESCRIPTOR.name}')
    else:
        DESCRIPTOR.write_text(new_text)
        print(f'  updated {DESCRIPTOR.name}')
    return f"'{version}'" in DESCRIPTOR.read_text()


def build_zip(version):
    out = BIN / f'module_docbrowser-{version}.zip'
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
        for f in sorted(MODULE.rglob('*')):
            if f.is_file() and f.name not in EXCLUDE_NAMES:
                zf.write(f, 'docbrowser/' + str(f.relative_to(MODULE)))
    with zipfile.ZipFile(out) as zf:
        name = 'docbrowser/' + str(DESCRIPTOR.relative_to(MODULE))
        if f"'{version}'" not in zf.read(name).decode():
            print(f'  ERROR: {version} not in zip entry {name}')
            return None
    print(f'  created {out.name} ({out.stat().st_size // 1024} KB)')
    return out


def main():
    if len(sys.argv) != 2:
        print('Usage: python3 bin/build.py <version>')
        sys.exit(1)
    version = sys.argv[1]
    print(f'\n=== Building v{version} ===\n')

    print('1. Updating the version string...')
    if not set_version(version):
        print('\nABORTED: version string not set.')
        sys.exit(1)

    print('\n2. Building zip...')
    out = build_zip(version)
    if not out:
        print('\nABORTED: zip check failed.')
        sys.exit(1)

    print('\nDone. Next steps:')
    print(f'  git add -A && git commit -m "v{version}: ..."')
    print(f'  gh release create v{version} {out} --title "v{version}" --notes "..."')


if __name__ == '__main__':
    main()
