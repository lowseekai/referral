'use strict';

var qrModal = require('./qrModal');

app.initializers.add('linkrobins/referral-admin', function () {
  var ExtensionPage = flarum.reg.get('core', 'admin/components/ExtensionPage');
  var saveSettings = flarum.reg.get('core', 'admin/utils/saveSettings');
  var FieldSet = flarum.reg.get('core', 'common/components/FieldSet');
  var Switch = flarum.reg.get('core', 'common/components/Switch');
  var Button = flarum.reg.get('core', 'common/components/Button');
  var LoadingIndicator = flarum.reg.get('core', 'common/components/LoadingIndicator');

  function apiBase() {
    return (app.forum && app.forum.attribute('apiUrl')) || '/api';
  }

  // Campaign invite link. The admin app can't resolve forum routes (e.g. a
  // private-facade sign-up page), so this targets the forum root: the forum
  // frontend captures ?ref= from any page it boots on.
  function inviteUrl(code) {
    var base = (app.forum && app.forum.attribute('baseUrl')) || window.location.origin;
    return base.replace(/\/$/, '') + '/?ref=' + encodeURIComponent(code);
  }

  function setting(key) {
    return app.data.settings['linkrobins-referral.' + key];
  }

  function saveSetting(key, value) {
    app.data.settings['linkrobins-referral.' + key] = value;
    var body = {};
    body['linkrobins-referral.' + key] = value;
    return saveSettings(body);
  }

  function trans(key, args) {
    return app.translator.trans('linkrobins-referral.admin.' + key, args);
  }

  function help(text) {
    return m('p', { className: 'helpText ReferralAdmin-help' }, text);
  }

  // Groups offered by the eligibility picker — everything except the virtual
  // Guest (2) and Member (3) groups.
  function pickableGroups() {
    return app.store.all('groups').filter(function (g) {
      return g.id() !== '2' && g.id() !== '3';
    });
  }

  // A dedicated settings page registered for this extension only, instead of
  // overriding the shared ExtensionPage.prototype.content (which ran for every
  // extension's admin page and early-returned). The admin route resolver swaps
  // this in when /extension/linkrobins-referral is opened.
  class ReferralSettingsPage extends ExtensionPage {
    oninit(vnode) {
      super.oninit(vnode);

      this.codes = null;
      this.codesLoading = false;
      this.creating = false;
      this.deletingId = null;
      this.newLabel = '';
      this.newExpiry = '';
      this.loadCodes();

      // The store only has groups if another admin page already loaded them.
      if (!pickableGroups().length) {
        app.store.find('groups').then(m.redraw);
      }
    }

    content() {
      return m(
        'div',
        { className: 'ExtensionPage-settings' },
        m(
          'div',
          { className: 'container ReferralAdmin' },
          this.renderGeneralSection(),
          this.renderEligibilitySection(),
          this.renderPurchaseSection(),
          this.renderCampaignSection()
        )
      );
    }

    // ===== General =====
    renderGeneralSection() {
      return m(
        FieldSet,
        { label: trans('settings.title') },
        m(
          'div',
          null,
          m(
            Switch,
            {
              state: setting('require_referral') === '1',
              onchange: function (val) {
                saveSetting('require_referral', val ? '1' : '0').then(m.redraw);
              },
            },
            trans('settings.require_label')
          ),
          help(trans('settings.require_help'))
        )
      );
    }

    // ===== Eligibility =====
    renderEligibilitySection() {
      var self = this;
      var groups = pickableGroups();
      return m(
        FieldSet,
        { label: trans('eligibility.title') },
        m(
          'div',
          null,
          help(trans('eligibility.help')),

          m(
            'div',
            { className: 'Form-group' },
            m('label', trans('group_rules.title')),
            help(trans('group_rules.help')),
            this.renderGroupRules(groups)
          ),

          m('div', { className: 'Form-group' }, m('label', trans('eligibility.min_posts_label')), this.numberInput('eligibility_min_posts')),

          m('div', { className: 'Form-group' }, m('label', trans('eligibility.min_age_label')), this.numberInput('eligibility_min_age_days')),

          m(
            'div',
            { className: 'Form-group' },
            m('label', trans('eligibility.whitelist_label')),
            help(trans('eligibility.whitelist_help')),
            m('textarea', {
              className: 'FormControl',
              rows: 3,
              value: setting('eligibility_whitelist') || '',
              onchange: function (e) {
                saveSetting('eligibility_whitelist', e.target.value);
              },
            })
          )
        )
      );
    }

    renderGroupRules(groups) {
      var self = this;
      var rules = this.groupRules();

      if (!groups.length) return m(LoadingIndicator, { display: 'inline', size: 'small' });

      return m(
        'div',
        { className: 'ReferralAdmin-groupRules' },
        m(
          'div',
          { className: 'ReferralAdmin-groupRulesHeader' },
          m('span', trans('group_rules.group_label')),
          m('span', trans('group_rules.quantity_label')),
          m('span', trans('group_rules.expiry_label'))
        ),
        groups.map(function (g) {
          var id = Number(g.id());
          var rule = rules[id] || null;

          return m(
            'div',
            { className: 'ReferralAdmin-groupRule', key: id },
            m(
              Switch,
              {
                state: !!rule,
                onchange: function (enabled) {
                  self.setGroupRule(id, enabled ? { quantity: 1, expiryHours: 0 } : null);
                },
              },
              g.namePlural()
            ),
            m('input', {
              className: 'FormControl ReferralAdmin-numberInput',
              type: 'number',
              min: '0',
              disabled: !rule,
              value: rule ? rule.quantity : 0,
              onchange: function (e) {
                if (rule) self.setGroupRule(id, { quantity: Math.max(0, parseInt(e.target.value, 10) || 0), expiryHours: rule.expiryHours });
              },
            }),
            m('input', {
              className: 'FormControl ReferralAdmin-numberInput',
              type: 'number',
              min: '0',
              disabled: !rule,
              value: rule ? rule.expiryHours : 0,
              onchange: function (e) {
                if (rule) self.setGroupRule(id, { quantity: rule.quantity, expiryHours: Math.max(0, parseInt(e.target.value, 10) || 0) });
              },
            })
          );
        })
      );
    }

    renderPurchaseSection() {
      var self = this;

      return m(
        FieldSet,
        { label: trans('purchase.title') },
        m(
          'div',
          null,
          help(trans('purchase.help')),
          m(
            Switch,
            {
              state: setting('purchase_enabled') === '1',
              onchange: function (val) {
                saveSetting('purchase_enabled', val ? '1' : '0').then(m.redraw);
              },
            },
            trans('purchase.enabled_label')
          ),
          m('div', { className: 'Form-group' }, m('label', trans('purchase.price_label')), this.numberInput('purchase_price')),
          m(
            'div',
            { className: 'Form-group' },
            m('label', trans('purchase.daily_limit_label')),
            help(trans('purchase.daily_limit_help')),
            this.numberInput('purchase_daily_limit')
          ),
          m(
            'div',
            { className: 'Form-group' },
            m('label', trans('purchase.expiry_label')),
            help(trans('purchase.expiry_help')),
            this.numberInput('purchase_expiry_hours')
          ),
          m(
            'div',
            { className: 'Form-group' },
            m('label', trans('purchase.reward_label')),
            help(trans('purchase.reward_help')),
            this.numberInput('inviter_reward')
          )
        )
      );
    }

    // ===== Campaign codes =====
    renderCampaignSection() {
      var self = this;

      return m(
        FieldSet,
        { label: trans('campaign.title') },
        m(
          'div',
          null,
          help(trans('campaign.help')),
          m(
            'div',
            { className: 'ReferralAdmin-create' },
            m(
              'div',
              { className: 'Form-group ReferralAdmin-create-label' },
              m('label', trans('campaign.label_label')),
              m('input', {
                className: 'FormControl',
                type: 'text',
                placeholder: trans('campaign.label_placeholder'),
                value: this.newLabel || '',
                oninput: function (e) {
                  self.newLabel = e.target.value;
                },
              })
            ),
            m(
              'div',
              { className: 'Form-group' },
              m('label', trans('campaign.expiry_label')),
              m('input', {
                className: 'FormControl',
                type: 'date',
                value: this.newExpiry || '',
                onchange: function (e) {
                  self.newExpiry = e.target.value;
                },
              })
            ),
            m(
              Button,
              {
                className: 'Button Button--primary',
                icon: 'fas fa-plus',
                loading: this.creating,
                onclick: function () {
                  self.createCode();
                },
              },
              trans('campaign.create')
            )
          ),
          this.renderCodesTable()
        )
      );
    }

    renderCodesTable() {
      var self = this;

      if (this.codesLoading) {
        return m(LoadingIndicator, { display: 'block', size: 'small' });
      }

      if (!this.codes || !this.codes.length) {
        return m('p', { className: 'ReferralAdmin-empty' }, trans('campaign.none'));
      }

      return m(
        'table',
        { className: 'ReferralAdmin-table' },
        m(
          'thead',
          m(
            'tr',
            m('th', trans('campaign.col_code')),
            m('th', trans('campaign.col_label')),
            m('th', trans('campaign.col_uses')),
            m('th', trans('campaign.col_expiry')),
            m('th', '')
          )
        ),
        m(
          'tbody',
          this.codes.map(function (c) {
            return self.renderCodeRow(c);
          })
        )
      );
    }

    renderCodeRow(c) {
      var self = this;

      return m(
        'tr',
        { key: c.id },
        m('td', m('span', { className: 'ReferralAdmin-codeChip' }, c.code)),
        m('td', c.label || '—'),
        m('td', String(c.uses)),
        m(
          'td',
          c.expiresAt
            ? m(
                'span',
                { className: c.expired ? 'ReferralAdmin-expired' : '' },
                new Date(c.expiresAt).toLocaleDateString() + (c.expired ? ' (' + trans('campaign.expired') + ')' : '')
              )
            : trans('campaign.no_expiry')
        ),
        m(
          'td',
          { className: 'ReferralAdmin-actions' },
          m(Button, {
            className: 'Button Button--icon',
            icon: 'fas fa-qrcode',
            title: trans('campaign.qr'),
            onclick: function () {
              qrModal.show(inviteUrl(c.code), c.code);
            },
          }),
          m(Button, {
            className: 'Button Button--icon Button--danger',
            icon: 'fas fa-trash',
            loading: self.deletingId === c.id,
            title: trans('campaign.delete'),
            onclick: function () {
              self.deleteCode(c.id);
            },
          })
        )
      );
    }

    // ===== State + actions =====
    loadCodes() {
      var self = this;

      this.codesLoading = true;
      app
        .request({ method: 'GET', url: apiBase() + '/referral/campaign-codes' })
        .then(function (res) {
          self.codes = (res && res.data) || [];
          self.codesLoading = false;
          m.redraw();
        })
        .catch(function () {
          self.codes = [];
          self.codesLoading = false;
          m.redraw();
        });
    }

    groupRules() {
      var raw = setting('group_rules');
      var decoded;
      try {
        decoded = raw ? JSON.parse(raw) : null;
      } catch (e) {
        decoded = null;
      }

      if (Array.isArray(decoded)) {
        var current = {};
        decoded.forEach(function (rule) {
          if (!rule || !rule.groupId) return;
          current[Number(rule.groupId)] = {
            quantity: Math.max(0, Number(rule.quantity) || 0),
            expiryHours: Math.max(0, Number(rule.expiryHours) || Number(rule.expiryDays) * 24 || 0),
          };
        });
        return current;
      }

      var legacy = [];
      try {
        legacy = JSON.parse(setting('eligibility_groups') || '[]');
      } catch (e) {}

      var fallback = {};
      (legacy || []).forEach(function (id) {
        fallback[Number(id)] = { quantity: 1, expiryHours: 0 };
      });
      return fallback;
    }

    setGroupRule(id, rule) {
      var rules = this.groupRules();
      if (rule) rules[Number(id)] = rule;
      else delete rules[Number(id)];

      var payload = Object.keys(rules).map(function (groupId) {
        return {
          groupId: Number(groupId),
          quantity: Math.max(0, Number(rules[groupId].quantity) || 0),
          expiryHours: Math.max(0, Number(rules[groupId].expiryHours) || 0),
        };
      });

      saveSetting('group_rules', JSON.stringify(payload)).then(m.redraw);
    }

    createCode() {
      var self = this;
      var label = (this.newLabel || '').trim();
      var expiry = this.newExpiry || '';
      var attrs = {};
      if (label) attrs.label = label;
      if (expiry) attrs.expiresAt = expiry + 'T23:59:59+08:00';

      this.creating = true;
      m.redraw();
      app
        .request({ method: 'POST', url: apiBase() + '/referral/campaign-codes', body: { data: { attributes: attrs } } })
        .then(function (res) {
          self.codes = [res.data].concat(self.codes || []);
          self.newLabel = '';
          self.newExpiry = '';
          self.creating = false;
          m.redraw();
        })
        .catch(function () {
          self.creating = false;
          m.redraw();
        });
    }

    deleteCode(id) {
      var self = this;

      if (!confirm(trans('campaign.confirm_delete'))) return;
      this.deletingId = id;
      m.redraw();
      app
        .request({ method: 'DELETE', url: apiBase() + '/referral/campaign-codes/' + id })
        .then(function () {
          self.codes = (self.codes || []).filter(function (c) {
            return c.id !== id;
          });
          self.deletingId = null;
          m.redraw();
        })
        .catch(function () {
          self.deletingId = null;
          m.redraw();
        });
    }

    numberInput(key) {
      return m('input', {
        className: 'FormControl ReferralAdmin-numberInput',
        type: 'number',
        min: '0',
        value: setting(key) || '0',
        onchange: function (e) {
          saveSetting(key, String(Math.max(0, parseInt(e.target.value, 10) || 0)));
        },
      });
    }
  }

  app.registry.for('linkrobins-referral').registerPage(ReferralSettingsPage);
});
