from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parents[1]
output = root / 'dist'
output.mkdir(exist_ok=True)
with ZipFile(output / 'avito-gg.zip', 'w', ZIP_DEFLATED) as archive:
    for file in sorted((root / 'plugin' / 'avito-gg').rglob('*')):
        if file.is_file():
            archive.write(file, file.relative_to(root / 'plugin'))
print(output / 'avito-gg.zip')
