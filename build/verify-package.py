#!/usr/bin/env python3
"""Verify the distributable, its update feed and bundled license together."""
from pathlib import Path
import hashlib
import re
import xml.etree.ElementTree as ET
import zipfile

root = Path(__file__).resolve().parent.parent
manifest = ET.parse(root / 'plg_system_spamtroll/spamtroll.xml').getroot()
assert manifest.find('files/folder[@plugin="spamtroll"]').text == 'services'
version = manifest.findtext('version')
archive = root / f'dist/plg_system_spamtroll-{version}.zip'
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
assert archive.with_suffix('.zip.sha256').read_text() == f'{checksum}  {archive.name}\n'
with zipfile.ZipFile(archive) as package:
    assert package.testzip() is None
    assert package.read('LICENSE') == (root / 'LICENSE').read_bytes()
    assert package.read('spamtroll.xml') == (root / 'plg_system_spamtroll/spamtroll.xml').read_bytes()
    for directory in ('services', 'src', 'sql', 'language'):
        for source in (root / 'plg_system_spamtroll' / directory).rglob('*'):
            if source.is_file():
                assert package.read(source.relative_to(root / 'plg_system_spamtroll').as_posix()) == source.read_bytes()
    for entry in ('vendor/autoload.php', 'vendor/spamtroll/php-sdk/src/Client.php',
                  'vendor/spamtroll/php-sdk/src/Http/HttpClientInterface.php',
                  'vendor/spamtroll/php-sdk/LICENSE', 'vendor/composer/LICENSE'):
        assert package.read(entry)
    for entry in package.namelist():
        assert not entry.startswith(('/', '../', '.git/', 'tests/')) and '/../' not in entry

assert manifest.findtext('updateservers/server') == 'https://github.com/spamtroll/spamtroll-joomla/releases/latest/download/updates.xml'
updates = ET.parse(root / 'dist/updates.xml').getroot().findall('update')
assert len(updates) == 2
for update, platform in zip(updates, (r'4\.4', r'5\.[0-9]+')):
    for field, expected in {'element': 'spamtroll', 'type': 'plugin', 'folder': 'system',
                            'client': '0', 'version': version, 'php_minimum': '8.2',
                            'sha256': checksum}.items():
        assert update.findtext(field) == expected, field
    assert update.find('targetplatform').attrib == {'name': 'joomla', 'version': platform}
    assert update.findtext('downloads/downloadurl') == f'https://github.com/spamtroll/spamtroll-joomla/releases/download/v{version}/{archive.name}'
    assert update.findtext('tags/tag') == 'stable'
    assert re.fullmatch(platform, '4.4' if platform == r'4\.4' else '5.4')
    assert not re.fullmatch(platform, '6.0')
print(f'Package, licenses, source bytes, update identities and SHA-256 verified: {archive.name}')
