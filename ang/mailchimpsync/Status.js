(function(angular, $, _) {

  angular.module('mailchimpsync').config(function($routeProvider) {
      $routeProvider.when('/mailchimpsync', {
        controller: 'MailchimpsyncStatus',
        templateUrl: '~/mailchimpsync/Status.html',

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
            }).then(r => r.values || {});
          },
          mcsStatus: function(crmApi) {
            return crmApi('Mailchimpsync', 'getstatus',  {}).then(r => r.values || {});
          },
        }
      });
    }
  );

  angular.module('mailchimpsync').controller('MailchimpsyncStatus', function($scope, crmApi, crmStatus, crmUiHelp, mcsConfig, mailingGroups, mcsStatus) {
    // The ts() and hs() functions help load strings for this module.
    var ts = $scope.ts = CRM.ts('mailchimpsync');
    var hs = $scope.hs = crmUiHelp({file: 'CRM/mailchimpsync/Status'}); // See: templates/CRM/mailchimpsync/Config.hlp

    // We have myContact available in JS. We also want to reference it in HTML.
    $scope.mcsConfig = mcsConfig;
    $scope.mcsStatus = mcsStatus;
    $scope.view = 'overview';
    $scope.editData = null;
    $scope.mailingGroups = mailingGroups;

    // Now page is loaded, do the slower fetch that gets more info.
    const getDetailedUpdate = function() {
      return crmApi('Mailchimpsync', 'getstatus', {batches: 1})
      .then(r => { mcsStatus = r.values || {}; $scope.mcsStatus= mcsStatus; });
    };
    setInterval( getDetailedUpdate, 60000);
    getDetailedUpdate();
  });

})(angular, CRM.$, CRM._);

