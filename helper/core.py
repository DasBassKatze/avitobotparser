from __future__ import annotations

import hashlib
import json
import re
import zipfile
from pathlib import Path
from urllib.parse import urljoin, urlsplit, urlunsplit

MAX_IMAGE = 20 * 1024 * 1024
MAX_BUNDLE = 100 * 1024 * 1024
DEFAULT_SELECTORS = {
    'links': 'a[data-marker="item-title"]',
    'next': 'a[data-marker="pagination-button/nextPage"]',
    'title': '[data-marker="item-view/title-info"]',
    'description': '[data-marker="item-view/item-description"]',
    'price': '[itemprop="price"]',
    'images': '[data-marker="image-frame/image-wrapper"] img',
}


def avito_url(value: str) -> str:
    parts = urlsplit(value.strip())
    host = (parts.hostname or '').lower()
    if parts.scheme != 'https' or host not in ('avito.ru', 'www.avito.ru') or parts.username or parts.password or parts.port not in (None, 443):
        raise ValueError('Нужна HTTPS-ссылка на avito.ru или www.avito.ru.')
    return urlunsplit((parts.scheme, parts.netloc, parts.path or '/', parts.query, ''))


def item_link(value: str, base: str) -> tuple[str, str] | None:
    try:
        url = avito_url(urljoin(base, value))
    except (ValueError, TypeError):
        return None
    parts = urlsplit(url)
    match = re.search(r'_(\d{6,})/?$', parts.path)
    return (match.group(1), urlunsplit((parts.scheme, parts.netloc, parts.path, '', ''))) if match else None


def image_extension(data: bytes) -> str:
    if len(data) > MAX_IMAGE:
        raise ValueError('Фото больше 20 МБ.')
    if data.startswith(b'\xff\xd8\xff'):
        return 'jpg'
    if data.startswith(b'\x89PNG\r\n\x1a\n'):
        return 'png'
    if data[:4] == b'RIFF' and data[8:12] == b'WEBP':
        return 'webp'
    raise ValueError('Фото не является JPEG, PNG или WebP.')


def image_url(value: str, base: str) -> str:
    url = urljoin(base, value)
    parts = urlsplit(url)
    host = (parts.hostname or '').lower()
    if parts.scheme != 'https' or parts.username or parts.password or parts.port not in (None, 443) or not any(host == h or host.endswith('.' + h) for h in ('avito.ru', 'avito.st')):
        raise ValueError('Адрес фото не принадлежит HTTPS-доменам avito.ru / avito.st.')
    return url


def product_from_jsonld(documents: list[str]) -> dict:
    def walk(value):
        if isinstance(value, list):
            for entry in value:
                yield from walk(entry)
        elif isinstance(value, dict):
            types = value.get('@type', [])
            if isinstance(types, str):
                types = [types]
            if 'Product' in types:
                yield value
            for key in ('@graph', 'mainEntity', 'itemListElement', 'item'):
                if key in value:
                    yield from walk(value[key])
    for text in documents:
        try:
            products = list(walk(json.loads(text)))
        except (ValueError, TypeError):
            continue
        if products:
            p = products[0]
            offer = p.get('offers', {})
            if isinstance(offer, list):
                offer = offer[0] if offer else {}
            if not isinstance(offer, dict):
                offer = {}
            images = p.get('image', [])
            if not isinstance(images, list):
                images = [images]
            images = [i.get('url', i.get('contentUrl', '')) if isinstance(i, dict) else i for i in images]
            return {'title': str(p.get('name', '')), 'description': str(p.get('description', '')),
                    'price': str(offer.get('price', '')), 'images': [i for i in images if isinstance(i, str) and i],
                    'category': str(p.get('category', '')), 'attributes': {}}
    return {}


def save_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
    tmp.replace(path)


def export_bundles(items: list[dict], image_dir: Path, output: Path, max_bytes=MAX_BUNDLE) -> list[Path]:
    if not items:
        raise ValueError('Сначала соберите объявления.')
    groups, current, used, size = [], [], set(), 0
    for source in items:
        item = {k: v for k, v in source.items() if not k.startswith('_')}
        refs = item.get('images', [])
        for ref in refs:
            if not re.fullmatch(r'images/[a-zA-Z0-9_-]+\.(jpg|png|webp)', ref):
                raise ValueError('Недопустимое имя фото.')
            if not (image_dir / Path(ref).name).is_file():
                raise ValueError(f'Фото отсутствует: {ref}')
        total_item_size = sum((image_dir / Path(ref).name).stat().st_size for ref in set(refs))
        extra = sum((image_dir / Path(ref).name).stat().st_size for ref in set(refs) - used)
        if total_item_size > max_bytes:
            raise ValueError(f'Слишком много фото у объявления {item["avito_id"]}.')
        if current and (size + extra > max_bytes or len(current) >= 5000):
            groups.append(current)
            current, used, size = [], set(), 0
            extra = sum((image_dir / Path(ref).name).stat().st_size for ref in set(refs))
        current.append(item)
        used.update(refs)
        size += extra
    if current:
        groups.append(current)
    result = []
    for number, group in enumerate(groups, 1):
        target = output if len(groups) == 1 else output.with_name(f'{output.stem}-{number:03d}.zip')
        temp = target.with_suffix('.zip.tmp')
        try:
            with zipfile.ZipFile(temp, 'w', zipfile.ZIP_DEFLATED) as z:
                z.writestr('products.json', json.dumps({'version': 1, 'items': group}, ensure_ascii=False, indent=2))
                refs = {ref for item in group for ref in item.get('images', [])}
                for ref in sorted(refs):
                    z.write(image_dir / Path(ref).name, ref)
            temp.replace(target)
        finally:
            temp.unlink(missing_ok=True)
        result.append(target)
    return result


def store_image(data: bytes, image_dir: Path) -> str:
    extension = image_extension(data)
    name = hashlib.sha256(data).hexdigest() + '.' + extension
    image_dir.mkdir(parents=True, exist_ok=True)
    (image_dir / name).write_bytes(data)
    return 'images/' + name
