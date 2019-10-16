(function(angular, $, _) {

  angular.module('mailchimpsync').config(function($routeProvider) {
      $routeProvider.when('/mailchimpsync/config', {
        controller: 'MailchimpsyncConfig',
        templateUrl: '~/mailchimpsync/Config.html',

        // If you need to look up data when opening the page, list it out
        // under "resolve".
        resolve: {
          mcsConfig: function(crmApi) {
            return crmApi('Setting', 'getvalue', { name: 'mailchimpsync_config' }).then(r => {
              // Basic validation
              if (!((typeof(r) === 'object') && (!Array.isArray(r)))) {
                // Config is invalid.
                console.warn("Invalid config, resetting:", r);
                r = {lists: {}, accounts: {}};
              }

              // PHP converts empty array to json array but we need an objects.
              if (!(('lists' in r) && !Array.isArray(r.lists))) {
                r.lists = {};
              }
              if (!(('accounts' in r) && !Array.isArray(r.accounts))) {
                r.accounts = {};
              }

              console.info("loaded config:", r);
              return r;
            });
          },
          mailingGroups: function(crmApi) {
            return crmApi('Group', 'get',  {
              "return": ["id","title"],
              //"group_type": "Mailing List",
              "options": {"limit":0, "sort": "title"}
            }).then(r => r.values || []);
          }
        }
      });
    }
  );

  // The controller uses *injection*. This default injects a few things:
  //   $scope -- This is the set of variables shared between JS and HTML.
  //   crmApi, crmStatus, crmUiHelp -- These are services provided by civicrm-core.
  //   myContact -- The current contact, defined above in config().
  angular.module('mailchimpsync').controller('MailchimpsyncConfig', function($scope, crmApi, crmStatus, crmUiHelp, mcsConfig, mailingGroups) {
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('mailchimpsync');
    var hs = $scope.hs = crmUiHelp({file: 'CRM/mailchimpsync/Config'}); // See: templates/CRM/mailchimpsync/Config.hlp

    // We have myContact available in JS. We also want to reference it in HTML.
    $scope.mcsConfig = mcsConfig;
    $scope.view = 'overview';
    $scope.editData = null;
    $scope.mailingGroups = mailingGroups;

    $scope.listEdit = function listEdit(listId) {
      console.log('listEdit', listId);
      $scope.editData = {
        listId: listId,
        groupId: '',
        apiKey: '',
        originalListId: listId || null,
        isSaving: false
      };
      if (listId && listId in mcsConfig.lists) {
        //xxx
        const d = mcsConfig.lists[listId];
        console.log('listEdit', listId, mcsConfig, d);
        $scope.editData.groupId = d.subscriptionGroup;
        $scope.editData.apiKey = d.apiKey;
      }
      $scope.view = 'editAudience';
    };
    $scope.listDelete = function listDelete(listId) {
      if (confirm("Delete audience-group subscription sync? " + listId)) {
        delete(mcsConfig.lists[listId]);
        return crmStatus(
          {start: ts('Deleting...'), success: ts('Deleted')},
          // The save action. Note that crmApi() returns a promise.
          crmApi('Setting', 'create', {
            mailchimpsync_config: mcsConfig
          })
        );
      }
    };
    $scope.listSave = function listSave() {
      // First copy the list stuff back to the original,
      // then save.
      const newListConfig = {
          subscriptionGroup: $scope.editData.groupId,
          apiKey: this.editData.apiKey
        };

      // Store in config array, keyed by Mailchimp List ID.
      mcsConfig.lists[this.editData.listId] = newListConfig;

      // If the API key changed we need to remove the previous item.
      if (this.editData.originalListId && this.editData.originalListId !== this.editData.listId) {
        delete(mcsConfig.lists[this.editData.originalListId]);
      }

      this.editData.isSaving = true;

      return saveConfig.bind(this)();
    };
    function saveConfig() {
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Saving...'), success: ts('Saved')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Setting', 'create', {
          mailchimpsync_config: mcsConfig
        })
        .then(r => {
          console.log("Saved value", r);
          this.editData.isSaving = false;
          this.view = 'overview';
        })
      );
    }
    $scope.accountEdit = function accountEdit(accountId) {
      console.log('accountEdit', accountId);
      $scope.editData = {
        accountId: accountId,
        apiKey: '',
        originalAccountId: accountId || null,
        isSaving: false
      };
      if (accountId && accountId in mcsConfig.accounts) {
        const d = mcsConfig.accounts[accountId];
        $scope.editData.apiKey = d.apiKey;
      }
      $scope.view = 'editAccount';
    };
    $scope.accountDelete = function accountDelete(accountId) {
      if (confirm("Delete account? This will remove all sync connnections too." + accountId)) {
        delete(mcsConfig.accounts[accountId]);

        for (const listId in mcsConfig.lists) {
          if (mcsConfig.lists[listId].apiKey === accountId) {
            delete(mcsConfig.lists[listId]);
          }
        }

        return crmStatus(
          {start: ts('Deleting...'), success: ts('Deleted')},
          // The save action. Note that crmApi() returns a promise.
          crmApi('Setting', 'create', {
            mailchimpsync_config: mcsConfig
          })
        );
      }
    };
    $scope.accountSave = function accountSave() {
      // Check we can use that API key.
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Contacting Mailchimp...'), success: ts('OK')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Mailchimpsync', 'fetchaccountinfo', {
          api_key: this.editData.apiKey
        })
        .then(r => {
          console.log("Fetch results value", r);

          if (this.editData.originalAccountId && this.editData.originalAccountId !== this.editData.apiKey) {
            // The API Key changed...

            // ...Update any lists that used the old API key.
            for (const listId in mcsConfig.lists) {
              if (mcsConfig.lists[listId].apiKey === this.editData.originalAccountId) {
                mcsConfig.lists[listId].apiKey = this.editData.apiKey;
              }
            }

            // ...Delete the old value from the accounts.
            delete(mcsConfig.accounts[this.editData.originalAccountId]);
          }

          mcsConfig.accounts[this.editData.apiKey] = Object.assign(
            {apiKey: this.editData.apiKey},
            r.values);

        })
      ).then(saveConfig.bind(this));
    };

  });

})(angular, CRM.$, CRM._);
