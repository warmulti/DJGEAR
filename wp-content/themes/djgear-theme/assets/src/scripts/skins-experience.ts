import '../styles/13-custom-skins.css';
import { initSkinsConfigurator } from './skins-configurator';
import { initSkinsPortal } from './skins-portal';

function mountAll(): void {
  document.querySelectorAll<HTMLElement>('.djgear-skin-configurator').forEach((node) => {
    initSkinsConfigurator(node);
  });

  document.querySelectorAll<HTMLElement>('.djgear-my-skins').forEach((node) => {
    initSkinsPortal(node);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAll, { once: true });
} else {
  mountAll();
}
