import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import Icon from 'flarum/common/components/Icon';
import humanTime from 'flarum/common/utils/humanTime';
import ReplaceModal from './ReplaceModal';

const t = (key, params) => app.translator.trans(`ernestdefoe-salvage.admin.${key}`, params);
const VIEWS = ['broken', 'failing', 'decided', 'mirrored', 'ignored', 'skipped', 'all'];
const ERRORS = [
  'http_404',
  'http_410',
  'dns_none',
  'dns',
  'timeout',
  'connect',
  'tls',
  'redirects',
  'too_large',
  'not_image',
  'storage',
  'scheme',
  'credentials',
  'port',
  'host',
  'private_address',
  'own_site',
  'skip_list',
];

/** Only web addresses become links in the admin page; anything else stays text. */
const safeHref = (url) => (typeof url === 'string' && /^https?:\/\//i.test(url) ? url : null);

/** A link that opens outside the admin, or plain text when it is not a web address. */
const externalLink = (url, children) => {
  const href = safeHref(url);
  return href ? (
    <a href={href} target="_blank" rel="noopener noreferrer nofollow">
      {children || url}
    </a>
  ) : (
    <span>{children || url}</span>
  );
};

function errorLabel(link) {
  if (!link.error) return link.httpStatus ? t('errors.http', { code: link.httpStatus }) : null;
  if (ERRORS.includes(link.error)) return t(`errors.${link.error}`);
  const http = /^http_(\d{3})$/.exec(link.error);
  return http ? t('errors.http', { code: http[1] }) : link.error;
}

/**
 * The admin page's status block and link table. Talks to /api/salvage/*,
 * which only admins may call.
 */
export default class LinkHealth extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.tab = 'broken';
    this.kind = '';
    this.q = '';
    this.page = 1;
    this.data = null;
    this.overview = null;
    this.expanded = {};
    this.busy = {};

    this.refresh();
  }

  api(path) {
    return app.forum.attribute('apiUrl') + '/salvage/' + path;
  }

  refresh() {
    this.loadOverview();
    this.load();
  }

  loadOverview() {
    return app.request({ method: 'GET', url: this.api('overview') }).then((overview) => {
      this.overview = overview;
      m.redraw();
    });
  }

  load() {
    this.loading = true;

    const params = { show: this.tab, page: this.page };
    if (this.kind) params.kind = this.kind;
    if (this.q) params.q = this.q;

    return app
      .request({ method: 'GET', url: this.api('links'), params })
      .then((data) => {
        this.data = data;
        this.expanded = {};
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  show(tab) {
    this.tab = tab;
    this.page = 1;
    this.load();
  }

  act(link, action, extra = {}, options = {}) {
    this.busy[link.id] = true;

    return app
      .request({ method: 'POST', url: this.api('links/' + link.id), body: { action, ...extra }, ...options })
      .then((updated) => {
        const i = this.data.links.findIndex((l) => l.id === link.id);
        if (i !== -1) this.data.links[i] = updated;
        this.loadOverview();
      })
      .finally(() => {
        delete this.busy[link.id];
        m.redraw();
      });
  }

  scan(start) {
    return app.request({ method: 'POST', url: this.api('scan'), body: { start } }).then(() => this.loadOverview());
  }

  morePosts(link) {
    const state = (this.expanded[link.id] ||= { posts: [], page: 0, more: true });
    state.loading = true;

    return app
      .request({ method: 'GET', url: this.api(`links/${link.id}/posts`), params: { page: state.page + 1 } })
      .then((data) => {
        state.posts.push(...data.posts);
        state.page++;
        state.more = data.more;
      })
      .finally(() => {
        state.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="SalvageHealth">
        {this.statusBlock()}
        {this.toolbar()}
        {this.table()}
      </div>
    );
  }

  statusBlock() {
    const o = this.overview;
    if (!o) return <LoadingIndicator />;

    const lastRun = o.schedulerLastRun ? new Date(o.schedulerLastRun) : null;
    const stale = lastRun && Date.now() - lastRun.getTime() > 15 * 60 * 1000;
    const docs = (
      <a href="https://docs.flarum.org/scheduler" target="_blank" rel="noopener noreferrer">
        {t('status.scheduler_docs')}
      </a>
    );

    return (
      <div className="SalvageHealth-status">
        {!lastRun ? (
          <div className="Alert Alert--warning">
            {t('status.scheduler_missing')} {docs}
          </div>
        ) : stale ? (
          <div className="Alert Alert--warning">
            {t('status.scheduler_stale', { ago: humanTime(lastRun) })} {docs}
          </div>
        ) : null}

        <div className="SalvageHealth-scan">
          <h3>{t('status.scan_title')}</h3>
          {o.scan ? (
            <p>
              {t('status.scan_running', { done: o.scan.done.toLocaleString(), total: o.scan.total.toLocaleString() })}{' '}
              <Button className="Button Button--link" onclick={() => this.scan(false)}>
                {t('status.scan_stop')}
              </Button>
            </p>
          ) : (
            <>
              <p className="helpText">{t('status.scan_help')}</p>
              <Button className="Button" icon="fas fa-magnifying-glass" onclick={() => this.scan(true)}>
                {t('status.scan_start')}
              </Button>
            </>
          )}
          {o.counts.pending ? <p className="helpText">{t('status.pending', { count: o.counts.pending.toLocaleString() })}</p> : null}
        </div>
      </div>
    );
  }

  toolbar() {
    const counts = this.overview?.counts || {};

    return (
      <div className="SalvageHealth-toolbar">
        <div className="SalvageHealth-views" role="tablist">
          {VIEWS.map((view) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.tab === view}
              className={'Button' + (this.tab === view ? ' Button--primary' : '')}
              onclick={() => this.show(view)}
            >
              {t(`views.${view}`)}
              {counts[view] !== undefined ? <span className="SalvageHealth-count">{counts[view].toLocaleString()}</span> : null}
            </button>
          ))}
        </div>
        <div className="SalvageHealth-filters">
          <Select
            value={this.kind}
            options={{ '': t('links.kind_all'), link: t('links.kind_link'), image: t('links.kind_image') }}
            onchange={(kind) => {
              this.kind = kind;
              this.page = 1;
              this.load();
            }}
          />
          <input
            className="FormControl"
            type="search"
            placeholder={t('links.search_placeholder')}
            value={this.q}
            onchange={(e) => {
              this.q = e.target.value.trim();
              this.page = 1;
              this.load();
            }}
          />
        </div>
      </div>
    );
  }

  table() {
    if (!this.data) return <LoadingIndicator />;

    const { links, total, perPage } = this.data;
    const pages = Math.max(1, Math.ceil(total / perPage));

    if (!links.length) {
      return <p className="SalvageHealth-empty">{this.tab === 'broken' ? t('links.empty_broken') : t('links.empty')}</p>;
    }

    return (
      <div className={'SalvageHealth-list' + (this.loading ? ' is-loading' : '')}>
        <ul>{links.map((link) => this.row(link))}</ul>
        {pages > 1 ? (
          <div className="SalvageHealth-pager">
            <Button className="Button" disabled={this.page <= 1} onclick={() => (this.page--, this.load())}>
              {t('links.previous')}
            </Button>
            <span>{t('links.page', { page: this.page, pages })}</span>
            <Button className="Button" disabled={this.page >= pages} onclick={() => (this.page++, this.load())}>
              {t('links.next')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  row(link) {
    const error = errorLabel(link);
    const base = app.forum.attribute('baseUrl');
    const postHref = (p) => `${base}/d/${p.discussionId}/${p.number}`;
    const expanded = this.expanded[link.id];

    return (
      <li className={`SalvageLink SalvageLink--${link.status}`} key={link.id}>
        <div className="SalvageLink-main">
          <div className="SalvageLink-url">
            {link.kind === 'image' ? <Icon name="far fa-image" className="SalvageLink-kind" title={t('links.image')} /> : null}
            {externalLink(link.url)}
          </div>
          <div className="SalvageLink-meta">
            <span className={`SalvageLink-status SalvageLink-status--${link.status}`}>{t(`status_labels.${link.status}`)}</span>
            {error ? <span>{error}</span> : null}
            {link.failCount && link.status !== 'broken' ? <span>{t('links.fail_count', { count: link.failCount })}</span> : null}
            <span>
              {link.brokenAt
                ? t('links.broken_since', { ago: humanTime(link.brokenAt) })
                : link.checkedAt
                  ? t('links.checked', { ago: humanTime(link.checkedAt) })
                  : t('links.never_checked')}
            </span>
          </div>
          {this.decision(link)}
          {link.firstPost ? (
            <div className="SalvageLink-posts">
              <a href={postHref(link.firstPost)} target="_blank" rel="noopener">
                {t('links.in_post', { title: link.firstPost.title, number: link.firstPost.number })}
              </a>
              {link.firstPost.hidden ? <span className="SalvageLink-hidden">{t('links.hidden_post')}</span> : null}
              {link.posts > 1 && !expanded ? (
                <Button className="Button Button--link" onclick={() => this.morePosts(link)}>
                  {t('links.more_posts', { count: link.posts - 1 })}
                </Button>
              ) : null}
            </div>
          ) : null}
          {expanded ? (
            <ul className="SalvageLink-postList">
              {expanded.posts.map((p) => (
                <li>
                  <a href={postHref(p)} target="_blank" rel="noopener">
                    {t('links.in_post', { title: p.title, number: p.number })}
                  </a>
                  {p.hidden ? <span className="SalvageLink-hidden">{t('links.hidden_post')}</span> : null}
                </li>
              ))}
              {expanded.more ? (
                <li>
                  <Button className="Button Button--link" loading={expanded.loading} onclick={() => this.morePosts(link)}>
                    {t('links.load_more')}
                  </Button>
                </li>
              ) : null}
            </ul>
          ) : null}
        </div>
        <div className="SalvageLink-actions">{this.actions(link)}</div>
      </li>
    );
  }

  decision(link) {
    if (link.applying) return <div className="SalvageLink-decision">{t('links.applying')}</div>;

    const note = {
      replace: () => t('links.replaced_with', { url: externalLink(link.replacement) }),
      restore: () => t('links.applying'),
      unlink: () => t('links.unlinked'),
      mirror: () => t('links.mirrored', { url: externalLink(link.replacement) }),
      ignore: () => t('links.ignored_note'),
    }[link.action];

    if (note) return <div className="SalvageLink-decision">{note()}</div>;

    if (link.status === 'broken' && link.archiveChecked) {
      return (
        <div className="SalvageLink-decision">{link.archiveUrl ? externalLink(link.archiveUrl, t('links.archive_copy')) : t('links.no_archive')}</div>
      );
    }

    return null;
  }

  actions(link) {
    const busy = !!this.busy[link.id] || link.applying;
    const button = (action, icon, onclick) => (
      <Button className="Button Button--icon-text" icon={icon} disabled={busy} onclick={onclick || (() => this.act(link, action))}>
        {t(`actions.${action}`)}
      </Button>
    );
    const items = [];

    if (link.action === null || link.action === 'replace' || link.action === 'ignore') {
      items.push(
        button('replace', 'fas fa-pen', () =>
          app.modal.show(ReplaceModal, {
            link,
            onsubmit: (replacement) => this.act(link, 'replace', { replacement }, { errorHandler: (e) => Promise.reject(e) }),
          })
        )
      );

      if (link.archiveUrl && link.replacement !== link.archiveUrl) items.push(button('archive', 'fas fa-clock-rotate-left'));
    }

    if (link.action !== 'unlink' && link.action !== 'mirror' && link.action !== 'restore') {
      items.push(
        button('unlink', 'fas fa-link-slash', () => {
          if (confirm(t('actions.unlink_confirm'))) this.act(link, 'unlink');
        })
      );
    }

    if (link.action === null) items.push(button('ignore', 'fas fa-eye-slash'));
    if (link.action === 'replace' || link.action === 'mirror' || link.action === 'ignore') items.push(button('restore', 'fas fa-rotate-left'));
    if (link.action === null) items.push(button('recheck', 'fas fa-rotate'));
    if (link.canMirror && link.status !== 'skipped') items.push(button('mirror', 'fas fa-images'));

    return items;
  }
}
