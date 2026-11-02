from __future__ import annotations

import json
import queue
import threading
import time
import tkinter as tk
from pathlib import Path
from tkinter import filedialog, messagebox, ttk

from core import DEFAULT_SELECTORS, avito_url, export_bundles, image_url, item_link, product_from_jsonld, save_json, store_image

BASE = Path(__file__).resolve().parent
DATA = BASE / '.local'


class BrowserWorker(threading.Thread):
    def __init__(self, events):
        super().__init__(daemon=True)
        self.events = events
        self.tasks = queue.Queue()
        self.stop_event = threading.Event()
        self.context = None
        self.page = None

    def emit(self, kind, value):
        self.events.put((kind, value))

    def browser(self, playwright):
        if self.context is None:
            DATA.mkdir(exist_ok=True)
            self.context = playwright.chromium.launch_persistent_context(str(DATA / 'browser-profile'), headless=False, accept_downloads=False)
            self.page = self.context.pages[0] if self.context.pages else self.context.new_page()
        elif self.page.is_closed():
            self.page = self.context.new_page()
        return self.page

    def run(self):
        try:
            from playwright.sync_api import sync_playwright
            with sync_playwright() as p:
                while True:
                    action, data = self.tasks.get()
                    try:
                        if action == 'quit':
                            break
                        if action == 'login':
                            self.browser(p).goto('https://www.avito.ru/', wait_until='domcontentloaded', timeout=45000)
                            self.emit('log', 'Войдите вручную в открывшемся браузере. Затем нажмите «Собрать объявления».')
                        if action == 'collect':
                            self.collect(self.browser(p), data)
                        if action == 'logout':
                            if self.context:
                                self.context.close()
                            self.context = self.page = None
                            import shutil
                            shutil.rmtree(DATA / 'browser-profile', ignore_errors=False) if (DATA / 'browser-profile').exists() else None
                            self.emit('log', 'Локальная сессия удалена.')
                    except Exception as error:
                        self.emit('log', 'Ошибка: ' + str(error))
                    finally:
                        self.emit('idle', None)
                if self.context:
                    self.context.close()
        except Exception as error:
            self.emit('log', 'Не удалось запустить браузер: ' + str(error))
            self.emit('idle', None)

    def check_page(self, page):
        title = page.title().lower()
        if any(text in title for text in ('доступ ограничен', 'captcha', 'проверка браузера', 'подтвердите, что')):
            raise RuntimeError('Авито запросил проверку. Пройдите её в браузере и повторите сбор. Обход проверки не выполняется.')

    def collect(self, page, config):
        self.stop_event.clear()
        selectors = config['selectors']
        url = avito_url(config['url'])
        links = {}
        visited = set()
        for number in range(config['pages']):
            if self.stop_event.is_set():
                break
            if url in visited:
                break
            visited.add(url)
            page.goto(url, wait_until='domcontentloaded', timeout=45000)
            page.wait_for_timeout(config['delay'] * 1000)
            self.check_page(page)
            candidates = page.locator(selectors['links']).evaluate_all('(els) => els.map(el => el.href).filter(Boolean)')
            for href in candidates:
                found = item_link(href, page.url)
                if found:
                    links[found[0]] = found[1]
                if len(links) >= config['limit']:
                    break
            self.emit('log', f'Страница {number + 1}: найдено ссылок {len(links)}.')
            if len(links) >= config['limit']:
                break
            next_page = page.locator(selectors['next']).first
            if next_page.count() == 0:
                break
            href = next_page.get_attribute('href')
            if not href:
                self.emit('log', 'У кнопки следующей страницы нет href. Откройте следующую страницу вручную и запустите сбор с её адреса.')
                break
            from urllib.parse import urljoin
            url = avito_url(urljoin(page.url, href))
        if not links and not self.stop_event.is_set():
            raise RuntimeError('Ссылки не найдены. Проверьте вход, адрес страницы и CSS-селектор ссылок.')
        self.emit('log', 'Начинаю читать карточки. Повторно уже собранные ID пропускаются.')
        existing = {str(i['avito_id']) for i in config['items']}
        for index, (avito_id, url) in enumerate(links.items(), 1):
            if self.stop_event.is_set():
                break
            if avito_id in existing:
                continue
            try:
                page.goto(url, wait_until='domcontentloaded', timeout=45000)
                page.wait_for_timeout(config['delay'] * 1000)
                self.check_page(page)
                item = product_from_jsonld(page.locator('script[type="application/ld+json"]').all_text_contents())
                for key in ('title', 'description'):
                    if not item.get(key):
                        node = page.locator(selectors[key]).first
                        item[key] = node.inner_text().strip() if node.count() else ''
                if not item.get('title'):
                    raise RuntimeError('Название не найдено; карточка не добавлена.')
                if not item.get('price'):
                    node = page.locator(selectors['price']).first
                    item['price'] = node.get_attribute('content') if node.count() else ''
                refs = item.get('images', [])
                if not refs:
                    refs = page.locator(selectors['images']).evaluate_all('(els) => els.map(el => el.currentSrc || el.src).filter(Boolean)')
                photos = []
                for ref in list(dict.fromkeys(refs))[:30]:
                    if self.stop_event.is_set():
                        break
                    safe_url = image_url(ref, page.url)
                    response = self.context.request.get(safe_url, timeout=20000, max_redirects=0)
                    try:
                        if response.status != 200:
                            raise RuntimeError(f'Фото: HTTP {response.status}.')
                        if int(response.headers.get('content-length', '0')) > 20 * 1024 * 1024:
                            raise RuntimeError('Фото больше 20 МБ.')
                        photos.append(store_image(response.body(), DATA / 'images'))
                    finally:
                        response.dispose()
                if self.stop_event.is_set():
                    break
                item.update(avito_id=avito_id, url=url, images=photos)
                item.setdefault('category', '')
                item.setdefault('attributes', {})
                config['items'].append(item)
                existing.add(avito_id)
                save_json(DATA / 'items.json', config['items'])
                self.emit('item', item)
                self.emit('log', f'{index}/{len(links)}: {item["title"]}' + (' · Внимание: нет фото' if not photos else ''))
            except Exception as error:
                self.emit('log', f'Не загружено {avito_id}: {error}')
                if 'проверку' in str(error):
                    break
        self.emit('log', 'Сбор остановлен.' if self.stop_event.is_set() else 'Сбор завершён. Проверьте позиции и сохраните ZIP.')


class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title('Авито · GG — браузерный помощник')
        self.geometry('1060x780')
        self.minsize(850, 650)
        self.events = queue.Queue()
        self.worker = BrowserWorker(self.events)
        self.worker.start()
        self.items = self.load('items.json', [])
        config = self.load('settings.json', {})
        self.url = tk.StringVar(value=config.get('url', ''))
        self.pages = tk.IntVar(value=config.get('pages', 10))
        self.limit = tk.IntVar(value=config.get('limit', 500))
        self.delay = tk.DoubleVar(value=config.get('delay', 3))
        self.selectors = {k: tk.StringVar(value=config.get('selectors', {}).get(k, v)) for k, v in DEFAULT_SELECTORS.items()}
        self.busy = False
        self.build()
        self.populate()
        self.after(150, self.poll)
        self.protocol('WM_DELETE_WINDOW', self.close)

    def load(self, name, fallback):
        try:
            return json.loads((DATA / name).read_text(encoding='utf-8'))
        except (OSError, ValueError):
            return fallback

    def build(self):
        style = ttk.Style()
        style.theme_use('clam')
        style.configure('TButton', padding=7)
        style.configure('Title.TLabel', font=('Segoe UI', 20, 'bold'), foreground='#65409b')
        frame = ttk.Frame(self, padding=18)
        frame.pack(fill='both', expand=True)
        ttk.Label(frame, text='Авито → ваш магазин', style='Title.TLabel').pack(anchor='w')
        ttk.Label(frame, text='Вход в браузере → сбор своих объявлений → проверка → ZIP для WordPress', padding=(0, 8)).pack(anchor='w')
        tabs = ttk.Notebook(frame)
        tabs.pack(fill='both', expand=True)
        source, products, settings = (ttk.Frame(tabs, padding=14) for _ in range(3))
        tabs.add(source, text='1. Вход и сбор')
        tabs.add(products, text='2. Проверка и ZIP')
        tabs.add(settings, text='3. Настройки разметки')
        ttk.Label(source, text='Ссылка на страницу ваших объявлений / магазин Авито').pack(anchor='w')
        ttk.Entry(source, textvariable=self.url).pack(fill='x', pady=8)
        options = ttk.Frame(source)
        options.pack(fill='x', pady=10)
        for label, var in [('Страниц', self.pages), ('Макс. объявлений', self.limit), ('Пауза, сек.', self.delay)]:
            ttk.Label(options, text=label).pack(side='left', padx=(0, 6))
            ttk.Entry(options, textvariable=var, width=8).pack(side='left', padx=(0, 18))
        buttons = ttk.Frame(source)
        buttons.pack(fill='x', pady=12)
        self.login = ttk.Button(buttons, text='Открыть Авито / войти', command=lambda: self.task('login', None))
        self.login.pack(side='left', padx=(0, 10))
        self.collect_button = ttk.Button(buttons, text='Собрать объявления', command=self.collect)
        self.collect_button.pack(side='left', padx=(0, 10))
        ttk.Button(buttons, text='Остановить', command=self.worker.stop_event.set).pack(side='left')
        self.logout = ttk.Button(source, text='Удалить локальную сессию / выйти', command=self.forget)
        self.logout.pack(anchor='w', pady=8)
        ttk.Label(source, text='Войдите и пройдите подтверждение вручную в окне Авито.\nПосле входа вернитесь сюда и запустите сбор. Пароль приложение не сохраняет.\nСессия браузера хранится локально. Сбор зависит от текущей разметки Авито;\nпри капче потребуется ваше действие. Уже собранные карточки сохраняются после каждой позиции.', wraplength=900).pack(anchor='w', pady=15)
        self.log = tk.Text(source, height=12, state='disabled', wrap='word', bg='#f4f0fa', relief='flat')
        self.log.pack(fill='both', expand=True)
        self.tree = ttk.Treeview(products, columns=('title', 'price', 'photos'), show='headings', selectmode='extended')
        for key, title, width in [('title', 'Название', 570), ('price', 'Цена', 100), ('photos', 'Фото', 60)]:
            self.tree.heading(key, text=title)
            self.tree.column(key, width=width)
        self.tree.pack(fill='both', expand=True)
        self.tree.bind('<Double-1>', lambda _: self.edit())
        row = ttk.Frame(products)
        row.pack(fill='x', pady=12)
        self.edit_button = ttk.Button(row, text='Редактировать выбранное', command=self.edit)
        self.edit_button.pack(side='left', padx=5)
        self.export_button = ttk.Button(row, text='Сохранить ZIP', command=self.export)
        self.export_button.pack(side='left', padx=5)
        self.delete_button = ttk.Button(row, text='Убрать выбранные', command=self.remove)
        self.delete_button.pack(side='left', padx=5)
        ttk.Label(products, text='ZIP включает выбранные позиции, а если ничего не выбрано — все.\nБольшая выгрузка делится на архивы примерно по 100 МБ. Финальная проверка и публикация — в WordPress.').pack(anchor='w')
        ttk.Label(settings, text='CSS-селекторы используются, когда в странице нет подходящих структурированных данных.\nМеняйте их только если сбор перестал находить нужные элементы.').pack(anchor='w', pady=10)
        for key, var in self.selectors.items():
            ttk.Label(settings, text=key).pack(anchor='w')
            ttk.Entry(settings, textvariable=var).pack(fill='x', pady=(2, 10))
        ttk.Button(settings, text='Восстановить селекторы', command=lambda: [v.set(DEFAULT_SELECTORS[k]) for k, v in self.selectors.items()]).pack(anchor='w')
        self.status = tk.StringVar(value=f'Готово · сохранённых позиций: {len(self.items)}')
        ttk.Label(frame, textvariable=self.status, padding=(0, 10)).pack(anchor='w')

    def config(self):
        config = dict(url=avito_url(self.url.get()), pages=self.pages.get(), limit=self.limit.get(), delay=self.delay.get(), selectors={k: v.get() for k, v in self.selectors.items()})
        if not 1 <= config['pages'] <= 1000 or not 1 <= config['limit'] <= 5000 or not 1 <= config['delay'] <= 60:
            raise ValueError('Страниц: 1–1000, объявлений: 1–5000, пауза: 1–60 секунд.')
        save_json(DATA / 'settings.json', config)
        return config

    def collect(self):
        if self.busy:
            return
        try:
            config = self.config()
            config['items'] = list(self.items)
            self.task('collect', config)
        except Exception as error:
            messagebox.showerror('Проверьте настройки', str(error))

    def task(self, name, data):
        if self.busy:
            return
        if not self.worker.is_alive():
            messagebox.showerror('Браузер недоступен', 'Перезапустите помощник. Проверьте установку Playwright и Chromium.')
            return
        self.set_busy(True)
        self.worker.tasks.put((name, data))

    def set_busy(self, busy):
        self.busy = busy
        for button in (self.login, self.collect_button, self.logout, self.edit_button, self.export_button, self.delete_button):
            button.configure(state='disabled' if busy else 'normal')
        self.status.set('Выполняется операция…' if busy else f'Готово · позиций: {len(self.items)}')

    def forget(self):
        if messagebox.askyesno('Выйти', 'Удалить сессию Авито на этом компьютере? Собранные позиции сохранятся.'):
            self.task('logout', None)

    def populate(self):
        self.tree.delete(*self.tree.get_children())
        for item in self.items:
            self.tree.insert('', 'end', iid=item['avito_id'], values=(item['title'], item.get('price', ''), len(item.get('images', []))))

    def poll(self):
        try:
            while True:
                kind, value = self.events.get_nowait()
                if kind == 'log':
                    self.log.configure(state='normal')
                    self.log.insert('end', time.strftime('%H:%M:%S') + ' · ' + value + '\n')
                    self.log.see('end')
                    self.log.configure(state='disabled')
                elif kind == 'item':
                    self.items.append(value)
                    self.populate()
                elif kind == 'idle':
                    self.set_busy(False)
        except queue.Empty:
            pass
        self.after(150, self.poll)

    def edit(self):
        if self.busy or not self.tree.selection():
            return
        item = next(i for i in self.items if i['avito_id'] == self.tree.selection()[0])
        window = tk.Toplevel(self)
        window.title('Редактирование объявления')
        window.geometry('650x650')
        window.transient(self)
        window.grab_set()
        values = {}
        for key, label in [('title', 'Название'), ('price', 'Цена, ₽'), ('category', 'Категория')]:
            ttk.Label(window, text=label).pack(anchor='w', padx=16, pady=(10, 0))
            var = tk.StringVar(value=item.get(key, ''))
            values[key] = var
            ttk.Entry(window, textvariable=var).pack(fill='x', padx=16, pady=5)
        ttk.Label(window, text='Описание').pack(anchor='w', padx=16)
        description = tk.Text(window, height=9, wrap='word')
        description.pack(fill='both', expand=True, padx=16, pady=5)
        description.insert('1.0', item.get('description', ''))
        ttk.Label(window, text='Характеристики (JSON)').pack(anchor='w', padx=16)
        attrs = tk.Text(window, height=5)
        attrs.pack(fill='x', padx=16, pady=5)
        attrs.insert('1.0', json.dumps(item.get('attributes', {}), ensure_ascii=False, indent=2))

        def save():
            try:
                import math
                price = values['price'].get().replace(',', '.').replace(' ', '')
                if price and (not math.isfinite(float(price)) or float(price) < 0):
                    raise ValueError('Неверная цена.')
                if not values['title'].get().strip():
                    raise ValueError('Укажите название.')
                attributes = json.loads(attrs.get('1.0', 'end'))
                if not isinstance(attributes, dict) or any(not isinstance(v, (str, int, float, bool)) for v in attributes.values()):
                    raise ValueError('Характеристики должны быть объектом с простыми значениями.')
                item.update({k: v.get() for k, v in values.items()})
                item.update(price=price, description=description.get('1.0', 'end').strip(), attributes=attributes)
                save_json(DATA / 'items.json', self.items)
                self.populate()
                window.destroy()
            except Exception as error:
                messagebox.showerror('Не удалось сохранить', str(error), parent=window)
        ttk.Button(window, text='Сохранить', command=save).pack(pady=10)

    def export(self):
        if self.busy:
            return
        chosen = set(self.tree.selection())
        items = [i for i in self.items if not chosen or i['avito_id'] in chosen]
        output = filedialog.asksaveasfilename(defaultextension='.zip', initialfile='avito-products.zip', filetypes=[('ZIP', '*.zip')])
        if not output:
            return
        try:
            files = export_bundles(items, DATA / 'images', Path(output))
            messagebox.showinfo('Готово', 'Загрузите эти файлы в WordPress → Авито · GG:\n' + '\n'.join(str(f) for f in files))
        except Exception as error:
            messagebox.showerror('Ошибка экспорта', str(error))

    def remove(self):
        if self.busy:
            return
        selected = set(self.tree.selection())
        if selected and messagebox.askyesno('Убрать позиции', 'Удалить выбранные позиции из локального списка? Объявления на Авито сохранятся.'):
            self.items = [i for i in self.items if i['avito_id'] not in selected]
            save_json(DATA / 'items.json', self.items)
            self.populate()

    def close(self):
        self.worker.stop_event.set()
        self.worker.tasks.put(('quit', None))
        self.destroy()


if __name__ == '__main__':
    App().mainloop()
