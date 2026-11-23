'use strict';
const $ = (selector, root = document) => root.querySelector(selector);
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const icons = {
  calendar: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 6-6 4 4 3-3 5 5"/>',
  channels: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/><path d="M17 3v5a4 4 0 0 1-4 4H7v9M3 17h8"/>',
  plus: '<path d="M12 5v14M5 12h14"/>', search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
  chevron: '<path d="m9 5 7 7-7 7"/>', close: '<path d="m6 6 12 12M18 6 6 18"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', check: '<path d="m5 12 4 4L19 6"/>',
  upload: '<path d="M12 16V3m-5 5 5-5 5 5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>',
  draft: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M8 13h8M8 17h5"/>',
  logout: '<path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M14 8l4 4-4 4M8 12h10"/>',
  warning: '<path d="m12 3 10 18H2zM12 9v5M12 17h.01"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="m9 3-.5 3-2 1-3-.5-2 4L4 12l-.5 3 3.5 3 2-1 3 1 2-2 3 .5 3-4-1.5-2 0-3-3-2-2 1z"/>',
  copy: '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
};
const icon = (name, cls = '') => `<svg class="icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || icons.grid}</svg>`;
const networkName = { instagram: 'Instagram', facebook: 'Facebook', youtube: 'YouTube' };
const networkIcon = (network, small = false) => `<span class="network-icon ${esc(network)} ${small ? 'small' : ''}" aria-label="${esc(networkName[network])}">${network === 'instagram' ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="5"/><circle cx="12" cy="12" r="3.5"/><circle cx="17" cy="7" r=".8" fill="currentColor"/></svg>' : network === 'youtube' ? '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="5" width="20" height="14" rx="5"/><path d="m10 9 6 3-6 3z" fill="white"/></svg>' : '<span>f</span>'}</span>`;
let data, view = location.hash.slice(1) || 'calendar', mode = 'week', anchor = new Date(), filter = 'all', query = '', editorPost = null, chosenMedia = [];
let toastTimer;
function toast(message, error = false) { const el = $('#toast'); el.textContent = message; el.className = error ? 'visible error' : 'visible'; clearTimeout(toastTimer); toastTimer = setTimeout(() => { el.className = ''; }, 6000); }
async function api(action, body) {
  const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, body === undefined ? { credentials: 'same-origin' } : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data?.csrf || '' }, body: JSON.stringify(body) });
  let result; try { result = await response.json(); } catch { throw new Error('Servern gav inget läsbart svar. Kontrollera PHP-konfigurationen.'); }
  if (!response.ok) throw new Error(result.error || 'Åtgärden kunde inte slutföras.'); return result;
}
function dateKey(date) { return date.toLocaleDateString('sv-SE'); }
function today() { return new Intl.DateTimeFormat('sv-SE', { timeZone: data.timezone }).format(new Date()); }
function utc(value) { return value ? new Date(value.replace(' ', 'T') + 'Z') : null; }
function localInput(value, tz = data.timezone) {
  const date = value instanceof Date ? value : utc(value);
  if (!date) return '';
  const p = Object.fromEntries(new Intl.DateTimeFormat('sv-SE', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date).map((x) => [x.type, x.value]));
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}
function timeLabel(value) { return utc(value)?.toLocaleTimeString('sv-SE', { timeZone: data.timezone, hour: '2-digit', minute: '2-digit' }) || 'Utkast'; }
function dayFor(post) { return post.scheduled_at ? localInput(post.scheduled_at).slice(0, 10) : null; }
function weekStart() { const d = new Date(anchor); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() - (d.getDay() + 6) % 7); return d; }
function postStatus(post) {
  if (post.status === 'draft') return ['draft', 'Utkast'];
  if (post.status === 'cancelled') return ['cancelled', 'Avbrutet'];
  const pubs = post.publications;
  if (pubs.some((p) => p.status === 'uncertain')) return ['uncertain', 'Kontroll behövs'];
  if (pubs.some((p) => p.status === 'processing')) return ['processing', 'Publicerar'];
  if (pubs.length && pubs.every((p) => p.status === 'published')) return ['published', 'Publicerat'];
  if (pubs.some((p) => p.status === 'failed')) return ['failed', pubs.some((p) => p.status === 'published') ? 'Delvis publicerat' : 'Misslyckat'];
  return ['scheduled', 'Schemalagt'];
}
function badge(post) { const [state, label] = postStatus(post); return `<span class="badge ${state}">${state === 'published' ? icon('check') : state === 'draft' ? icon('draft') : state === 'uncertain' || state === 'failed' ? icon('warning') : icon('clock')}${label}</span>`; }
function filteredPosts() { return data.posts.filter((p) => (filter === 'all' || postStatus(p)[0] === filter || p.publications.some((pub) => pub.network === filter)) && `${p.title} ${p.caption}`.toLocaleLowerCase('sv').includes(query.toLocaleLowerCase('sv'))); }
async function refresh() { data = await api('bootstrap'); render(); if (data.flash) toast(data.flash.message, data.flash.type === 'error'); }
function shell(content, title, subtitle, actions = '') {
  const nav = [['overview', 'grid', 'Översikt'], ['calendar', 'calendar', 'Kalender'], ['drafts', 'draft', 'Utkast'], ['media', 'image', 'Mediabibliotek'], ['channels', 'channels', 'Kanaler']];
  return `<aside class="sidebar"><a class="brand" href="#calendar"><span class="brand-mark">S</span>socialen<span class="brand-period">.</span></a><div class="workspace"><span class="workspace-avatar">${esc(data.user.name.charAt(0).toUpperCase())}</span><div><strong>Min arbetsyta</strong><small>${esc(data.user.name)}</small></div><span class="workspace-tag">EGEN</span></div><div class="nav-label">DIN PUBLICERING</div><nav aria-label="Huvudmeny">${nav.map(([id, symbol, label]) => `<a href="#${id}" class="nav-item ${view === id ? 'active' : ''}" ${view === id ? 'aria-current="page"' : ''}>${icon(symbol)}<span>${label}</span>${id === 'drafts' ? `<span class="nav-count">${data.posts.filter((p) => p.status === 'draft').length}</span>` : ''}</a>`).join('')}</nav><div class="sidebar-bottom"><div class="own-space">${icon('check')}<div><strong>Din plats. Ditt innehåll.</strong><p>Planera i lugn och ro.<br>Publicera när det passar.</p></div></div><button class="profile" data-action="logout"><span class="user-avatar">${esc(data.user.name.charAt(0).toUpperCase())}</span><span><strong>${esc(data.user.name)}</strong><small>${data.demo ? 'Lokal förhandsvisning' : 'Administratör'}</small></span>${icon('logout')}</button></div></aside><main class="main"><header class="topbar"><div class="breadcrumb">Min arbetsyta <span>/</span> ${esc(title)}</div><div class="topbar-right"><span class="timezone">${icon('clock')}${esc(data.timezone)}</span><span class="avatar-mini">${esc(data.user.name.charAt(0).toUpperCase())}</span></div></header><div class="page"><div class="page-heading"><div><p class="eyebrow">${view === 'calendar' ? 'LITE FRAMFÖRHÅLLNING. MER SKAPARGLÄDJE.' : 'EN PLATS FÖR DITT INNEHÅLL'}</p><h1>${esc(title)}</h1><p class="subtitle">${esc(subtitle)}</p></div><div class="heading-actions">${actions}<button class="button primary" data-action="new">${icon('plus')}Skapa inlägg</button></div></div>${data.demo ? '<div class="demo-banner"><span class="demo-pill">LOKAL DEMO</span><span>Prova kalendern och skapa egna utkast. Demokanalerna publicerar inget.</span></div>' : workerBanner()}${content}<footer class="page-footer"><span>Skapat för din egen takt.</span><span><a href="about.php">Om Socialen</a> · <a href="privacy.php">Integritet</a> · <a href="terms.php">Villkor</a> · <a href="data-deletion.php">Radera uppgifter</a></span></footer></div></main>`;
}
function workerBanner() {
  if (view === 'channels') return '';
  if (!data.last_worker || Date.now() - utc(data.last_worker).getTime() > 300000) return `<div class="notice warning-notice">${icon('warning')}<span>${data.last_worker ? 'Publiceringsjobbet har inte körts på över fem minuter.' : 'Automatisk publicering väntar på att cron-jobbet aktiveras.'} <a href="#channels">Visa driftstatus</a></span></div>`;
  return '';
}
function render() {
  if (!data.user) { renderLogin(); return; }
  if (!data.policy_accepted) { renderPolicyConsent(); return; }
  if (!['calendar', 'overview', 'drafts', 'media', 'channels'].includes(view)) view = 'calendar';
  const views = {
    calendar: () => shell(calendar(), 'Din innehållskalender', 'Ge dina idéer en plats. Vi håller ordning på resten.'),
    overview: () => shell(overview(), `Hej ${data.user.name.split(' ')[0]}, här är läget.`, 'En liten överblick över det du skapar och delar.'),
    drafts: () => shell(drafts(), 'Idéer som får ta sin tid', 'Här samlas dina utkast tills de är redo att möta världen.'),
    media: () => shell(mediaView(), 'Ditt mediabibliotek', 'Bilder och videor, redo för nästa berättelse.', `<button class="button secondary" data-action="upload">${icon('upload')}Ladda upp</button>`),
    channels: () => shell(channelsView(), 'Dina kanaler', 'Koppla ihop din arbetsyta med platserna där du publicerar.'),
  };
  $('#app').innerHTML = views[view]();
  $('#search')?.addEventListener('input', (event) => { query = event.target.value; const position = event.target.selectionStart; render(); $('#search').focus(); $('#search').setSelectionRange(position, position); });
  $('#filter')?.addEventListener('change', (event) => { filter = event.target.value; render(); });
}
function filters() { return `<div class="filters"><div class="network-filters" aria-label="Filtrera kanaler"><button class="filter-chip ${filter === 'all' ? 'selected' : ''}" data-filter="all">Alla kanaler</button>${Object.keys(networkName).map((network) => `<button class="filter-chip ${filter === network ? 'selected' : ''}" data-filter="${network}">${networkIcon(network, true)}<span>${networkName[network]}</span></button>`).join('')}</div><label class="search">${icon('search')}<input id="search" placeholder="Sök bland inlägg" aria-label="Sök bland inlägg" value="${esc(query)}"></label></div>`; }
function calendar() {
  const start = weekStart(); const end = new Date(start); end.setDate(end.getDate() + 6);
  const monthLabel = mode === 'week' ? `${start.getDate()}–${end.getDate()} ${end.toLocaleDateString('sv-SE', { month: 'long', year: 'numeric' })}` : anchor.toLocaleDateString('sv-SE', { month: 'long', year: 'numeric' });
  return `<section class="calendar-panel">${filters()}<div class="calendar-toolbar"><div class="calendar-nav"><button class="icon-button previous" data-action="previous" aria-label="Föregående ${mode === 'week' ? 'vecka' : 'månad'}">${icon('chevron')}</button><button class="icon-button" data-action="next" aria-label="Nästa ${mode === 'week' ? 'vecka' : 'månad'}">${icon('chevron')}</button><h2>${esc(monthLabel)}</h2><button class="button compact secondary" data-action="today">Idag</button></div><div class="segmented" aria-label="Kalendervy"><button class="${mode === 'week' ? 'selected' : ''}" data-mode="week">Vecka</button><button class="${mode === 'month' ? 'selected' : ''}" data-mode="month">Månad</button><button class="${mode === 'list' ? 'selected' : ''}" data-mode="list">Lista</button></div></div>${mode === 'list' ? listPosts(filteredPosts()) : calendarGrid(start)}</section><div class="calendar-caption"><span><i class="legend scheduled"></i>Schemalagt <i class="legend draft"></i>Utkast <i class="legend published"></i>Publicerat</span><span>${filteredPosts().filter((p) => p.status !== 'draft' && p.status !== 'cancelled').length} inlägg i din kalender</span></div>`;
}
function calendarGrid(start) {
  const month = mode === 'month';
  let first = new Date(start); let length = 7;
  if (month) { first = new Date(anchor.getFullYear(), anchor.getMonth(), 1, 12); first.setDate(1 - (first.getDay() + 6) % 7); length = 42; }
  const posts = filteredPosts();
  const labels = ['MÅN', 'TIS', 'ONS', 'TOR', 'FRE', 'LÖR', 'SÖN'];
  return `<div class="calendar-scroll"><div class="calendar-grid ${month ? 'month-grid' : 'week-grid'}">${month ? labels.map((label) => `<div class="month-label">${label}</div>`).join('') : ''}${Array.from({ length }, (_, i) => {
    const date = new Date(first); date.setDate(first.getDate() + i); const key = dateKey(date); const current = key === today(); const items = posts.filter((post) => dayFor(post) === key && post.status !== 'draft' && post.status !== 'cancelled').sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at));
    return `<div class="calendar-day ${current ? 'is-today' : ''} ${i % 7 > 4 ? 'weekend' : ''} ${month && date.getMonth() !== anchor.getMonth() ? 'outside-month' : ''}"><div class="day-heading">${month ? '' : `<span>${labels[i % 7]}</span>`}<button class="day-number" data-day="${key}" aria-label="Skapa inlägg ${key}">${date.getDate()}</button>${current && !month ? '<small>Idag</small>' : ''}</div><div class="day-posts">${items.map((post, n) => postCard(post, month, n)).join('')}${!items.length && !month ? `<button class="empty-slot" data-day="${key}">${icon('plus')}<span>Plats för en idé</span></button>` : ''}</div><button class="day-add" data-day="${key}" aria-label="Lägg till inlägg ${key}">${icon('plus')}</button></div>`;
  }).join('')}</div></div>`;
}
function postCard(post, compact = false) {
  const media = data.media.find((m) => String(m.id) === String(post.media_ids[0])); const [status] = postStatus(post);
  return `<button class="post-card ${status} ${compact ? 'compact-card' : ''}" data-post="${post.id}"><span class="post-top"><span>${icon('clock')}${timeLabel(post.scheduled_at)}</span><span class="mini-indicator ${status}" title="${esc(postStatus(post)[1])}"></span></span>${media && !compact ? mediaPreview(media, 'post-thumb') : !compact && data.demo ? `<span class="demo-art art-${Number(post.id) % 4}"><span class="art-line"></span><span class="art-caption">${['Det lilla<br>i det stora.', 'Bakom<br>ögonblicket.', 'En tanke.<br>En början.', 'Mer att<br>berätta.'][Number(post.id) % 4]}</span><span class="art-star">✳</span><small>STYVERZ / STUDIO</small></span>` : ''}<strong>${esc(post.title)}</strong>${compact ? '' : `<p>${esc(post.caption.slice(0, 100))}${post.caption.length > 100 ? '…' : ''}</p>`}<span class="post-bottom"><span class="post-networks">${[...new Set(post.publications.map((p) => p.network))].map((n) => networkIcon(n, true)).join('')}</span><span>${status === 'published' ? 'Publicerat' : status === 'failed' || status === 'uncertain' ? 'Kontrollera' : 'Planerat'}</span></span></button>`;
}
function mediaPreview(media, cls = '') { return media.mime === 'video/mp4' ? `<video class="${cls}" src="media.php?id=${media.id}#t=0.1" preload="metadata" muted playsinline aria-label="${esc(media.name)}"></video>` : `<img class="${cls}" src="media.php?id=${media.id}" alt="${esc(media.name)}" loading="lazy">`; }
function empty(title, text, action = 'new', label = 'Skapa ett inlägg', symbol = 'calendar') { return `<div class="empty-state"><span class="empty-symbol">${icon(symbol)}</span><h3>${esc(title)}</h3><p>${esc(text)}</p><button class="button secondary" data-action="${action}">${icon('plus')}${esc(label)}</button></div>`; }
function listPosts(posts) {
  if (!posts.length) return empty('Här finns plats för något bra.', 'Inga inlägg matchar ditt val. Skapa ett nytt eller ändra filtret.');
  return `<div class="post-list"><div class="list-label"><span>INNEHÅLL</span><span>KANALER</span><span>PUBLICERING</span><span>STATUS</span></div>${posts.map((p) => `<button class="post-row" data-post="${p.id}"><span class="row-title"><span class="row-thumb">${p.media_ids.length && data.media.find((m) => String(m.id) === String(p.media_ids[0])) ? mediaPreview(data.media.find((m) => String(m.id) === String(p.media_ids[0]))) : icon('draft')}</span><span><strong>${esc(p.title)}</strong><small>${esc(p.caption.slice(0, 68))}</small></span></span><span class="post-networks">${[...new Set(p.publications.map((x) => x.network))].map((n) => networkIcon(n, true)).join('')}</span><span class="row-date">${p.scheduled_at ? utc(p.scheduled_at).toLocaleDateString('sv-SE', { timeZone: data.timezone, day: 'numeric', month: 'short' }) + ' · ' + timeLabel(p.scheduled_at) : 'Inte planerat'}</span>${badge(p)}</button>`).join('')}</div>`;
}
function overview() {
  const counts = { scheduled: 0, draft: 0, published: 0, failed: 0 }; data.posts.forEach((p) => { const s = postStatus(p)[0]; counts[s] = (counts[s] || 0) + 1; });
  return `<div class="stats">${[['scheduled','clock','På väg ut','Inlägg i din publiceringskö'],['draft','draft','Idéer på gång','Utkast att fortsätta med'],['published','check','Delat med världen','Publicerat via din arbetsyta'],['channels','channels','Anslutna kanaler','Dina platser på nätet']].map(([key,symbol,label,small]) => `<div class="stat"><span class="stat-symbol ${key}">${icon(symbol)}</span><span class="stat-number">${key === 'channels' ? data.channels.filter((c) => Number(c.active)).length : counts[key] || 0}</span><strong>${label}</strong><small>${small}</small></div>`).join('')}</div><section class="content-panel"><div class="section-heading"><div><h2>Nästa berättelse att dela</h2><p>Dina kommande och senaste inlägg, samlade på ett ställe.</p></div><a class="text-link" href="#calendar">Öppna kalendern</a></div>${listPosts(data.posts.filter((p) => p.status !== 'cancelled').slice(0, 8))}</section>`;
}
function drafts() { const posts = filteredPosts().filter((p) => p.status === 'draft'); return `<section class="content-panel">${filters()}${posts.length ? listPosts(posts) : empty('Alla bra inlägg börjar med en idé.', 'Spara en rubrik, en bild eller en tanke. Du behöver inte bestämma allt på en gång.', 'new', 'Skapa ditt första utkast', 'draft')}</section>`; }
function mediaView() { return `<section class="content-panel"><div class="section-heading"><div><h2>Allt ditt material <span class="count-label">${data.media.length}</span></h2><p>JPEG, PNG, WebP och MP4 · högst ${data.upload_limit_mb} MB per fil</p></div><span class="muted">Bilder sparas som JPEG</span></div>${data.media.length ? `<div class="media-grid">${data.media.map((m) => `<button class="media-tile" data-media-preview="${m.id}"><span class="media-visual">${mediaPreview(m)}${m.mime === 'video/mp4' ? '<span class="video-label">MP4</span>' : ''}</span><strong>${esc(m.name)}</strong><small>${m.width ? `${m.width} × ${m.height} · ` : ''}${formatBytes(m.bytes)}</small></button>`).join('')}</div>` : empty('Ditt nästa inlägg börjar här.', 'Ladda upp bilder och videor så finns de redo när inspirationen kommer.', 'upload', 'Ladda upp media', 'image')}</section>`; }
function formatBytes(value) { return Number(value) >= 1048576 ? `${(Number(value) / 1048576).toFixed(1)} MB` : `${Math.ceil(Number(value) / 1024)} kB`; }
function channelsView() {
  return `<div class="channel-grid">${Object.entries(networkName).map(([network, name]) => {
    const channels = data.channels.filter((c) => c.network === network); const provider = network === 'youtube' ? 'google' : 'meta';
    return `<section class="channel-card"><div class="channel-card-top">${networkIcon(network)}<h2>${name}</h2></div><p>${network === 'instagram' ? 'Bilder, bildkaruseller och Reels för ditt professionella konto.' : network === 'facebook' ? 'Text, bilder och video till dina Facebook-sidor.' : 'Videor till din YouTube-kanal, med vald synlighet.'}</p><div class="connected-list">${channels.map((c) => `<div class="connected-channel"><span class="channel-avatar">${esc(c.name.charAt(0))}</span><div><strong>${esc(c.name)}</strong><small>${data.demo ? 'Demokanal · ingen publicering' : Number(c.active) ? 'Ansluten' : 'Frånkopplad'}</small></div>${!data.demo && (Number(c.active) || network === 'youtube') ? `<button class="text-button danger" data-disconnect="${c.id}">${network === 'youtube' ? 'Koppla från och radera Google-data' : 'Koppla från'}</button>` : ''}</div>`).join('') || '<div class="not-connected">Ingen kanal ansluten ännu</div>'}</div><button class="button secondary full" data-connect="${provider}">${icon('plus')}${channels.length ? 'Anslut eller förnya kanal' : 'Anslut ' + name}</button><small class="provider-note">${data.demo ? 'Anslutningar aktiveras i din riktiga installation.' : data.providers[provider] ? 'Appuppgifter finns. Fortsätt till plattformen.' : 'Appuppgifter behöver fyllas i före anslutning.'}</small></section>`;
  }).join('')}</div><section class="content-panel setup-panel"><div class="section-heading"><div><h2>Redo för automatisk publicering?</h2><p>Din arbetsyta behöver två saker för att kunna arbeta åt dig.</p></div>${icon('settings')}</div><div class="setup-columns"><div><h3>Plattformarnas appanslutningar</h3><p>Fyll i Google- och Meta-uppgifterna i serverns konfiguration. Instagram ansluts via en Facebook-sida. Kontot behöver vara professionellt.</p><p class="muted">Googles testläge kan ge förnyelsetoken med sju dagars livslängd. Följ installationsguiden för varaktig anslutning.</p></div><div><h3>Publiceringsjobb via cron</h3><p>Kör <code>bin/worker.php</code> regelbundet från DirectAdmin. Webbläsaren behöver inte vara öppen.</p><div class="worker-status">${icon(data.last_worker ? 'check' : 'clock')}<span>${data.last_worker ? 'Senast kört ' + esc(localInput(data.last_worker).replace('T', ' ')) : 'Inget publiceringsjobb har registrerats ännu.'}</span></div></div></div></section>`;
}
function renderLogin() { $('#app').innerHTML = `<main class="login-page"><div class="login-brand brand"><span class="brand-mark">S</span>socialen.</div><section class="login-card"><p class="eyebrow">DIN EGEN INNEHÅLLSSTUDIO</p><h1>Välkommen tillbaka.</h1><p>Här får dina idéer en plats och dina inlägg en plan.</p><form id="login-form"><label>E-post<input type="email" name="email" autocomplete="username" required></label><label>Lösenord<input type="password" name="password" autocomplete="current-password" required></label><label class="policy-checkbox"><input type="checkbox" name="accept_policies" required><span>Jag har läst och godkänner <a href="privacy.php" target="_blank" rel="noopener">integritetspolicyn</a> och <a href="terms.php" target="_blank" rel="noopener">användarvillkoren</a>.</span></label><input type="hidden" name="policy_version" value="${esc(data.policy_version)}"><div id="login-error" class="form-error" role="alert"></div><button class="button primary full" type="submit">Logga in</button></form></section><small><a href="about.php">Om Socialen</a> · <a href="privacy.php">Integritet</a> · <a href="terms.php">Villkor</a> · <a href="data-deletion.php">Radera uppgifter</a></small></main>`; $('#login-form').addEventListener('submit', async (event) => { event.preventDefault(); const button = $('button', event.target); button.disabled = true; try { const fields = new FormData(event.target); await api('login', Object.fromEntries(fields)); await refresh(); } catch (e) { $('#login-error').textContent = e.message; } finally { button.disabled = false; } }); }
function renderPolicyConsent() {
  $('#app').innerHTML = `<main class="login-page"><div class="login-brand brand"><span class="brand-mark">S</span>socialen.</div><section class="login-card"><p class="eyebrow">INTEGRITET OCH KANALÅTKOMST</p><h1>Du bestämmer över dina uppgifter.</h1><p>Läs hur Socialen använder kanalanslutningar, publicerar ditt valda innehåll och hanterar radering.</p><form id="policy-form"><label class="policy-checkbox"><input type="checkbox" required><span>Jag har läst och godkänner <a href="privacy.php" target="_blank" rel="noopener">integritetspolicyn</a> och <a href="terms.php" target="_blank" rel="noopener">användarvillkoren</a>, inklusive YouTubes villkor för YouTube-funktionerna.</span></label><p class="muted">Version ${esc(data.policy_version)} · <a href="data-deletion.php" target="_blank" rel="noopener">Så raderar du uppgifter</a></p><div id="policy-error" class="form-error" role="alert"></div><button class="button primary full" type="submit">Godkänn och öppna arbetsytan</button><button class="button secondary full" type="button" data-action="logout">Logga ut</button></form></section></main>`;
  $('#policy-form').addEventListener('submit', async (event) => {
    event.preventDefault(); const button = $('button[type="submit"]', event.target); button.disabled = true;
    try { await api('accept-policy', { version: data.policy_version }); await refresh(); }
    catch (error) { $('#policy-error').textContent = error.message; } finally { button.disabled = false; }
  });
}
function openEditor(post = null, day = null, copy = false) {
  editorPost = post && !copy ? post : null; chosenMedia = [...(post?.media_ids || [])].map(Number);
  const dialog = $('#editor'); const next = new Date(Date.now() + 3600000); const scheduled = post?.scheduled_at && !copy ? localInput(post.scheduled_at, post.timezone) : day ? `${day}T${day === today() ? localInput(next).slice(11) : '10:00'}` : localInput(next);
  const locked = post && !copy && (post.status === 'cancelled' || post.publications.some((p) => p.started || !['pending', 'cancelled'].includes(p.status)));
  const channels = data.channels.filter((c) => Number(c.active));
  dialog.innerHTML = `<form id="post-form"><div class="dialog-header"><div><p class="eyebrow">${locked ? 'PUBLICERINGSDETALJER' : editorPost ? 'FORTSÄTT SKAPA' : 'FRÅN IDÉ TILL INLÄGG'}</p><h2 id="editor-title">${locked ? esc(post.title) : editorPost ? 'Redigera inlägg' : 'Vad vill du dela?'}</h2></div><button type="button" class="icon-button" data-close="editor" aria-label="Stäng">${icon('close')}</button></div><div class="composer"><div class="composer-main">${locked ? `<div class="detail-caption">${esc(post.caption).replace(/\n/g, '<br>')}</div>${publicationDetails(post)}` : `<label class="field-label">Rubrik <span>Visas som titel på YouTube</span><input name="title" required maxlength="100" placeholder="Ge din idé en rubrik" value="${esc(post ? post.title + (copy ? ' (kopia)' : '') : '')}"></label><label class="field-label">Din text<textarea name="caption" id="caption" rows="7" maxlength="60000" placeholder="Berätta något. Dela ett ögonblick. Börja här…">${esc(post?.caption || '')}</textarea><span class="caption-count" id="caption-count">${(post?.caption || '').length} tecken</span></label><div class="media-heading"><span>Bild eller video</span><button type="button" class="text-button" data-action="upload-editor">${icon('upload')}Ladda upp</button></div><div id="chosen-media" class="chosen-media"></div><details class="library-picker"><summary>Välj från mediabiblioteket <span>${data.media.length} filer</span></summary><div class="picker-grid">${data.media.length ? data.media.map((m) => `<button class="picker-item ${chosenMedia.includes(Number(m.id)) ? 'selected' : ''}" type="button" data-pick="${m.id}" aria-label="Välj ${esc(m.name)}" aria-pressed="${chosenMedia.includes(Number(m.id))}">${mediaPreview(m)}<span>${esc(m.name)}</span></button>`).join('') : '<p class="muted">Ladda upp din första bild eller video.</p>'}</div></details>`}</div><aside class="composer-aside"><h3>Var ska det synas?</h3><div class="channel-choices">${channels.length ? channels.map((c) => `<label class="channel-choice ${locked ? 'disabled' : ''}"><input type="checkbox" name="channels" value="${c.id}" ${post?.publications.some((p) => String(p.channel_id) === String(c.id)) ? 'checked' : ''} ${locked ? 'disabled' : ''}>${networkIcon(c.network, true)}<span><strong>${esc(c.name)}</strong><small>${esc(networkName[c.network])}</small></span></label>`).join('') : '<p class="muted">Anslut en kanal för att schemalägga. Du kan spara ett utkast redan nu.</p><a href="#channels" class="text-link" data-close="editor">Öppna Kanaler</a>'}</div>${!locked ? `<div class="schedule-fields"><h3>Hitta rätt stund</h3><label class="field-label">Datum och tid<input type="datetime-local" name="scheduled_at" value="${esc(scheduled)}"></label><label class="field-label">Tidszon<select name="timezone">${[...new Set([post?.timezone || data.timezone, 'Europe/Stockholm', 'UTC'])].map((tz) => `<option>${esc(tz)}</option>`).join('')}</select></label><div id="youtube-options" hidden><label class="field-label">Synlighet på YouTube<select name="visibility"><option value="private" ${post?.visibility === 'private' ? 'selected' : ''}>Privat</option><option value="unlisted" ${post?.visibility === 'unlisted' ? 'selected' : ''}>Olistad</option><option value="public" ${post?.visibility === 'public' ? 'selected' : ''}>Offentlig</option></select></label><label class="field-label">YouTube: avsedd för barn?<select name="made_for_kids"><option value="">Välj för YouTube</option><option value="no" ${post?.made_for_kids !== null && Number(post?.made_for_kids) === 0 ? 'selected' : ''}>Nej, inte avsedd för barn</option><option value="yes" ${Number(post?.made_for_kids) === 1 ? 'selected' : ''}>Ja, avsedd för barn</option></select></label></div><p class="small-note">Publiceringen börjar vid vald tid. Videobearbetning hos plattformen kan ta ytterligare tid.</p></div>` : `<div class="detail-status">${badge(post)}<p>${post.scheduled_at ? esc(localInput(post.scheduled_at).replace('T', ' ')) : ''}<br>${esc(post.timezone)}</p></div>`}</aside></div><div id="editor-error" class="form-error" role="alert"></div><div class="dialog-footer">${locked ? `<span class="muted">Börja på något nytt med samma innehåll.</span><button type="button" class="button primary" data-copy="${post.id}">${icon('copy')}Skapa kopia</button>` : `${editorPost?.status === 'scheduled' ? `<button type="button" class="text-button danger" data-cancel="${editorPost.id}">Avbryt publicering</button>` : `<span class="save-hint">${icon('draft')}En bra idé kan börja som ett utkast.</span>`}<div><button type="submit" name="intent" value="draft" class="button secondary">Spara utkast</button><button type="submit" name="intent" value="scheduled" class="button primary">${icon('calendar')}Schemalägg</button></div>`}</div></form>`;
  if (!dialog.open) dialog.showModal(); renderChosenMedia(); updateYouTubeOptions();
  $('#caption')?.addEventListener('input', (event) => { $('#caption-count').textContent = `${[...event.target.value].length} tecken`; });
  $('#post-form').addEventListener('submit', savePost);
}
function publicationDetails(post) {
  return `<div class="publication-details">${post.publications.map((p) => `<section>${networkIcon(p.network, true)}<div><strong>${esc(p.channel_name)}</strong><p>${esc(({ pending: 'Väntar på publicering', processing: 'Publicering pågår', published: 'Publicerat', failed: 'Publiceringen stoppades', uncertain: 'Resultatet behöver kontrolleras manuellt', cancelled: 'Avbrutet' })[p.status] || p.status)}</p>${p.last_error ? `<small>${esc(p.last_error)}</small>` : ''}${p.remote_url && /^https:\/\/(www\.)?(youtube\.com|facebook\.com|instagram\.com)\//.test(p.remote_url) ? `<a class="text-link" href="${esc(p.remote_url)}" target="_blank" rel="noopener noreferrer">Visa hos plattformen</a>` : ''}${p.status === 'uncertain' ? `<button type="button" class="button compact secondary" data-reconcile="${p.id}">Stäm av resultatet</button>` : ''}</div></section>`).join('')}${post.status === 'scheduled' && post.publications.some((p) => p.status === 'pending' || p.status === 'failed') ? `<button type="button" class="text-button danger" data-cancel="${post.id}">Avbryt återstående publiceringar</button>` : ''}</div>`;
}
function updateYouTubeOptions() { const panel = $('#youtube-options'); if (!panel) return; const selected = [...document.querySelectorAll('input[name="channels"]:checked')].map((el) => Number(el.value)); panel.hidden = !data.channels.some((c) => c.network === 'youtube' && selected.includes(Number(c.id))); }
document.addEventListener('change', (event) => { if (event.target.matches('input[name="channels"]')) updateYouTubeOptions(); });
function renderChosenMedia() {
  const holder = $('#chosen-media'); if (!holder) return;
  holder.innerHTML = chosenMedia.length ? chosenMedia.map((id) => { const m = data.media.find((x) => Number(x.id) === id); return m ? `<div class="chosen-item">${mediaPreview(m)}<button type="button" class="remove-media" data-unpick="${id}" aria-label="Ta bort ${esc(m.name)}">${icon('close')}</button></div>` : ''; }).join('') : `<button class="upload-drop" type="button" data-action="upload-editor">${icon('image')}<strong>Ge berättelsen en bild</strong><span>Välj en fil eller använd ditt mediabibliotek.</span></button>`;
  document.querySelectorAll('[data-pick]').forEach((el) => { const selected = chosenMedia.includes(Number(el.dataset.pick)); el.classList.toggle('selected', selected); el.setAttribute('aria-pressed', selected); });
}
async function savePost(event) {
  event.preventDefault(); const form = event.target; const fields = new FormData(form); const status = event.submitter?.value || 'draft';
  const buttons = [...form.querySelectorAll('button[type="submit"]')]; buttons.forEach((b) => { b.disabled = true; }); $('#editor-error').textContent = '';
  try {
    await api('save', { id: editorPost?.id, version: editorPost?.version, title: fields.get('title'), caption: fields.get('caption'), channel_ids: fields.getAll('channels'), media_ids: chosenMedia, scheduled_at: fields.get('scheduled_at'), timezone: fields.get('timezone'), visibility: fields.get('visibility'), made_for_kids: fields.get('made_for_kids'), status });
    $('#editor').close(); await refresh(); toast(status === 'draft' ? 'Utkastet är sparat. Fortsätt när du vill.' : data.demo ? 'Inlägget är sparat i demokalendern. Ingen extern publicering sker.' : 'Inlägget är schemalagt.');
  } catch (e) { $('#editor-error').textContent = e.message; } finally { buttons.forEach((b) => { b.disabled = false; }); }
}
async function uploadFiles(forEditor = false) {
  const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp,video/mp4'; input.multiple = true;
  input.addEventListener('change', async () => {
    const files = [...input.files]; if (files.length > 10) { toast('Välj högst 10 filer åt gången.', true); return; }
    for (let i = 0; i < files.length; i++) {
      const file = files[i]; if (file.size > data.upload_limit_mb * 1048576) { toast(`${file.name} överskrider appens filgräns.`, true); continue; }
      toast(`Laddar upp ${i + 1} av ${files.length}: ${file.name}`);
      try {
        const form = new FormData(); form.append('file', file);
        const response = await fetch('api.php?action=upload', { method: 'POST', headers: { 'X-CSRF-Token': data.csrf }, body: form });
        let result; try { result = await response.json(); } catch { throw new Error('Filen kunde inte tas emot. Kontrollera webbhotellets uppladdningsgräns.'); }
        if (!response.ok) throw new Error(result.error || 'Uppladdningen misslyckades.');
        const updated = await api('bootstrap'); data.media = updated.media;
        if (forEditor && chosenMedia.length < 10) chosenMedia.push(Number(result.media.id));
      } catch (e) { toast(e.message, true); return; }
    }
    if (forEditor) { renderChosenMedia(); const picker = $('.picker-grid'); if (picker) picker.innerHTML = data.media.map((m) => `<button class="picker-item ${chosenMedia.includes(Number(m.id)) ? 'selected' : ''}" type="button" data-pick="${m.id}" aria-label="Välj ${esc(m.name)}" aria-pressed="${chosenMedia.includes(Number(m.id))}">${mediaPreview(m)}<span>${esc(m.name)}</span></button>`).join(''); }
    else render(); toast('Ditt material är uppladdat.');
  }); input.click();
}
async function confirmAction(title, text, callback, label = 'Fortsätt') {
  const dialog = $('#confirm-dialog'); dialog.returnValue = ''; dialog.innerHTML = `<form method="dialog"><div class="dialog-header"><h2 id="confirm-title">${esc(title)}</h2><button class="icon-button" value="cancel" aria-label="Stäng">${icon('close')}</button></div><p class="confirm-text">${esc(text)}</p><div class="dialog-footer"><button class="button secondary" value="cancel">Avbryt</button><button class="button primary" value="confirm">${esc(label)}</button></div></form>`; dialog.showModal();
  dialog.addEventListener('close', async () => { if (dialog.returnValue === 'confirm') { try { await callback(); } catch (e) { toast(e.message, true); } } }, { once: true });
}
document.addEventListener('click', async (event) => {
  const target = event.target.closest('button,a'); if (!target) return;
  if (target.dataset.close) { $(`#${target.dataset.close}`).close(); return; }
  if (target.dataset.filter) { filter = target.dataset.filter; render(); return; }
  if (target.dataset.mode) { mode = target.dataset.mode; render(); return; }
  if (target.dataset.day) { openEditor(null, target.dataset.day); return; }
  if (target.dataset.post) { openEditor(data.posts.find((p) => String(p.id) === target.dataset.post)); return; }
  if (target.dataset.copy) { openEditor(data.posts.find((p) => String(p.id) === target.dataset.copy), null, true); return; }
  if (target.dataset.pick || target.dataset.unpick) { const id = Number(target.dataset.pick || target.dataset.unpick); if (chosenMedia.includes(id)) chosenMedia = chosenMedia.filter((x) => x !== id); else if (chosenMedia.length < 10) chosenMedia.push(id); else toast('Välj högst 10 filer.', true); renderChosenMedia(); return; }
  if (target.dataset.connect) { try { const result = await api('connect', { provider: target.dataset.connect }); location.assign(result.url); } catch (e) { toast(e.message, true); } return; }
  if (target.dataset.disconnect) {
    const channel = data.channels.find((c) => String(c.id) === target.dataset.disconnect);
    const youtube = channel?.network === 'youtube';
    await confirmAction(youtube ? 'Koppla från och radera Google-data?' : 'Koppla från kanalen?', youtube ? 'Google-åtkomsten återkallas och kanalens lokala namn, ID, token och publiceringshistorik raderas. Egna inlägg och filer behålls; schemalagda inlägg utan annan kanal återgår till utkast. Videor på YouTube raderas inte. Andra kanaler med samma Google-behörighet kan behöva anslutas igen.' : 'Kanalen kan anslutas igen senare. Publiceringshistoriken behålls.', async () => {
      const result = await api('disconnect', { id: Number(target.dataset.disconnect) });
      await refresh();
      toast(result.deletion_pending ? 'Anslutningen är pausad. Radering har begärts och servern försöker återkalla Google-åtkomsten igen.' : youtube ? 'Google-data har raderats. Videorna på YouTube ligger kvar.' : 'Kanalen är frånkopplad.');
    }, youtube ? 'Koppla från och radera' : 'Koppla från'); return;
  }
  if (target.dataset.cancel) { confirmAction('Avbryt återstående publiceringar?', 'Inlägg som redan har publicerats ligger kvar hos plattformarna.', async () => { await api('cancel', { id: target.dataset.cancel }); $('#editor').close(); await refresh(); toast('Återstående publiceringar har avbrutits.'); }, 'Avbryt publicering'); return; }
  if (target.dataset.reconcile) { openReconcile(Number(target.dataset.reconcile)); return; }
  if (target.dataset.mediaPreview) { const m = data.media.find((x) => String(x.id) === target.dataset.mediaPreview); const dialog = $('#confirm-dialog'); dialog.innerHTML = `<div class="dialog-header"><h2 id="confirm-title">${esc(m.name)}</h2><button class="icon-button" data-close="confirm-dialog" aria-label="Stäng">${icon('close')}</button></div><div class="media-preview-large">${m.mime === 'video/mp4' ? `<video controls src="media.php?id=${m.id}"></video>` : mediaPreview(m)}</div>`; dialog.showModal(); return; }
  switch (target.dataset.action) {
    case 'new': openEditor(); break;
    case 'upload': uploadFiles(); break;
    case 'upload-editor': uploadFiles(true); break;
    case 'previous': anchor.setDate(mode === 'month' ? 1 : anchor.getDate() - 7); if (mode === 'month') anchor.setMonth(anchor.getMonth() - 1); render(); break;
    case 'next': anchor.setDate(mode === 'month' ? 1 : anchor.getDate() + 7); if (mode === 'month') anchor.setMonth(anchor.getMonth() + 1); render(); break;
    case 'today': anchor = new Date(); render(); break;
    case 'logout': if (data.demo) { toast('Du är i en lokal demo. Den kräver ingen inloggning.'); break; } await api('logout', {}); await refresh(); break;
  }
});
function openReconcile(id) {
  const dialog = $('#confirm-dialog');
  dialog.innerHTML = `<form id="reconcile-form"><div class="dialog-header"><h2 id="confirm-title">Stäm av hos plattformen</h2><button type="button" class="icon-button" data-close="confirm-dialog" aria-label="Stäng">${icon('close')}</button></div><div class="reconcile-body"><p>Öppna kanalen och kontrollera om inlägget faktiskt publicerades. Appen skickar inget nytt i det här steget.</p><label class="field-label">Resultat<select name="decision"><option value="published">Inlägget är publicerat</option><option value="failed">Jag har kontrollerat: inget inlägg publicerades</option></select></label><label class="field-label">Plattformens inläggs-ID <span>Behövs om inlägget publicerades</span><input name="remote_id" maxlength="190"></label><div class="form-error" id="reconcile-error"></div></div><div class="dialog-footer"><span></span><button class="button primary" type="submit">Spara avstämning</button></div></form>`; dialog.showModal();
  $('#reconcile-form').addEventListener('submit', async (event) => { event.preventDefault(); try { await api('reconcile', { id, ...Object.fromEntries(new FormData(event.target)) }); dialog.close(); $('#editor').close(); await refresh(); toast('Avstämningen är sparad.'); } catch (e) { $('#reconcile-error').textContent = e.message; } });
}
window.addEventListener('hashchange', () => { view = location.hash.slice(1) || 'calendar'; filter = 'all'; query = ''; render(); });
refresh().catch((error) => { $('#app').innerHTML = `<main class="login-page"><div class="brand"><span class="brand-mark">S</span>socialen.</div><section class="login-card"><h1>Vi behöver ansluta serverdelen.</h1><p>${esc(error.message)}</p><p>Följ installationsguiden i projektets README för databas och konfiguration.</p><button class="button primary" id="reload">Försök igen</button></section></main>`; $('#reload').addEventListener('click', () => location.reload()); });
