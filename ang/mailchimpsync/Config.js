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
              var result = JSON.parse(r.result);
              if (!((typeof(result) === 'object') && (!Array.isArray(result)))) {
                // Config is invalid.
                console.warn("Invalid config, resetting. Received: ", r);
                result = {lists: {}, accounts: {}};
              }

              // PHP converts empty array to json array but we need an objects.
              if (!(('lists' in result) && !Array.isArray(result.lists))) {
                result.lists = {};
              }
              if (!(('accounts' in result) && !Array.isArray(result.accounts))) {
                result.accounts = {};
              }

              console.info("loaded config:", result);
              return result;
            },
            error => {
              if (confirm("Failed to load Mailchimp Sync config. Do you want to start from scratch? Nb. you should not normally do this, it's likely that it's a temporary network problem and you should try later. Start from scratch?")) {
                return {
                  accounts: {}, lists: {}
                };
              }
            });
          },
          mailingGroups: function(crmApi) {
            return crmApi('Group', 'get',  {
              "return": ["id","title"],
              "group_type": "Mailing List",
              "is_hidden": 0,
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

    $scope.$watch('mcsConfig.lists', function(newValue, oldValue, scope) {
      const rows = [];
      console.log("syncRows called");
      for (const listId in newValue) {
        const list = newValue[listId];
        rows.push({
          listId,
          listName: mcsConfig.accounts[list.apiKey].audiences[listId].name,
          groupName: mailingGroups[list.subscriptionGroup].title,
          webhookFound: mcsConfig.accounts[list.apiKey].audiences[listId].webhookFound,
        });
        for (const interestId in newValue[listId].interests) {
          rows.push({
            listId,
            interestId,
            interestName: mcsConfig.accounts[list.apiKey].audiences[listId].interests[interestId],
            groupName: mailingGroups[list.interests[interestId]].title
          });
        }
        rows.push({
          listId,
          interestName: '_new_',
          groupName: '',
        });
      }
      scope.syncRows = rows;
    }, true);

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
        return saveConfig.bind(this, 'Deleting...', 'Deleted')();
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
    $scope.interestEdit = function interestEdit(listId, interestId) {
      $scope.editData = {
        listId: listId,
        interestId: interestId,
        options: mcsConfig.accounts[mcsConfig.lists[listId].apiKey].audiences[listId].interests,
        groupId: '',
        apiKey: '',
        originalInterestId: interestId || null,
        isSaving: false
      };
      if (interestId && interestId in mcsConfig.lists[listId].interests) {
        $scope.editData.groupId = mcsConfig.lists[listId].interests[interestId];
      }
      $scope.view = 'editInterest';
    };
    $scope.interestDelete = function interestDelete(listId, interestId) {
      if (confirm("Delete interest-group subscription sync?")) {
        delete(mcsConfig.lists[listId].interests[interestId]);
        return saveConfig('Deleting...', 'Deleted');
      }
    };
    $scope.interestSave = function interestSave() {
      // Store in config array, keyed by Mailchimp List ID.
      if (! ('interests' in mcsConfig.lists[this.editData.listId])
        || ( Array.isArray(mcsConfig.lists[this.editData.listId].interests) )
      ) {
        mcsConfig.lists[this.editData.listId].interests = {};
      }
      mcsConfig.lists[this.editData.listId].interests[this.editData.interestId] = this.editData.groupId;

      // If the selected interest changed we need to remove the previous item.
      if (this.editData.originalInterestId && this.editData.originalInterestId !== this.editData.interestId) {
        delete(mcsConfig.lists[this.editData.listId].interests[this.editData.originalListId]);
      }

      this.editData.isSaving = true;
      console.log("Interest save, editData:", this.editData);
      console.log("Interest save, config:", mcsConfig);

      return saveConfig.bind(this)();
    };
    function saveConfig(msgDoing, msgDone, noReturnToOverview) {
      console.log("saveConfig noRet:", noReturnToOverview);
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts(msgDoing || 'Saving...'), success: ts(msgDone || 'Saved')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Mailchimpsync', 'updateconfig',
          { config: JSON.stringify(mcsConfig) })
        .then(r => {
          console.log("Saved value", r);
          this.editData.isSaving = false;
          mcsConfig = r.values.config;
          $scope.mcsConfig = mcsConfig;
          if (!noReturnToOverview) {
            this.view = 'overview';
          }
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

        return saveConfig('Deleting...', 'Deleted');
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

          // Store details on main config.
          mcsConfig.accounts[this.editData.apiKey] = Object.assign(
            {apiKey: this.editData.apiKey},
            r.values);

          return saveConfig.bind(this)('Saving...', 'Saved', !r.values.batchWebhookFound);
        })
      );
    };
    $scope.bwhAdd = function bwhAdd() {
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Contacting Mailchimp...'), success: ts('OK')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Mailchimpsync', 'updatewebhook', {
          api_key: this.editData.apiKey,
          process: 'add_batch_webhook'
        })
        .then(r => {
          mcsConfig = r.values.config;
          $scope.mcsConfig = mcsConfig;
        })
      );
    };
    $scope.bwhDelete = function bwhDelete(apiKey, bwhId) {
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Contacting Mailchimp...'), success: ts('OK')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Mailchimpsync', 'updatewebhook', {
          api_key: apiKey,
          id: bwhId,
          process: 'delete_batch_webhook'
        })
        .then(r => {
          mcsConfig = r.values.config;
          $scope.mcsConfig = mcsConfig;
        })
      );
    };
    $scope.webhookCreate = function webhookCreate() {
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Contacting Mailchimp...'), success: ts('OK')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Mailchimpsync', 'updatewebhook', {
          api_key: this.editData.apiKey,
          list_id: this.editData.listId,
          process: 'add_webhook'
        })
        .then(r => {
          mcsConfig = r.values.config;
          $scope.mcsConfig = mcsConfig;
        })
      );
    };
    $scope.webhookDelete = function webhookDelete(id) {
      return crmStatus(
        // Status messages. For defaults, just use "{}"
        {start: ts('Contacting Mailchimp...'), success: ts('OK')},
        // The save action. Note that crmApi() returns a promise.
        crmApi('Mailchimpsync', 'updatewebhook', {
          api_key: this.editData.apiKey,
          list_id: this.editData.listId,
          id: id,
          process: 'delete_webhook'
        })
        .then(r => {
          mcsConfig = r.values.config;
          $scope.mcsConfig = mcsConfig;
        })
      );
    };

  });

})(angular, CRM.$, CRM._);
