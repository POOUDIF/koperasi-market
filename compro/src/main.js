import './style.css';

// Menu navigasi seluler — satu-satunya JavaScript di situs profil.
const toggle = document.querySelector('[data-nav-toggle]');
const menu = document.querySelector('[data-nav-menu]');
if (toggle && menu) {
  toggle.addEventListener('click', () => {
    const open = menu.hasAttribute('hidden');
    menu.toggleAttribute('hidden', !open);
    toggle.setAttribute('aria-expanded', String(open));
  });
}

const year = document.querySelector('[data-year]');
if (year) year.textContent = String(new Date().getFullYear());
