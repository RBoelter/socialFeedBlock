/**
 * The posts come from the live public APIs of mastodon.social and bsky.app, so
 * these tests need network access. The tests build on each other, like the other
 * plugin suites: run the whole file, not a single test.
 */
describe('Social Feed Block plugin tests', function () {
    const row = 'component-grid-settings-plugins-settingsplugingrid-category-blocks-row-socialfeedblockplugin';
    const form = 'form[id="socialFeedSettings"]';
    const block = '.block_socialFeed';

    function openWebsiteSettings() {
        cy.visit('/index.php/publicknowledge/management/settings/website');
    }

    function openBlockSettings() {
        openWebsiteSettings();
        cy.get('button[id="plugins-button"]').click();
        cy.get('tr[id="' + row + '"] a[class="show_extras"]').click();
        cy.get('a[id^="' + row + '-settings-button"]').click();
        cy.waitJQuery();
        cy.wait(1000);
    }

    function save() {
        cy.get(form + ' button[id^="submitFormButton"]').click();
        cy.waitJQuery();
    }

    /** Opens the settings, fills in a Mastodon source and saves. */
    function useMastodon(instance, handle, extra = {}) {
        openBlockSettings();
        cy.get(form + ' select[name="network"]').select('mastodon');
        cy.get(form + ' select[name="mastodonSource"]').select(extra.source || 'account');
        cy.get(form + ' input[name="mastodonInstance"]').clear().type(instance);
        cy.get(form + ' input[name="mastodonHandle"]').clear().type(handle);
        cy.get(form + ' input[name="postCount"]').clear().type(extra.postCount || '3');
        cy.get(form + ' input[name="blockTitle"]').clear();
        if (extra.title) {
            cy.get(form + ' input[name="blockTitle"]').type(extra.title, {parseSpecialCharSequences: false});
        }
        save();
    }

    /** The block must not make a visitor's browser load anything from another host. */
    function assertNoRemoteResources() {
        cy.get('.pkp_structure_sidebar').then($sidebar => {
            const ours = $sidebar.find(block + ', ' + block + ' *, link[href*="socialFeedBlock"]');
            const loading = ours.filter('script[src], img[src], iframe[src], video[src], audio[src], source[src], link[href], object[data], embed[src]');
            const remote = loading.filter((i, el) => {
                const url = el.getAttribute('src') || el.getAttribute('href') || el.getAttribute('data');
                return new URL(url, window.location.href).host !== window.location.host;
            });
            expect(remote.length, 'resources loaded from another host').to.eq(0);
        });
    }

    it('Disable Social Feed Block', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openWebsiteSettings();
        cy.get('button[id="plugins-button"]').click();
        cy.get('input[id^="select-cell-socialfeedblockplugin-enabled"]')
            .then($btn => {
                if ($btn.attr('checked') === 'checked') {
                    cy.get('input[id^="select-cell-socialfeedblockplugin-enabled"]').click();
                    cy.get('[data-cy="dialog"]').contains('button', 'OK').click();
                    cy.get('div:contains(\'The plugin "Social Feed Block" has been disabled.\')');
                }
            });
    });

    it('Enable Social Feed Block and show it in the sidebar', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openWebsiteSettings();
        cy.get('button[id="plugins-button"]').click();
        cy.get('input[id^="select-cell-socialfeedblockplugin-enabled"]').click();
        cy.get('div:contains(\'The plugin "Social Feed Block" has been enabled.\')');
        cy.waitJQuery();

        useMastodon('mastodon.social', 'PublicKnowledgeProject');

        openWebsiteSettings();
        cy.get('button[id="appearance-button"]').click();
        cy.get('button[id="appearance-setup-button"]').click();
        cy.get('div[id="appearance-setup"] input[value="socialfeedblockplugin"]')
            .then($btn => {
                if ($btn.attr('checked') !== 'checked' && $btn.attr('checked') !== true) {
                    cy.get('div[id="appearance-setup"] input[value="socialfeedblockplugin"]').check();
                    cy.get('div[id="appearance-setup"] div[class*="pkpFormPage__footer"] button[label="Save"]').click();
                }
            });
    });

    it('Shows the posts of a Mastodon account', function () {
        cy.visit('/');
        cy.get(block + ' h2.title').should('contain.text', 'Latest posts');
        cy.get(block + ' .socialFeed_post').should('have.length', 3);
        cy.get(block + ' .socialFeed_post').each($post => {
            cy.wrap($post).find('.socialFeed_handle').invoke('text').should('match', /^@\S+@\S+$/);
            cy.wrap($post).find('time').should('have.attr', 'datetime');
            cy.wrap($post).find('.socialFeed_meta a').should('have.attr', 'href').and('match', /^https:\/\//);
        });
        cy.get(block + ' .socialFeed_more a')
            .should('have.attr', 'href', 'https://mastodon.social/@PublicKnowledgeProject');
    });

    it('Renders no raw locale keys or unreplaced placeholders', function () {
        // Regression test: passing "count" to {translate} made OJS look for a plural form
        // and printed ##plugins.blocks.socialFeed.reposts## instead of the text.
        cy.visit('/');
        cy.get(block).invoke('text')
            .should('not.match', /##|\{\$\w+\}/);
        cy.get(block + ' .socialFeed_stats li').first().invoke('text')
            .should('match', /^(Replies|Reposts|Likes): \d+$/);
    });

    it('Makes the visitor\'s browser load nothing from another host', function () {
        cy.visit('/');
        cy.get(block + ' .socialFeed_post').should('have.length.greaterThan', 0);
        assertNoRemoteResources();
        cy.get(block + ' script, ' + block + ' img, ' + block + ' iframe').should('not.exist');
    });

    it('Links posts to the original in a new tab without leaking the opener', function () {
        cy.visit('/');
        cy.get(block + ' a[href^="http"]').each($link => {
            expect($link.attr('target')).to.eq('_blank');
            expect($link.attr('rel')).to.contain('noopener');
        });
    });

    it('Saving the settings succeeds without a server error', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        cy.intercept('POST', '**/settings-plugin-grid/manage*').as('saveSettings');
        openBlockSettings();
        cy.get(form + ' button[id^="submitFormButton"]').click();
        cy.wait('@saveSettings').then(({response}) => {
            expect(response.statusCode).to.eq(200);
            expect(response.body.status).to.eq(true);
        });
    });

    it('Reopens the settings with the values that are in effect', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form + ' input[name="postCount"]').clear();
        cy.get(form + ' input[name="cacheTtl"]').clear();
        save();

        openBlockSettings();
        cy.get(form + ' input[name="postCount"]').should('have.value', '5');
        cy.get(form + ' input[name="cacheTtl"]').should('have.value', '15');
        cy.get(form + ' input[name="mastodonInstance"]').should('have.value', 'mastodon.social');
        cy.get(form + ' input[name="mastodonHandle"]').should('have.value', 'PublicKnowledgeProject');

        // leave the setting the way the rest of the suite expects it
        cy.get(form + ' input[name="postCount"]').clear().type('3');
        save();
    });

    it('Shows only the fields of the selected network', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form + ' select[name="network"]').select('mastodon');
        cy.get(form + ' .socialFeedNetworkFields[data-network="mastodon"]').should('be.visible');
        cy.get(form + ' .socialFeedNetworkFields[data-network="bluesky"]').should('not.be.visible');

        cy.get(form + ' select[name="network"]').select('bluesky');
        cy.get(form + ' .socialFeedNetworkFields[data-network="mastodon"]').should('not.be.visible');
        cy.get(form + ' .socialFeedNetworkFields[data-network="bluesky"]').should('be.visible');
    });

    it('Offers no hashtag source for Bluesky, whose API refuses searches without a login', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form + ' select[name="blueskySource"] option').then($options => {
            expect([...$options].map(o => o.value)).to.deep.eq(['author', 'feed']);
        });
        cy.get(form + ' select[name="mastodonSource"] option').then($options => {
            expect([...$options].map(o => o.value)).to.deep.eq(['account', 'hashtag']);
        });
    });

    it('Rejects invalid input, keeps the old settings and explains why', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form + ' select[name="network"]').select('mastodon');
        cy.get(form + ' input[name="mastodonInstance"]').clear().type('https://evil.example/path');
        cy.get(form + ' input[name="mastodonHandle"]').clear().type('user@other.social');
        cy.get(form + ' input[name="postCount"]').clear().type('99');
        cy.get(form + ' button[id^="submitFormButton"]').click();
        cy.waitJQuery();

        // The wording of the error messages differs from the help texts under the fields
        // ("Enter ..." vs "The server's host name ..."), so this does not pass on help texts alone.
        cy.get(form).should('be.visible')
            .and('contain.text', 'Enter the server\'s host name')
            .and('contain.text', 'Enter an account name without the server part')
            .and('contain.text', 'Enter a whole number from 1 to 20');

        openBlockSettings();
        cy.get(form + ' input[name="mastodonInstance"]').should('have.value', 'mastodon.social');
        cy.get(form + ' input[name="mastodonHandle"]').should('have.value', 'PublicKnowledgeProject');
        cy.get(form + ' input[name="postCount"]').should('have.value', '3');
    });

    it('Refuses values posted directly to the endpoint that the form would not send', function () {
        // The form cannot send a made-up network or a host with a path, so the request
        // is posted to the same endpoint the form submits to.
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form).then($form => {
            const csrfToken = $form.find('input[name="csrfToken"]').val();
            cy.request({
                method: 'POST',
                url: $form.attr('action'),
                form: true,
                body: {
                    csrfToken: csrfToken,
                    network: 'mastodon',
                    mastodonSource: 'account',
                    mastodonInstance: '127.0.0.1',
                    mastodonHandle: 'x',
                },
            }).then(response => {
                // a failed validation redisplays the form: HTML with the error, and the setting stays put
                expect(response.status).to.eq(200);
                expect(response.body.content).to.contain('Enter the server\'s host name');
            });
        });

        cy.visit('/');
        cy.get(block + ' .socialFeed_more a').should('have.attr', 'href').and('contain', 'mastodon.social');
    });

    it('Escapes the block title', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        useMastodon('mastodon.social', 'PublicKnowledgeProject', {title: '<img src=x onerror=window.xssTitle=1>Hi'});

        cy.visit('/');
        cy.get(block + ' h2.title').invoke('text').should('eq', '<img src=x onerror=window.xssTitle=1>Hi');
        cy.get(block + ' h2.title img').should('not.exist');
        cy.window().should('not.have.property', 'xssTitle');
    });

    it('Shows the posts of a Bluesky account', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form + ' select[name="network"]').select('bluesky');
        cy.get(form + ' select[name="blueskySource"]').select('author');
        cy.get(form + ' input[name="blueskyActor"]').clear().type('pkp.sfu.ca');
        cy.get(form + ' input[name="blockTitle"]').clear().type('On Bluesky');
        cy.get(form + ' input[name="excludeReplies"]').check();
        save();

        cy.visit('/');
        cy.get(block + ' h2.title').should('have.text', 'On Bluesky');
        cy.get(block + ' .socialFeed_post').should('have.length.greaterThan', 0);
        cy.get(block + ' .socialFeed_handle').first().invoke('text').should('match', /^@\S+$/);
        cy.get(block + ' .socialFeed_more a')
            .should('have.attr', 'href', 'https://bsky.app/profile/pkp.sfu.ca');
        assertNoRemoteResources();
    });

    it('Shows a custom Bluesky feed', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form + ' select[name="blueskySource"]').select('feed');
        cy.get(form + ' input[name="blueskyActor"]').clear()
            .type('at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/whats-hot');
        save();

        cy.visit('/');
        cy.get(block + ' .socialFeed_post').should('have.length.greaterThan', 0);
        cy.get(block + ' .socialFeed_more a').should('have.attr', 'href')
            .and('contain', '/feed/whats-hot');
    });

    it('Keeps the journal page working when the feed cannot be loaded', function () {
        // "invalid" is a reserved top-level domain that never resolves.
        cy.login('admin', 'admin', 'publicknowledge');
        useMastodon('social.does-not-exist.invalid', 'someone');

        cy.request('/').its('status').should('eq', 200);
        cy.visit('/');
        cy.get('.pkp_structure_main').should('exist');
        cy.get(block).should('not.exist');
    });

    it('Tells the administrator when a saved source cannot be reached', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        openBlockSettings();
        cy.get(form + ' select[name="network"]').select('mastodon');
        cy.get(form + ' input[name="mastodonInstance"]').clear().type('social.does-not-exist.invalid');
        cy.get(form + ' input[name="mastodonHandle"]').clear().type('someone');
        cy.get(form + ' button[id^="submitFormButton"]').click();
        cy.contains('The settings were saved, but the posts could not be loaded');
    });

    it('Restores a working source for the next run', function () {
        cy.login('admin', 'admin', 'publicknowledge');
        useMastodon('mastodon.social', 'PublicKnowledgeProject');

        cy.visit('/');
        cy.get(block + ' .socialFeed_post').should('have.length', 3);
    });
});
