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

const expandedHtml = `<!DOCTYPE html>
<html class="light-style layout-menu-fixed layout-navbar-fixed tyroops-theme" data-style="light">
<head>${links}</head>
<body>
${rail}
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
    <div class="app-brand demo"><a href="#" class="app-brand-link"><span class="app-brand-logo demo"><img src="assets/images/admin_logo.png"></span></span></a></div>
    <div class="menu-inner-shadow"></div>
    <ul id="menus" class="menu-inner py-1">${menuItems}</ul>
  </aside>
  <div class="layout-page">
    ${navbar.replace(/(<li id="user"[^>]*>)/, `$1${user}`)}
    <div id="content" class="content-wrapper"><div style="height:2000px"></div></div>
  </div>
</div></div>
<style>html.tyroops-theme .layout-menu-toggle a:hover{background-color:transparent !important}
@media (min-width:1200px){html.tyroops-theme:not(.layout-menu-collapsed) .layout-menu .menu-vertical .menu-inner > .menu-item > .menu-link div:not(.menu-block),\nhtml.tyroops-theme:not(.layout-menu-collapsed) .layout-menu.menu-vertical .menu-inner > .menu-item div:not(.menu-block){overflow:visible !important;opacity:1 !important;white-space:normal !important}}\n</style>
<script src="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script>
addEventListener('load', () => {
  const p = {};
  try { new PerfectScrollbar(document.querySelector('#layout-menu .menu-inner')); } catch (e) {}
  const firstToggle = document.querySelector('.menu-item .menu-toggle');
  const link = firstToggle || (document.querySelector('.menu-item')?.querySelector('.menu-link'));
  if (link) {
    const r = link.getBoundingClientRect();
    p.toggleWidth = Math.round(r.width);
    p.toggleIconVisible = !!link.querySelector('.menu-icon') && link.querySelector('.menu-icon').getBoundingClientRect().width > 0;
    p.toggleIconClass = link.querySelector('.menu-icon')?.className;
    // the text div Vuexy's collapsed rule targets (any child div of menu-link
    // that is not explicitly preserved)
    const children = [...link.querySelectorAll('div')];
    const textDiv = children.find(d => !d.classList.contains('menu-block'));
    p.textContentDivFound = !!textDiv;
    p.textContentDivText = textDiv ? textDiv.textContent?.trim() : null;
    p.textContentDivDisplay = textDiv ? getComputedStyle(textDiv).display : 'MISSING';
    p.textContentDivOverflow = textDiv ? getComputedStyle(textDiv).overflow : 'MISSING';
    p.textContentDivOpacity = textDiv ? getComputedStyle(textDiv).opacity : 'MISSING';
    p.textContentDivWhiteSpace = textDiv ? getComputedStyle(textDiv).whiteSpace : 'MISSING';
  }
  p.htmlClasses = document.documentElement.className;
  document.documentElement.setAttribute('data-p', encodeURIComponent(JSON.stringify(p)));
});
<\/script>
</body></html>`;

const collapsedHtml = expandedHtml.replace('tyroops-theme"', 'tyroops-theme layout-menu-collapsed"');
const scenarios = [['expanded', expandedHtml], ['collapsed', collapsedHtml]];

for (const [label, html] of scenarios) {
  fs.writeFileSync(`${DIR}/_ic.html`, html);
  let out = '';
  try {
    out = execFileSync(CHROME, [
      '--headless=new', '--disable-gpu', '--window-size=1440,900',
      '--allow-file-access-from-files', '--virtual-time-budget=4000', '--dump-dom',
      `file:///${DIR}/_ic.html`,
    ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 60000 });
  } catch (e) { out = String(e.stdout ?? ''); }
  fs.unlinkSync(`${DIR}/_ic.html`);
  const m = out.match(/data-p="([^"]*)"/);
  const p = m ? JSON.parse(decodeURIComponent(m[1])) : null;
  console.log(`${label}: ${JSON.stringify(p)}`);
}
