(() => {
'use strict';
const root = document.getElementById('gg-avito');
if (!root) return;
const fields = {title:'Название',description:'Описание',price:'Цена',category:'Категория',attributes:'Характеристики',images:'Фотографии'};
const state = {tab:'items', page:1, search:'', filter:'', selected:new Map(), data:null, list:{items:[],total:0,pages:0}, busy:false, stop:false};
const esc = x => String(x ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const message = (text, error=false) => { const node=root.querySelector('#gg-message'); node.textContent=text; node.className=error?'gg-message error':'gg-message'; };
async function api(path, method='GET', body) {
 const options={method,headers:{'X-WP-Nonce':GG_AVITO.nonce},credentials:'same-origin'};
 if(body instanceof FormData) options.body=body;
 else if(body!==undefined) {options.headers['Content-Type']='application/json';options.body=JSON.stringify(body);}
 const r=await fetch(GG_AVITO.root+path,options);
 const data=await r.json();
 if(!r.ok) throw new Error(data.message||`Ошибка ${r.status}`);
 return data;
}
async function refresh() {
 state.data=await api('state');
 state.list=await api(`items?page=${state.page}&search=${encodeURIComponent(state.search)}&filter=${state.filter}`);
 for(const item of state.list.items) if(state.selected.has(item.id)) state.selected.set(item.id,item);
 render();
}
function categoryOptions(current) {return `<option value="0">Без назначения</option>`+state.data.categories.map(c=>`<option value="${c.id}" ${Number(current)===c.id?'selected':''}>${esc(c.name)}</option>`).join('');}
function render() {
 const d=state.data;
 root.innerHTML=`<div class="gg-shell"><header class="gg-header"><div><span class="gg-eyebrow">GG · ПЕРЕНОС КАТАЛОГА</span><h1>Авито → WooCommerce</h1><p>Подготовьте объявления. Проверьте детали. Опубликуйте выбранное.</p></div><span class="gg-badge ${d.woocommerce?'ok':''}">${d.woocommerce?'WooCommerce подключён':'WooCommerce не активен'}</span></header>
 <div id="gg-message" role="status" aria-live="polite" class="gg-message"></div>
 ${!d.settings.onboarding_done?`<section class="gg-welcome"><h2>Первый перенос — четыре простых шага</h2><p>1. Откройте «Подключение и загрузка» → 2. Загрузите ZIP из помощника → 3. Проверьте товары → 4. Создайте черновики или опубликуйте.</p><button class="button" data-action="demo">Попробовать на двух демотоварах</button> <button class="button" data-action="hide-help">Понятно, скрыть подсказку</button></section>`:''}
 <nav class="gg-tabs" aria-label="Разделы плагина">${[['items','Товары',d.counts],['import','Подключение и загрузка',''],['settings','Настройки',''],['help','Помощь и журнал','']].map(([key,label,count])=>`<button data-tab="${key}" class="${state.tab===key?'active':''}">${label} ${count!==''?`<span>${count}</span>`:''}</button>`).join('')}</nav>
 <main>${state.tab==='items'?itemsView():state.tab==='import'?importView():state.tab==='settings'?settingsView():helpView()}</main></div>`;
 root.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{if(state.busy)return;state.tab=b.dataset.tab;render();});
 bind();
}
function itemsView() {
 return `<section class="gg-card"><div class="gg-row"><div><h2>Подготовленные позиции</h2><p>Загрузка сюда ничего не публикует на сайте.</p></div><button class="button" data-action="refresh">Обновить</button></div>
 <form id="gg-search" class="gg-row"><input name="search" aria-label="Поиск по названию" placeholder="Поиск по названию…" value="${esc(state.search)}"><select name="filter" aria-label="Статус"><option value="">Все позиции</option><option value="review" ${state.filter==='review'?'selected':''}>Ещё не перенесены</option><option value="linked" ${state.filter==='linked'?'selected':''}>Связаны с WooCommerce</option></select><button class="button">Найти</button></form>
 <div class="gg-toolbar"><strong>Выбрано: ${state.selected.size}</strong><button class="button" data-action="bulk">Изменить цены / категории</button><button class="button" data-action="draft" ${!state.data.woocommerce?'disabled':''}>Создать / обновить черновики</button><button class="button button-primary" data-action="publish" ${!state.data.woocommerce?'disabled':''}>Опубликовать / обновить</button><button class="button" data-action="zip">Скачать ZIP</button><button class="button" data-action="csv">CSV</button><button class="button" data-action="clear">Снять выбор</button></div>
 <div class="gg-table-wrap"><table class="widefat striped"><thead><tr><td><input type="checkbox" id="gg-all" aria-label="Выбрать эту страницу"></td><th>Товар</th><th>Цена источника</th><th>Категория</th><th>Состояние</th><th></th></tr></thead><tbody>${state.list.items.map(i=>`<tr><td><input type="checkbox" data-select="${i.id}" aria-label="Выбрать ${esc(i.title)}" ${state.selected.has(i.id)?'checked':''}></td><td><div class="gg-product">${i.photos[0]?`<img src="${esc(i.photos[0])}" alt="" loading="lazy">`:'<span class="gg-placeholder">Нет фото</span>'}<div><strong>${esc(i.title)}</strong><small>ID Авито: ${esc(i.avito_id)}</small></div></div></td><td>${i.price===''?'Не указана':esc(i.price)+' ₽'}</td><td>${esc(i.category)||'—'}</td><td><span class="gg-status">${i.status==='review'?'На проверке':i.status==='publish'?'Опубликован':i.status==='draft'?'Черновик':esc(i.status||'Товар недоступен')}</span></td><td><button class="button" data-edit="${i.id}">Проверить</button>${i.product_edit_url?` <a href="${esc(i.product_edit_url)}">Товар ↗</a>`:''}</td></tr>`).join('')||'<tr><td colspan="6"><div class="gg-empty"><h3>Пока нет позиций</h3><p>Загрузите архив на вкладке «Подключение и загрузка» или добавьте демотовары.</p><button class="button" data-action="demo">Добавить демотовары</button></div></td></tr>'}</tbody></table></div>
 <div class="gg-pagination"><button class="button" data-action="prev" ${state.page<=1?'disabled':''}>← Назад</button><span>Страница ${state.page} из ${state.list.pages||1} · Найдено: ${state.list.total}</span><button class="button" data-action="next" ${state.page>=state.list.pages?'disabled':''}>Далее →</button></div></section>`;
}
function importView() {
 return `<div class="gg-columns"><section class="gg-card"><span class="gg-eyebrow">ШАГ 1 · ИСТОЧНИК</span><h2>Подключение к Авито</h2><span class="gg-status">Браузерный помощник · на вашем компьютере</span><p>Запустите <code>Start_Helper.bat</code> из репозитория. Нажмите «Открыть Авито / войти» и войдите в открывшемся браузере. Пароль и код подтверждения вводятся на странице Авито.</p><p>В помощнике укажите ссылку на свои объявления, соберите список, затем выгрузите ZIP. Сессия остаётся на вашем компьютере.</p><p><strong>Сохранённая ссылка:</strong> ${esc(state.data.settings.profile_url)||'Не указана — заполните в настройках'}</p><p>Авторизация Авито из WordPress в этой версии не выполняется. API и автоматическое расписание пока не подключены.</p><a class="button" href="https://github.com/DasBassKatze/avitobotparser" target="_blank" rel="noopener noreferrer">Открыть файлы помощника ↗</a></section>
 <section class="gg-card"><span class="gg-eyebrow">ШАГ 2 · ФАЙЛ</span><h2>Загрузить объявления</h2><p>ZIP с фотографиями или CSV UTF-8. До 5000 позиций; лимит файла на этом сайте: ${Math.floor(GG_AVITO.maxUpload/1048576)} МБ.</p>${!state.data.zip?'<p class="gg-warning">PHP ZipArchive недоступен. Обратитесь к хостингу или используйте CSV.</p>':''}<form id="gg-upload"><input type="file" name="file" accept=".zip,.csv" required aria-label="Файл объявлений"><label class="gg-check"><input type="checkbox" name="replace"> Заменить подготовленные данные для уже загруженных ID</label><p>Без галочки повторы пропускаются. Замена затронет правки в списке проверки; товары сайта обновляются отдельной кнопкой.</p><button class="button button-primary">Загрузить и обработать</button></form></section></div>
 <section class="gg-card"><h2>Загрузки за последние 7 дней</h2><p>Обработка идёт, пока открыта эта страница. После остановки можно продолжить. Временный файл сервер может удалить раньше семи дней.</p>${state.data.jobs.map(j=>`<div class="gg-job"><div><strong>${esc(j.name)}</strong><p>Всего: ${j.total} · Осталось: ${j.remaining} · Пропущено дублей: ${j.skipped} · Ошибок: ${Object.keys(j.errors).length}</p></div><div>${j.remaining?`<button class="button button-primary" data-job="${j.id}">Продолжить</button>`:''} ${Object.keys(j.errors).length?`<button class="button" data-retry="${j.id}">Повторить ошибки</button><details><summary>Показать ошибки</summary>${Object.values(j.errors).map(e=>`<p>${esc(e.avito_id)}: ${esc(e.message)}</p>`).join('')}</details>`:''}</div></div>`).join('')||'<p>Файлы ещё не загружались.</p>'}<button class="button" id="gg-stop" hidden>Остановить после текущей позиции</button></section>`;
}
function settingsView() {
 const s=state.data.settings;
 return `<section class="gg-card"><h2>Правила переноса</h2><form id="gg-settings" class="gg-form"><label>Ссылка на профиль / магазин Авито<input type="url" name="profile_url" value="${esc(s.profile_url)}" placeholder="https://www.avito.ru/…"></label><label>Наценка к цене источника, %<input type="number" name="markup" min="-100" max="10000" step="0.01" value="${s.markup}"></label><p>Применяется при переносе в WooCommerce. Исходная цена в списке проверки не меняется. Повторное обновление не накапливает наценку.</p><label>Категория WooCommerce по умолчанию<select name="default_category">${categoryOptions(s.default_category)}</select></label><p>Если название категории источника точно совпадает с существующей категорией сайта, используется она. Иначе — категория по умолчанию. Для других соответствий измените категорию в позиции.</p><fieldset><legend>Какие поля обновлять у уже связанных товаров</legend>${Object.entries(fields).map(([key,label])=>`<label class="gg-check"><input type="checkbox" name="update_fields" value="${key}" ${s.update_fields.includes(key)?'checked':''}>${label}</label>`).join('')}</fieldset><p>Для новых товаров переносятся все поля. По умолчанию при повторном переносе меняется только цена. Кнопка создания черновиков сохраняет статус уже опубликованного товара.</p><button class="button button-primary">Сохранить настройки</button></form></section>`;
}
function helpView() {
 return `<section class="gg-card"><h2>Частые вопросы</h2>${[
 ['Можно начать без доступа к Авито?','Да. Добавьте демотовары, проверьте редактирование и создайте черновики WooCommerce. Демо не проверяет сбор с Авито.'],
 ['Откуда взять файл?','Запустите Start_Helper.bat, войдите в браузере Авито, укажите страницу своих объявлений, соберите ссылки и сохраните ZIP. Можно подготовить CSV по примеру examples/products.csv.'],
 ['Почему сборщик не видит объявления?','Он зависит от разметки страниц Авито и не проверен на вашем аккаунте. При капче пройдите её вручную и повторите. Селекторы карточек и пагинации можно изменить в помощнике. API не используется.'],
 ['Почему не появилась фотография?','Внешний сервер мог запретить загрузку. В журнале будет ошибка. Надёжнее ZIP с локальными JPEG/PNG/WebP. Позиция с ошибкой фото не считается успешно загруженной.'],
 ['Появятся ли дубли?','Плагин связывает объявления и товары по ID Авито. Повторный импорт по умолчанию пропускает уже подготовленные позиции. Для обновления включите замену при загрузке, затем явно обновите выбранные товары.'],
 ['Как работают остатки, размеры и снятые объявления?','В этой версии создаются простые товары; характеристики текстовые. Остатки, варианты размеров и снятие с публикации автоматически не синхронизируются. Их можно настроить в WooCommerce.'],
 ['Что остаётся после удаления плагина?','Товары, фотографии и подготовленные записи сохраняются. Автоматическая очистка касается только временных файлов и заданий старше 7 дней.'],
 ['Что делает CSV безопасным для Excel?','Экспорт экранирует значения, начинающиеся со знаков формул, апострофом. Для точного переноса туда-обратно используйте ZIP.'],
 ].map(([q,a])=>`<details><summary>${q}</summary><p>${a}</p></details>`).join('')}</section><section class="gg-card"><h2>Последние операции</h2>${state.data.logs.map(l=>`<p><time>${esc(l.time)}</time> ${esc(l.message)}</p>`).join('')||'<p>Записей пока нет.</p>'}</section>`;
}
async function guarded(fn) {
 if(state.busy) return;
 state.busy=true;
 try { await fn(); } catch(e) {message(e.message,true);} finally {state.busy=false;}
}
function bind() {
 root.querySelectorAll('[data-action]').forEach(b=>b.onclick=()=>guarded(async()=>{
  const action=b.dataset.action;
  if(action==='refresh') await refresh();
  if(action==='demo') {await api('demo','POST',{});await refresh();message('Добавлены демотовары. Они ещё не опубликованы.');}
  if(action==='hide-help') {await api('settings','POST',{onboarding_done:true});await refresh();}
  if(action==='clear'){state.selected.clear();render();}
  if(action==='prev'||action==='next'){state.page+=action==='prev'?-1:1;await refresh();}
  if(action==='bulk') bulkDialog();
  if(action==='draft'||action==='publish') await transfer(action==='publish');
  if(action==='zip'||action==='csv') {
   requireSelection();message('Подготовка файла…');
   const result=await api('export','POST',{ids:[...state.selected.keys()],format:action});
   const bytes=Uint8Array.from(atob(result.base64),c=>c.charCodeAt(0));
   const url=URL.createObjectURL(new Blob([bytes],{type:result.mime}));const a=document.createElement('a');a.href=url;a.download=result.name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);message('Файл подготовлен.');
  }
 }));
 root.querySelectorAll('[data-select]').forEach(c=>c.onchange=()=>{const i=state.list.items.find(i=>i.id===Number(c.dataset.select));if(c.checked)state.selected.set(i.id,i);else state.selected.delete(i.id);render();});
 const all=root.querySelector('#gg-all');if(all){all.checked=state.list.items.length>0&&state.list.items.every(i=>state.selected.has(i.id));all.onchange=()=>{for(const i of state.list.items){if(all.checked)state.selected.set(i.id,i);else state.selected.delete(i.id);}render();};}
 root.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>{if(!state.busy)editDialog(state.list.items.find(i=>i.id===Number(b.dataset.edit)));});
 const search=root.querySelector('#gg-search');if(search)search.onsubmit=e=>{e.preventDefault();guarded(async()=>{state.search=search.search.value;state.filter=search.filter.value;state.page=1;await refresh();});};
 const settings=root.querySelector('#gg-settings');if(settings)settings.onsubmit=e=>{e.preventDefault();guarded(async()=>{const f=new FormData(settings);await api('settings','POST',{profile_url:f.get('profile_url'),markup:f.get('markup'),default_category:f.get('default_category'),update_fields:f.getAll('update_fields')});await refresh();message('Настройки сохранены.');});};
 const upload=root.querySelector('#gg-upload');if(upload)upload.onsubmit=e=>{e.preventDefault();guarded(async()=>{const f=new FormData(upload);if(f.get('file').size>GG_AVITO.maxUpload)throw new Error('Файл превышает лимит сайта.');f.set('replace',upload.replace.checked?'1':'0');message('Загрузка файла…');const job=await api('upload','POST',f);await runJob(job.job_id);});};
 root.querySelectorAll('[data-job]').forEach(b=>b.onclick=()=>guarded(()=>runJob(Number(b.dataset.job))));
 root.querySelectorAll('[data-retry]').forEach(b=>b.onclick=()=>guarded(async()=>{await api(`jobs/${b.dataset.retry}/retry`,'POST',{});await runJob(Number(b.dataset.retry));}));
}
function requireSelection(){if(!state.selected.size)throw new Error('Сначала выберите позиции слева в таблице.');}
async function runJob(id) {
 state.stop=false;const button=root.querySelector('#gg-stop');if(button){button.hidden=false;button.onclick=()=>{state.stop=true;button.disabled=true;};}
 let result;
 do {result=await api(`jobs/${id}/step`,'POST',{});message(`Обработка: осталось ${result.remaining??0} из ${result.total??0}. Ошибок: ${Object.keys(result.errors||{}).length}.`);}while(!result.done&&!state.stop);
 await refresh();message(state.stop?'Обработка остановлена. Можно продолжить в списке загрузок.':`Обработка завершена. Ошибок: ${Object.keys(result.errors||{}).length}. Проверьте позиции перед публикацией.`);
}
async function transfer(publish) {
 requireSelection();
 const chosen=[...state.selected.entries()];
 if(!confirm(`${publish?'Опубликовать / обновить':'Создать черновики / обновить'} выбранные позиции (${chosen.length})? Для существующих товаров применятся поля из настроек. Цена будет с наценкой ${state.data.settings.markup}%.`))return;
 const errors=[];let done=0;
 for(const [id,item] of chosen){try{await api(`items/${id}/publish`,'POST',{status:publish?'publish':'draft'});done++;}catch(e){errors.push(`${item.title}: ${e.message}`);}message(`Обработано ${done+errors.length} из ${chosen.length}`);}
 await refresh();message(`Успешно: ${done}. Ошибок: ${errors.length}.${errors.length?' '+errors.slice(0,5).join('; '):''}`,errors.length>0);
}
function dialog(html,onSave){
 const el=document.createElement('dialog');el.className='gg-dialog';el.innerHTML=`<form>${html}<p class="gg-dialog-error" role="alert"></p><div class="gg-row"><button type="button" class="button" data-close>Закрыть</button><button class="button button-primary">Сохранить</button></div></form>`;document.body.append(el);el.querySelector('[data-close]').onclick=()=>el.close();el.onclose=()=>el.remove();el.querySelector('form').onsubmit=async e=>{e.preventDefault();const save=el.querySelector('button[type="submit"],button:not([type])');save.disabled=true;try{await onSave(new FormData(e.target));el.close();}catch(error){el.querySelector('.gg-dialog-error').textContent=error.message;}finally{save.disabled=false;}};el.showModal();
}
function editDialog(item){
 dialog(`<h2>Проверка объявления</h2><div class="gg-gallery">${item.photos.map(p=>`<img src="${esc(p)}" alt="Фото товара">`).join('')}</div><label>Название<input name="title" value="${esc(item.title)}" required></label><label>Описание<textarea name="description" rows="7">${esc(item.description)}</textarea></label><label>Цена источника, ₽<input name="price" type="number" step="0.01" min="0" value="${esc(item.price)}"></label><label>Категория (точное название на сайте)<input name="category" value="${esc(item.category)}" list="gg-cats"><datalist id="gg-cats">${state.data.categories.map(c=>`<option value="${esc(c.name)}">`).join('')}</datalist></label><label>Характеристики — JSON объект<textarea name="attributes" rows="5">${esc(JSON.stringify(item.attributes,null,2))}</textarea></label>${item.url?`<p><a href="${esc(item.url)}" target="_blank" rel="noopener noreferrer">Оригинал на Авито ↗</a></p>`:''}<p>Сохранение меняет подготовленную позицию. Для обновления сайта используйте кнопку переноса.</p>`,async f=>{const attributes=JSON.parse(f.get('attributes'));await api(`items/${item.id}`,'POST',{title:f.get('title'),description:f.get('description'),price:f.get('price'),category:f.get('category'),attributes});await refresh();message('Изменения сохранены.');});
}
function bulkDialog(){
 requireSelection();dialog(`<h2>Изменить выбранные позиции (${state.selected.size})</h2><label>Изменение исходной цены, %<input name="percent" type="number" min="-100" max="10000" step="0.01" value="0"></label><label>Назначить категорию<input name="category" placeholder="Пусто — не менять"></label><p>Эта операция меняет цены в подготовленном списке. При переносе дополнительно применяется наценка из настроек.</p>`,async f=>{const percent=Number(f.get('percent'));if(!Number.isFinite(percent)||percent < -100 || percent>10000)throw new Error('Неверный процент.');let done=0;for(const [id,item] of state.selected){const patch={};if(item.price!==''&&percent!==0)patch.price=(Number(item.price)*(1+percent/100)).toFixed(2);if(f.get('category').trim())patch.category=f.get('category').trim();await api(`items/${id}`,'POST',patch);done++;}await refresh();message(`Обновлено позиций: ${done}`);});
}
refresh().catch(e=>{root.textContent='Не удалось открыть панель: '+e.message;});
})();
