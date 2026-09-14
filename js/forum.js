'use strict';

var qrModal = require('./qrModal');

(function () {
  app.initializers.add('linkrobins/referral', function () {
    var extend = flarum.reg.get('core', 'common/extend').extend;
    var override = flarum.reg.get('core', 'common/extend').override;
    var Model = flarum.reg.get('core', 'common/Model');
    var UserPage = flarum.reg.get('core', 'forum/components/UserPage');
    var LinkButton = flarum.reg.get('core', 'common/components/LinkButton');
    var Link = flarum.reg.get('core', 'common/components/Link');
    var Button = flarum.reg.get('core', 'common/components/Button');
    var LoadingIndicator = flarum.reg.get('core', 'common/components/LoadingIndicator');
    var UserPageResolver = flarum.reg.get('core', 'forum/resolvers/UserPageResolver');

    var UserModel = app.store.models['users'];
    if (UserModel) {
      UserModel.prototype.referralCount = Model.attribute('referralCount');
      UserModel.prototype.referralEligible = Model.attribute('referralEligible');
      UserModel.prototype.referredBy = Model.hasOne('referredBy');
      UserModel.prototype.referredUsers = Model.hasMany('referredUsers');
    }

    (function () {
      try {
        var urlCode = (new URLSearchParams(window.location.search).get('ref') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (urlCode) {
          localStorage.setItem('referral_pending_code', urlCode);
        }
      } catch (e) {
        // Best-effort storage write (private mode / disabled storage); safe to ignore.
      }
    })();

    // The link a new member follows: the private-facade sign-up page when
    // that extension provides one, else the forum root (the ?ref capture
    // above runs on any page).
    function inviteUrl(code) {
      var base = app.routes['sycho-private-facade.signup'] ? app.route('sycho-private-facade.signup') : '/';
      return window.location.origin + base + '?ref=' + encodeURIComponent(code);
    }

    function getRefFromUrl() {
      try {
        var urlCode = (new URLSearchParams(window.location.search).get('ref') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (urlCode) return urlCode;
        return (localStorage.getItem('referral_pending_code') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
      } catch (e) {
        return '';
      }
    }

    function isEnabled(value) {
      return value === true || value === 1 || value === '1';
    }

    function safeInviteLink(value) {
      var raw = String(value || '').trim();
      if (!raw) return '';

      if (raw.charAt(0) === '/' && raw.charAt(1) !== '/') {
        return raw;
      }

      try {
        var url = new URL(raw, window.location.origin);
        if (url.protocol === 'http:' || url.protocol === 'https:') {
          return url.href;
        }
      } catch (e) {
        // Ignore malformed admin input instead of rendering an unsafe href.
      }

      return '';
    }

    function isExternalInviteLink(url) {
      try {
        return new URL(url, window.location.origin).origin !== window.location.origin;
      } catch (e) {
        return false;
      }
    }

    function renderGetInvitePrompt() {
      var enabled = app.forum && app.forum.attribute('referralGetInviteEnabled');
      var url = safeInviteLink(app.forum && app.forum.attribute('referralGetInviteUrl'));
      if (!isEnabled(enabled) || !url) return null;
      var external = isExternalInviteLink(url);

      return m(
        'div',
        { className: 'Alert get-invite-code-text ReferralSignup-getInvite' },
        m('span', app.translator.trans('linkrobins-referral.forum.sign_up.get_invite_text')),
        m(
          'a',
          {
            href: url,
            target: external ? '_blank' : null,
            rel: external ? 'noopener noreferrer' : null,
          },
          app.translator.trans('linkrobins-referral.forum.sign_up.get_invite_link')
        )
      );
    }

    function extendSignUpModal(SignUpModal) {
      if (!SignUpModal || SignUpModal._referralExtended) return;
      SignUpModal._referralExtended = true;

      extend(SignUpModal.prototype, 'fields', function (items) {
        var self = this;
        var required = app.forum && app.forum.attribute('referralRequired');
        if (self._inviteCode === undefined) self._inviteCode = getRefFromUrl();

        items.add(
          'inviteCode',
          m(
            'div',
            { className: 'Form-group' },
            m(
              'label',
              required
                ? app.translator.trans('linkrobins-referral.forum.sign_up.invite_code_label_required')
                : app.translator.trans('linkrobins-referral.forum.sign_up.invite_code_label')
            ),
            m('input', {
              className: 'FormControl ReferralSignup-codeInput',
              name: 'inviteCode',
              type: 'text',
              placeholder: app.translator.trans('linkrobins-referral.forum.sign_up.invite_code_placeholder'),
              value: self._inviteCode,
              oninput: function (e) {
                self._inviteCode = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                e.target.value = self._inviteCode;
              },
              required: required || false,
            }),
            renderGetInvitePrompt()
          ),
          5
        );
      });

      override(SignUpModal.prototype, 'onsubmit', function (original, e) {
        var code = this._inviteCode && this._inviteCode.trim();
        // Only mark the cookie Secure over HTTPS so it isn't dropped on
        // plain-HTTP dev forums, but is never sent in cleartext on HTTPS.
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        if (code) {
          var expires = new Date(Date.now() + 10 * 60 * 1000).toUTCString();
          document.cookie = 'referral_code=' + encodeURIComponent(code) + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
        } else {
          document.cookie = 'referral_code=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax' + secure;
        }
        try {
          localStorage.removeItem('referral_pending_code');
        } catch (e) {}
        return original(e);
      });
    }

    var SignUpModalNow = flarum.reg.checkModule && flarum.reg.checkModule('core', 'forum/components/SignUpModal');
    if (SignUpModalNow) extendSignUpModal(SignUpModalNow);
    flarum.reg.onLoad('core', 'forum/components/SignUpModal', extendSignUpModal);

    var SignUpSectionNow = flarum.reg.checkModule && flarum.reg.checkModule('sycho-private-facade', 'forum/components/SignUpSection');
    if (SignUpSectionNow) extendSignUpModal(SignUpSectionNow);
    flarum.reg.onLoad('sycho-private-facade', 'forum/components/SignUpSection', extendSignUpModal);

    class ReferralsPage extends UserPage {
      oninit(vnode) {
        super.oninit(vnode);
        this.codes = null;
        this.codesLoading = false;
        this.action = null;
        this.meta = null;
        this.loadUser(m.route.param('username'));
      }

      loadCodes() {
        if (this.codesLoading || this.codes !== null) return;

        this.codesLoading = true;
        var apiUrl = (app.forum && app.forum.attribute('apiUrl')) || '/api';
        app
          .request({ method: 'GET', url: apiUrl + '/referral/my-codes' })
          .then((res) => {
            this.codes = (res && res.data) || [];
            this.meta = (res && res.meta) || {};
            this.codesLoading = false;
            m.redraw();
          })
          .catch(() => {
            this.codes = [];
            this.meta = {};
            this.codesLoading = false;
            m.redraw();
          });
      }

      runAction(action, url) {
        if (this.action) return;

        this.action = action;
        var apiUrl = (app.forum && app.forum.attribute('apiUrl')) || '/api';
        app
          .request({ method: 'POST', url: apiUrl + url })
          .then(() => {
            this.codes = null;
            this.meta = null;
            this.action = null;
            m.redraw();
          })
          .catch(() => {
            this.action = null;
            m.redraw();
          });
      }

      renderCode(code) {
        var channel = code.channel || 'legacy';
        var unavailable = code.used || code.expired;
        var channelKey = channel === 'legacy' ? 'group' : channel;
        var channelLabel = app.translator.trans('linkrobins-referral.forum.profile.channel_' + channelKey);
        var statusKey = code.used ? 'used' : code.expired ? 'expired' : 'available';

        return m(
          'div',
          { className: 'ReferralProfile-codeTableRow' },
          m(
            'div',
            { className: 'ReferralProfile-codeTableCell ReferralProfile-codeTableCell--code' },
            m('span', { className: 'ReferralProfile-cellLabel' }, app.translator.trans('linkrobins-referral.forum.profile.table_code')),
            m('span', { className: 'ReferralProfile-code' }, code.code)
          ),
          m(
            'div',
            { className: 'ReferralProfile-codeTableCell' },
            m('span', { className: 'ReferralProfile-cellLabel' }, app.translator.trans('linkrobins-referral.forum.profile.table_source')),
            m('span', { className: 'ReferralProfile-badge' }, channelLabel)
          ),
          m(
            'div',
            { className: 'ReferralProfile-codeTableCell' },
            m('span', { className: 'ReferralProfile-cellLabel' }, app.translator.trans('linkrobins-referral.forum.profile.table_status')),
            m(
              'span',
              { className: 'ReferralProfile-status ReferralProfile-status--' + statusKey },
              app.translator.trans('linkrobins-referral.forum.profile.' + statusKey)
            )
          ),
          m(
            'div',
            { className: 'ReferralProfile-codeTableCell ReferralProfile-codeTableCell--details' },
            m('span', { className: 'ReferralProfile-cellLabel' }, app.translator.trans('linkrobins-referral.forum.profile.table_validity')),
            m(
              'span',
              null,
              code.expiresAt
                ? app.translator.trans('linkrobins-referral.forum.profile.expires_at', {
                    date: new Date(code.expiresAt).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai' }),
                  })
                : app.translator.trans('linkrobins-referral.forum.profile.no_expiry')
            ),
            m(
              'span',
              { className: 'ReferralProfile-codeUses' },
              app.translator.trans('linkrobins-referral.forum.profile.uses', { count: code.uses || 0 })
            )
          ),
          m(
            'div',
            { className: 'ReferralProfile-codeTableCell ReferralProfile-codeTableCell--actions' },
            m('span', { className: 'ReferralProfile-cellLabel' }, app.translator.trans('linkrobins-referral.forum.profile.table_actions')),
            !unavailable &&
              m(
                'div',
                { className: 'ReferralProfile-codeActions' },
                m(
                  Button,
                  {
                    className: 'Button Button--default',
                    icon: 'fas fa-copy',
                    title: app.translator.trans('linkrobins-referral.forum.profile.copy'),
                    onclick: function () {
                      navigator.clipboard && navigator.clipboard.writeText(code.code);
                    },
                  },
                  app.translator.trans('linkrobins-referral.forum.profile.copy')
                ),
                m(
                  Button,
                  {
                    className: 'Button Button--default',
                    icon: 'fas fa-link',
                    title: app.translator.trans('linkrobins-referral.forum.profile.copy_link'),
                    onclick: function () {
                      navigator.clipboard && navigator.clipboard.writeText(inviteUrl(code.code));
                    },
                  },
                  app.translator.trans('linkrobins-referral.forum.profile.copy_link')
                ),
                m(
                  Button,
                  {
                    className: 'Button Button--default',
                    icon: 'fas fa-qrcode',
                    title: app.translator.trans('linkrobins-referral.forum.profile.qr_button'),
                    onclick: function () {
                      qrModal.show(inviteUrl(code.code), code.code);
                    },
                  },
                  app.translator.trans('linkrobins-referral.forum.profile.qr_button')
                )
              )
          )
        );
      }

      content() {
        const user = this.user;
        if (!user) return m(LoadingIndicator);

        const isOwn = app.session && app.session.user && app.session.user.id() === user.id();
        const count = user.referralCount ? user.referralCount() : 0;
        if (isOwn && this.codes === null) {
          this.loadCodes();
          return m(
            'div',
            { className: 'ReferralProfile' },
            m('h3', { className: 'ReferralProfile-heading' }, app.translator.trans('linkrobins-referral.forum.profile.invite_code_title')),
            m(LoadingIndicator, { display: 'block', size: 'small' })
          );
        }
        var entitlement = (this.meta && this.meta.entitlement) || {};
        var purchase = (this.meta && this.meta.purchase) || {};
        var eligible = entitlement.eligible;

        return m(
          'div',
          { className: 'ReferralProfile' },
          isOwn &&
            m(
              'div',
              { className: 'ReferralProfile-section' },
              m('h3', { className: 'ReferralProfile-heading' }, app.translator.trans('linkrobins-referral.forum.profile.invite_code_title')),
              m('p', { className: 'ReferralProfile-help' }, app.translator.trans('linkrobins-referral.forum.profile.invite_code_help')),
              eligible
                ? m(
                    'div',
                    { className: 'ReferralProfile-summary' },
                    m(
                      'span',
                      null,
                      app.translator.trans('linkrobins-referral.forum.profile.group_quota', {
                        current: entitlement.activeCount || 0,
                        max: entitlement.maxQuantity || 0,
                      })
                    ),
                    m(
                      Button,
                      {
                        className: 'Button Button--primary',
                        icon: 'fas fa-plus',
                        loading: this.action === 'generate',
                        disabled: this.action !== null || entitlement.remaining <= 0,
                        onclick: () => this.runAction('generate', '/referral/my-codes/generate'),
                      },
                      app.translator.trans('linkrobins-referral.forum.profile.generate')
                    )
                  )
                : m('p', { className: 'ReferralProfile-note' }, app.translator.trans('linkrobins-referral.forum.profile.not_eligible')),
              purchase.enabled &&
                m(
                  'div',
                  { className: 'ReferralProfile-purchase' },
                  m(
                    'span',
                    null,
                    app.translator.trans('linkrobins-referral.forum.profile.purchase_summary', {
                      price: purchase.price,
                      currency: purchase.currencyName,
                    })
                  ),
                  purchase.dailyLimit > 0 &&
                    m(
                      'span',
                      null,
                      app.translator.trans('linkrobins-referral.forum.profile.daily_remaining', { remaining: purchase.remainingToday })
                    ),
                  m(
                    Button,
                    {
                      className: 'Button Button--default',
                      icon: 'fas fa-coins',
                      loading: this.action === 'purchase',
                      disabled:
                        this.action !== null ||
                        !purchase.pointsAvailable ||
                        purchase.balance < purchase.price ||
                        (purchase.dailyLimit > 0 && purchase.remainingToday <= 0),
                      onclick: () => this.runAction('purchase', '/referral/my-codes/purchase'),
                    },
                    app.translator.trans('linkrobins-referral.forum.profile.purchase')
                  )
                )
            ),
          isOwn &&
            m(
              'div',
              { className: 'ReferralProfile-section' },
              m('h3', { className: 'ReferralProfile-heading' }, app.translator.trans('linkrobins-referral.forum.profile.my_codes')),
              this.codesLoading && m(LoadingIndicator, { display: 'inline', size: 'small' }),
              !this.codesLoading && this.codes && this.codes.length
                ? m(
                    'div',
                    { className: 'ReferralProfile-codeTableCard' },
                    m(
                      'div',
                      { className: 'ReferralProfile-codeTableHeader' },
                      m('span', app.translator.trans('linkrobins-referral.forum.profile.table_code')),
                      m('span', app.translator.trans('linkrobins-referral.forum.profile.table_source')),
                      m('span', app.translator.trans('linkrobins-referral.forum.profile.table_status')),
                      m('span', app.translator.trans('linkrobins-referral.forum.profile.table_validity')),
                      m('span', app.translator.trans('linkrobins-referral.forum.profile.table_actions'))
                    ),
                    this.codes.map((code) => this.renderCode(code))
                  )
                : !this.codesLoading &&
                    m('p', { className: 'ReferralProfile-empty' }, app.translator.trans('linkrobins-referral.forum.profile.no_codes'))
            ),
          m(
            'div',
            { className: 'ReferralProfile-total' },
            m('h3', { className: 'ReferralProfile-totalHeading' }, app.translator.trans('linkrobins-referral.forum.profile.total_referrals')),
            m('p', { className: 'ReferralProfile-count' }, count),
            count === 0 && m('p', { className: 'ReferralProfile-empty' }, app.translator.trans('linkrobins-referral.forum.profile.no_referrals'))
          )
        );
      }
    }

    app.routes['user.referrals'] = {
      path: '/u/:username/referrals',
      component: ReferralsPage,
      resolverClass: UserPageResolver,
    };

    extend(UserPage.prototype, 'navItems', function (items) {
      if (!this.user) return;
      const count = this.user.referralCount ? this.user.referralCount() : 0;
      items.add(
        'referrals',
        m(
          LinkButton,
          {
            href: app.route('user.referrals', { username: this.user.username() }),
            icon: 'fas fa-user-check',
          },
          app.translator.trans('linkrobins-referral.forum.profile.tab'),
          count > 0 &&
            m(
              'span',
              {
                className: 'Button-badge',
              },
              count
            )
        ),
        10
      );
    });

    // ---- "Someone joined with your invite code" notification ------------
    var NOTIFICATION_TYPE = 'linkrobinsReferralRegistered';

    function installNotificationComponent(Notification) {
      if (!Notification || Notification._referralExtended) return;
      Notification._referralExtended = true;

      class ReferralRegisteredNotification extends Notification {
        icon() {
          return 'fas fa-user-check';
        }
        href() {
          var subject = this.attrs.notification.subject();
          return subject ? app.route('user', { username: subject.slug ? subject.slug() : subject.username() }) : '#';
        }
        content() {
          var from = this.attrs.notification.fromUser && this.attrs.notification.fromUser();
          var name = from && from.displayName ? from.displayName() : app.translator.trans('linkrobins-referral.forum.notifications.someone');
          return app.translator.trans('linkrobins-referral.forum.notifications.registered_text', { name: name });
        }
        excerpt() {
          return '';
        }
      }

      app.notificationComponents[NOTIFICATION_TYPE] = ReferralRegisteredNotification;
    }

    var NotificationNow = flarum.reg.checkModule && flarum.reg.checkModule('core', 'forum/components/Notification');
    if (NotificationNow) installNotificationComponent(NotificationNow);
    flarum.reg.onLoad('core', 'forum/components/Notification', installNotificationComponent);

    // NotificationGrid lives in a lazily-loaded chunk, so it isn't in the
    // registry at init time. The string-path form of extend() defers
    // resolution until the module actually loads.
    extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items) {
      items.add(NOTIFICATION_TYPE, {
        name: NOTIFICATION_TYPE,
        icon: 'fas fa-user-check',
        label: app.translator.trans('linkrobins-referral.forum.settings.notify_registered_label'),
      });
    });

    // Core's NotificationList.content groups notifications by discussion and
    // lumps anything not tied to one (like this type) into a neutral group
    // labelled with the forum title. Core exposes no per-type or per-group
    // hook, so content() is reimplemented here to route referral notifications
    // into their own "Referrals" group while mirroring core's grouping for
    // everything else.
    //
    // Fully overriding a core render method is inherently coupled to core's
    // internals, so two guards keep it safe:
    //   1. The logic below mirrors core's NotificationList.content as of
    //      Flarum 2.0.0-rc.5 (flarum/core: forum/components/NotificationList).
    //      When bumping core, re-check that method and reconcile this copy.
    //   2. The whole body runs inside a try/catch that falls back to core's
    //      original content() on any error, so a future core refactor degrades
    //      to plain (un-relabelled) notification rendering rather than breaking
    //      the entire notifications page. RegistrationReferralTest locks the
    //      serialized contract this override reads (contentType + subject).
    function installNotificationGrouping(NotificationList) {
      if (!NotificationList || NotificationList._referralExtended) return;
      NotificationList._referralExtended = true;

      override(NotificationList.prototype, 'content', function (original, state) {
        try {
          if (state.isLoading() || !state.hasItems()) return null;

          var HeaderListGroup = flarum.reg.get('core', 'forum/components/HeaderListGroup');
          var NotificationType = flarum.reg.get('core', 'forum/components/NotificationType');
          var Discussion = flarum.reg.get('core', 'common/models/Discussion');
          var listItems = flarum.reg.get('core', 'common/helpers/listItems');

          return state.getPages().map(function (page) {
            var groups = [];
            var byKey = {};

            page.items.forEach(function (notification) {
              var subject = notification.subject();
              if (typeof subject === 'undefined') return;

              var contentType = notification.contentType && notification.contentType();
              var isReferral = contentType === NOTIFICATION_TYPE;

              // Mirror core's discussion resolution for everything else.
              var discussion = null;
              if (!isReferral) {
                if (Discussion && subject instanceof Discussion) discussion = subject;
                else if (subject && subject.discussion) discussion = subject.discussion();
              }

              var key = isReferral ? 'linkrobins-referral' : discussion ? 'd' + discussion.id() : 'neutral';

              byKey[key] = byKey[key] || { discussion: discussion, referral: isReferral, notifications: [] };
              byKey[key].notifications.push(notification);
              if (groups.indexOf(byKey[key]) === -1) groups.push(byKey[key]);
            });

            return groups.map(function (group) {
              var label;
              if (group.referral) {
                label = app.translator.trans('linkrobins-referral.forum.notifications.group_label');
              } else if (group.discussion) {
                var badges = group.discussion.badges().toArray();
                label = m(Link, { href: app.route.discussion(group.discussion) }, [
                  badges && badges.length ? m('ul', { className: 'HeaderListGroup-badges badges' }, listItems(badges)) : null,
                  m('span', null, group.discussion.title()),
                ]);
              } else {
                label = app.forum.attribute('title');
              }

              return m(
                HeaderListGroup,
                { label: label },
                group.notifications
                  .map(function (notification) {
                    return m(NotificationType, { notification: notification });
                  })
                  .filter(Boolean)
              );
            });
          });
        } catch (e) {
          // A core-internal change broke the reproduced pipeline; render core's
          // own grouping so notifications still show (without the referral group).
          return original(state);
        }
      });
    }

    var NotificationListNow = flarum.reg.checkModule && flarum.reg.checkModule('core', 'forum/components/NotificationList');
    if (NotificationListNow) installNotificationGrouping(NotificationListNow);
    flarum.reg.onLoad('core', 'forum/components/NotificationList', installNotificationGrouping);

    flarum.reg.onLoad('core', 'forum/components/UserCard', function (UserCard) {
      if (!UserCard) return;
      extend(UserCard.prototype, 'infoItems', function (items) {
        const user = this.attrs.user;
        const count = user && user.referralCount ? user.referralCount() : 0;
        if (!count) return;
        items.add(
          'referralCount',
          m(
            'div',
            { className: 'ReferralCard-info' },
            m('i', { className: 'icon fas fa-user-check ReferralCard-icon' }),
            m(
              'span',
              { className: 'ReferralCard-text' },
              app.translator.trans(
                count === 1 ? 'linkrobins-referral.forum.user_card.referrals_singular' : 'linkrobins-referral.forum.user_card.referrals_plural',
                { count: count }
              )
            )
          ),
          5
        );
      });
    });
  });
})();
