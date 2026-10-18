(() => {
  const login = document.getElementById('portal-login');
  if (!login) return;
  const mode = document.querySelector('.site-header')?.dataset.siteMode || 'server';
  const form = document.getElementById('login-form');
  const dashboard = document.getElementById('portal-dashboard');
  const loginMessage = document.getElementById('login-message');
  const portalMessage = document.getElementById('portal-message');
  const projects = document.getElementById('portal-projects');
  const demoUrl = new URL(dashboard.dataset.demoProjectsUrl, location.href);
  let csrf = '';
  let generation = 0;
  const heading = dashboard.querySelector('h2');
  heading.tabIndex = -1;
  function closeDashboard() {
    generation++;
    dashboard.hidden = true;
    login.hidden = false;
    projects.replaceChildren();
    (mode === 'demo' ? document.getElementById('open-demo-button') : form.email).focus();
  }

  function message(element, value) {
    element.textContent = value;
    element.hidden = !value;
  }

  async function api(path, options = {}) {
    const response = await fetch(path, { credentials: 'same-origin', cache: 'no-store', ...options });
    const payload = await response.json();
    if (!response.ok) {
      const error = new Error(payload.error || 'request_failed');
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function element(tag, className, content) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (content !== undefined) node.textContent = content;
    return node;
  }

  function renderProject(project) {
    const article = element('article', 'portal-project');
    const top = element('div', 'portal-project-top');
    top.append(element('h3', '', project.title), element('span', 'portal-status', project.status));
    article.append(top);
    if (project.summary) article.append(element('p', 'portal-summary', project.summary));
    article.append(element('p', 'portal-updated', `${dashboard.dataset.updated}: ${new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(new Date(project.updated_at))}`));
    article.append(element('h4', '', dashboard.dataset.files));
    if (!project.files.length) {
      article.append(element('p', 'portal-no-files', dashboard.dataset.noFiles));
    } else {
      const list = element('ul', 'portal-files');
      for (const file of project.files) {
        const item = element('li');
        const link = element('a', '', file.original_name);
        link.href = mode === 'demo' ? new URL(file.path, demoUrl).href : `/api/files/${encodeURIComponent(file.id)}`;
        link.setAttribute('aria-label', `${dashboard.dataset.download}: ${file.original_name}`);
        item.append(link, element('span', '', `${Math.max(1, Math.ceil(file.size / 1024))} ${dashboard.dataset.sizeUnit}`));
        list.append(item);
      }
      article.append(list);
    }
    return article;
  }

  async function showDashboard(email, focus = true) {
    const request = ++generation;
    login.hidden = true;
    dashboard.hidden = false;
    if (focus) heading.focus();
    document.getElementById('portal-email').textContent = email;
    message(portalMessage, dashboard.dataset.loading);
    projects.replaceChildren();
    try {
      const data = mode === 'demo'
        ? await fetch(demoUrl, { cache: 'no-store' }).then((response) => { if (!response.ok) throw new Error('demo_unavailable'); return response.json(); })
        : await api('/api/projects');
      if (request !== generation || dashboard.hidden) return;
      message(portalMessage, data.projects.length ? '' : dashboard.dataset.empty);
      for (const project of data.projects) projects.append(renderProject(project));
    } catch (_) {
      if (request !== generation || dashboard.hidden) return;
      message(portalMessage, dashboard.dataset.error);
    }
  }

  if (mode === 'demo') {
    document.getElementById('open-demo-button').addEventListener('click', () => showDashboard(login.dataset.demoClient));
    const close = document.getElementById('logout-button');
    close.textContent = close.dataset.demoClose;
    close.addEventListener('click', () => {
      closeDashboard();
    });
    return;
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    message(loginMessage, '');
    try {
      const data = await api('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ email: form.email.value, password: form.password.value }),
      });
      csrf = data.csrf;
      form.password.value = '';
      await showDashboard(data.email);
    } catch (error) {
      message(loginMessage, error.status === 429 ? form.dataset.limit : error.status === 401 ? form.dataset.invalid : form.dataset.unavailable);
    } finally {
      button.disabled = false;
    }
  });

  document.getElementById('logout-button').addEventListener('click', async () => {
    generation++;
    try {
      const data = await api('/api/logout', { method: 'POST', headers: { 'X-CSRF-Token': csrf } });
      csrf = data.csrf;
      closeDashboard();
      message(loginMessage, '');
    } catch (_) {
      message(portalMessage, form.dataset.unavailable);
    }
  });

  api('/api/session').then((data) => {
    csrf = data.csrf;
    if (data.authenticated) showDashboard(data.email, false);
  }).catch(() => message(loginMessage, form.dataset.unavailable));
})();
