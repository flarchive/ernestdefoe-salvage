import app from 'flarum/admin/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';

const t = (key, params) => app.translator.trans(`ernestdefoe-salvage.admin.replace_modal.${key}`, params);

/** Asks for the address a broken link should point to instead. */
export default class ReplaceModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    this.url = Stream(this.attrs.link.replacement || '');
  }

  className() {
    return 'SalvageReplaceModal Modal--small';
  }

  title() {
    return t('title');
  }

  content() {
    return (
      <div className="Modal-body">
        <p className="helpText">{t('help')}</p>
        <p className="SalvageReplaceModal-old">{this.attrs.link.url}</p>
        <div className="Form-group">
          <label for="salvage-replacement">{t('label')}</label>
          <input id="salvage-replacement" name="action" className="FormControl" type="url" required bidi={this.url} placeholder="https://" />
        </div>
        <Button type="submit" className="Button Button--primary" loading={this.loading}>
          {t('submit')}
        </Button>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();
    this.loading = true;

    this.attrs
      .onsubmit(this.url().trim())
      .then(() => this.hide())
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }
}
