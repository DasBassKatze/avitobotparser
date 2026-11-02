import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'helper'))
from core import avito_url, item_link, image_url, image_extension, product_from_jsonld, export_bundles, store_image


class CoreTests(unittest.TestCase):
    def test_login_and_item_urls_stay_on_avito(self):
        for url in ['http://avito.ru/a', 'https://avito.ru.evil.com/a', 'https://avito.ru@evil.com/a', 'file:///etc/passwd', 'https://evil.com', 'https://avito.ru:8443/a']:
            with self.subTest(url=url), self.assertRaises(ValueError):
                avito_url(url)
        self.assertEqual(item_link('/moskva/mebel/kreslo_12345678?x=1', 'https://www.avito.ru/'), ('12345678', 'https://www.avito.ru/moskva/mebel/kreslo_12345678'))
        self.assertIsNone(item_link('/profile/123', 'https://www.avito.ru/'))
        self.assertIsNone(item_link('https://evil.com/a_12345678', 'https://www.avito.ru/'))

    def test_image_host_and_signature(self):
        self.assertEqual(image_url('https://img.avito.st/photo.jpg', ''), 'https://img.avito.st/photo.jpg')
        for url in ['https://avito.st.evil.com/x', 'https://127.0.0.1/x', 'http://img.avito.st/x']:
            with self.assertRaises(ValueError):
                image_url(url, '')
        with self.assertRaises(ValueError):
            image_extension(b'<script>alert(1)</script>')

    def test_jsonld_graph_and_image_objects(self):
        data = {'@graph': [{'@type': 'Product', 'name': 'Кресло', 'description': 'Описание', 'image': {'url': 'https://img.avito.st/a.jpg'}, 'offers': [{'price': '1200'}]}]}
        item = product_from_jsonld(['not json', json.dumps(data)])
        self.assertEqual(item['title'], 'Кресло')
        self.assertEqual(item['price'], '1200')
        self.assertEqual(item['images'], ['https://img.avito.st/a.jpg'])

    def test_portable_bundle_and_split(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            image_dir = base / 'images'
            ref1 = store_image(b'\xff\xd8\xff' + b'a' * 20, image_dir)
            ref2 = store_image(b'\xff\xd8\xff' + b'b' * 20, image_dir)
            items = [{'avito_id': '1', 'title': 'Первое', 'images': [ref1]}, {'avito_id': '2', 'title': 'Второе', 'images': [ref2]}]
            files = export_bundles(items, image_dir, base / 'products.zip', max_bytes=30)
            self.assertEqual(len(files), 2)
            for path in files:
                with zipfile.ZipFile(path) as archive:
                    manifest = json.loads(archive.read('products.json'))
                    self.assertEqual(manifest['version'], 1)
                    self.assertEqual(len(manifest['items']), 1)
                    self.assertIn(manifest['items'][0]['images'][0], archive.namelist())
            self.assertEqual(items[0]['images'], [ref1])
            with self.assertRaises(ValueError):
                export_bundles([{'avito_id': '3', 'images': ['../secret.jpg']}], image_dir, base / 'bad.zip')


if __name__ == '__main__':
    unittest.main()
