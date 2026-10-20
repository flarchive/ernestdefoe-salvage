import app from 'flarum/forum/app';
import Post from 'flarum/forum/components/Post';
import { override } from 'flarum/common/extend';

// flarum/flags asks each flag type how its flag reads; ours names the
// broken links. -10: after Flags itself has added flagReason.
app.initializers.add(
  'ernestdefoe-salvage',
  () => {
    if (!Post.prototype.flagReason) return;

    override(Post.prototype, 'flagReason', function (original, flag) {
      if (flag.type() !== 'salvage') return original(flag);

      return [app.translator.trans('ernestdefoe-salvage.forum.flag_reason'), <span className="Post-flagged-detail">{flag.reasonDetail()}</span>];
    });
  },
  -10
);
