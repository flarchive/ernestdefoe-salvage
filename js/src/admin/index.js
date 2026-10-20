import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LinkHealth from './LinkHealth';

const t = (key, params) => app.translator.trans(`ernestdefoe-salvage.admin.${key}`, params);
const key = (name) => `ernestdefoe-salvage.${name}`;

/** Link health first, then the settings. Salvage has no permissions. */
class SalvagePage extends ExtensionPage {
  sections(vnode) {
    const items = super.sections(vnode);

    items.remove('permissions');
    items.setPriority('content', 10);
    items.add(
      'health',
      <div className="ExtensionPage-settings">
        <div className="container">
          <h2 className="ExtensionTitle">{t('status.title')}</h2>
          <LinkHealth />
        </div>
      </div>,
      20
    );

    return items;
  }
}

const toggle = (name) => () => ({
  setting: key(name),
  type: 'boolean',
  label: t(`settings.${name}_label`),
  help: t(`settings.${name}_help`),
});

const number =
  (name, min, max, help = true) =>
  () => ({
    setting: key(name),
    type: 'number',
    min,
    max,
    label: t(`settings.${name}_label`),
    help: help ? t(`settings.${name}_help`) : undefined,
  });

// Callbacks, not values: this module runs before the app has booted.
export const extend = [
  new Admin()
    .page(SalvagePage)
    .setting(toggle('check_links'))
    .setting(toggle('mirror_images'))
    .setting(toggle('use_fof_upload'))
    .setting(toggle('archive'))
    .setting(toggle('auto_archive'))
    .setting(toggle('flag_posts'))
    .setting(number('per_run', 5, 200))
    .setting(number('recheck_days', 1, 365, false))
    .setting(number('max_image_kb', 50, 51200, false))
    .setting(number('daily_mb', 1, 1048576))
    .setting(() => ({
      setting: key('skip_domains'),
      type: 'textarea',
      label: t('settings.skip_domains_label'),
      help: t('settings.skip_domains_help'),
    })),
];
