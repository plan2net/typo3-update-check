import { loadData } from './data';
import { initUi } from './ui';
import { strings } from './i18n';

loadData()
  .then((data) => initUi(document, data))
  .catch((err) => {
    const lang = new URLSearchParams(location.search).get('lang') === 'de' ? 'de' : 'en';
    const result = document.getElementById('result');
    if (result) result.textContent = `${strings(lang).ui.loadError} ${String(err)}`;
  });
