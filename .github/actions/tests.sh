#!/bin/bash
# Copy into a plugin repo as .github/actions/tests.sh (same content works on
# every branch - it only cares about the plugin's own path). Sourced by
# pkp-github-actions' run-plugin-actions.sh once the app + this plugin are
# installed and built. Pattern verified against pkp/quickSubmit and
# pkp/oaiJats's real tests.sh.
set -e

PLUGIN_PATH=plugins/blocks/socialFeedBlock

# Code style (PKP's own ruleset) and unit tests run first when the plugin has them: they take
# seconds, Cypress takes minutes. The CI job installs Composer's dev dependencies, which
# provide php-cs-fixer and PHPUnit.
if [ -f "$PLUGIN_PATH/.php-cs-fixer.php" ]; then
  PHP_CS_FIXER_IGNORE_ENV=1 php lib/pkp/lib/vendor/bin/php-cs-fixer fix --dry-run --diff \
    --config="$PLUGIN_PATH/.php-cs-fixer.php"
fi
if [ -f "$PLUGIN_PATH/phpunit.xml" ]; then
  (cd "$PLUGIN_PATH" && php ../../../lib/pkp/lib/vendor/bin/phpunit --no-progress)
fi

npx cypress run --headless --browser chrome \
  --config '{"specPattern":["plugins/blocks/socialFeedBlock/cypress/tests/functional/*.cy.js"]}'
