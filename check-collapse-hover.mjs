import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const DIR = 'D:/laragon/www/v3-cms/admin';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const shell = fs.readFileSync(`${DIR}/index.html`, 'utf8');
const links = [...shell.matchAll(/<link[^>]*rel="stylesheet"[^>]*>/g)].map((m) => m[0]).join('\n');
const rail = (shell.match(/<aside class="tyro-rail"[\s\S]*?<\/aside>/) || [])[0];
const navbar = (shell.match(/<nav[\s\S]*?id="layout-navbar">[\s\S]*?<\/nav>/) || [])[0];
const user = fs.readFileSync(`${DIR}/user.html`, 'utf8');

const NAMES = ['GMS Website Editor', 'Pages', 'Activity Logs', 'Sustainability Compliance Reports',
  'Health and Safety', 'Graphs', 'Careers', 'Company History', 'Board of directors', 'Linkedin',
  'Fleet', 'Result & Presentation', 'Corporate policy', 'Appearances', 'File Manager',
  'Contact List', 'Email Template', 'Widgets', 'Module', 'Form Builder'];
const menuItems = NAMES.map((n, i) => i === 0
  ? `<li class="menu-item menu-item-has-children"><a href="javascript:;" class="menu-link menu-toggle"><i class="menu-icon tf-icons ri-settings-3-line"></i><div>${n}</div></a><ul class="menu-sub"><li class="menu-item"><a href="?P=x&amp;M=x" class="menu-link waves-effect"><div>Sub</div></a></li></ul></li>`
  : `<li class="menu-item"><a href="?P=p&amp;M=x" class="menu-link"><i class="menu-icon tf-icons ri-settings-3-line"></i><div>${n}</div></a></li>`
).join('\n');

// index.js builds the aside inner at runtime; we reproduce a collapsed-compatible aside
// with app-brand, the injected menu-icon sibling, and menu items. The menu-icon is the
// direct child of aside that Vuexy's collapsed rules target for hover-expand.
const appBrandBlock = `<div class="app-brand demo">
      <a href="index.html" class="app-brand-link">
        <span class="app-brand-logo demo">
          <img src="assets/images/admin_logo.png" />
        </span>
      </a>
    </div>
    <i class="menu-icon" aria-hidden="true"></i>`;

const collapsedOffHtml = `<!DOCTYPE html>
<html class="light-style layout-menu-fixed layout-navbar-fixed tyroops-theme layout-menu-collapsed" data-style="light">
<head>${links}</head>
<body>
${rail}
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
    ${appBrandBlock}
    <div class="menu-inner-shadow"></div>
    <ul id="menus" class="menu-inner py-1">${menuItems}</ul>
  </aside>
  <div class="layout-page">
    ${navbar.replace(/(<li id="user"[^>]*>)/, `$1${user}`)}
    <div id="content" class="content-wrapper"><div style="height:2000px"></div></div>
  </div>
</div></div>
<script src="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script>
addEventListener('load', () => {
  try { new PerfectScrollbar(document.querySelector('#layout-menu .menu-inner')); } catch (e) {}
  const aside = document.getElementById('layout-menu');
  const icon = aside.querySelector('.layout-menu > .menu-icon');
  const iconAny = aside.querySelector('.menu-icon');
  document.documentElement.setAttribute('data-p', encodeURIComponent(JSON.stringify({
    collapsed: aside.classList.contains('layout-menu-collapsed'),
    hoverActive: aside.classList.contains('layout-menu-hover'),
    asideWidthPx: Math.round(aside.getBoundingClientRect().width),
    directChildIconInDOM: !!icon,
    directChildIconOpacity: icon ? getComputedStyle(icon).opacity : 'MISSING',
    anyIconInDOM: !!iconAny,
    anyIconOpacity: iconAny ? getComputedStyle(iconAny).opacity : 'MISSING',
  })));
});
<\/script>
</body></html>`;

const collapsedOnHtml = collapsedOffHtml.replace('layout-menu-collapsed"', 'layout-menu-collapsed layout-menu-hover"');

const swap = async (html, label) => {
  fs.writeFileSync(`${DIR}/_ch.html`, html);
  let out = '';
  try {
    out = execFileSync(CHROME, [
      '--headless=new', '--disable-gpu', '--window-size=1440,900',
      '--allow-file-access-from-files', '--virtual-time-budget=4000', '--dump-dom',
      `file:///${DIR}/_ch.html`,
    ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 60000 });
  } catch (e) { out = String(e.stdout ?? ''); }
  fs.unlinkSync(`${DIR}/_ch.html`);
  const m = out.match(/data-p="([^"]*)"/);
  const p = m ? JSON.parse(decodeURIComponent(m[1])) : null;
  console.log(`${label}: ${JSON.stringify(p)}`);
  return p;
};

await swap(collapsedOffHtml, 'collapsed no-hover');
await swap(collapsedOnHtml, 'collapsed hover-active');
