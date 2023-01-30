/// <reference types="Cypress" />

import AddonManager from '../../elements/pages/addons/AddonManager';
const addonsPage = new AddonManager;

context('CLI', () => {

    before(function(){
        cy.task('db:seed')

        //copy templates
        cy.task('filesystem:copy', { from: 'support/templates/*', to: '../../system/user/templates/' }).then(() => {
            cy.authVisit('admin.php?/cp/design');
            cy.wait(1000);
            cy.authVisit('admin.php?/cp/design/manager/');
            cy.wait(1000);
        })
        cy.task('filesystem:delete', '../../system/user/addons/cypress_addon')
    })

    it('list all commands', function() {
        cy.exec('php ../../system/ee/eecli.php list').then((result) => {
            expect(result.code).to.eq(0)
            expect(result.stderr).to.be.empty
            expect(result.stdout).to.not.contain('on line')
            expect(result.stdout).to.not.contain('caught:')
        })
    })

    it('display help', function() {
        cy.exec('php ../../system/ee/eecli.php cache:clear --help').then((result) => {
            expect(result.code).to.eq(0)
            expect(result.stderr).to.be.empty
            expect(result.stdout).to.not.contain('on line')
            expect(result.stdout).to.not.contain('caught:')
            expect(result.stdout).to.contain('SUMMARY')
            expect(result.stdout).to.contain('USAGE')
            expect(result.stdout).to.contain('DESCRIPTION')
            expect(result.stdout).to.contain('SUMMARY')
        })
    })

    describe('clear caches', function() {
        before(function() {
            // turn the caching on
            cy.authVisit('admin.php?/cp/design/manager/cli')
            cy.get('.app-listing__row a').contains('index').click()
            cy.get('.js-tab-button.tab-bar__tab').contains('Settings').click()
            cy.get('[data-toggle-for="cache"]').click()
            cy.get('button').contains('Save').click()
        })

        it('clear caches', function() {
            cy.clearCookies()
            cy.wait(1000)
            cy.visit('index.php/cli/index')
            cy.wait(1000)
            cy.visit('index.php/cli/index')
            cy.wait(1000)

            cy.readFile('../../system/user/cache/default_site/tag_cache/index.html').should('exist'); //assert cache exists
            cy.readFile('../../system/user/cache/default_site/page_cache/index.html').should('exist'); //assert cache exists

            cy.exec('php ../../system/ee/eecli.php cache:clear -t tag').then((result) => {
                expect(result.code).to.eq(0)
                expect(result.stderr).to.be.empty
                expect(result.stdout).to.not.contain('on line')
                expect(result.stdout).to.not.contain('caught:')
                expect(result.stdout).to.contain('Tag caches are cleared!')
                cy.readFile('../../system/user/cache/default_site/tag_cache/index.html').should('not.exist')
                cy.readFile('../../system/user/cache/default_site/page_cache/index.html').should('exist');

                cy.wait(1000)
                cy.visit('index.php/cli/index')
                cy.wait(1000)
                cy.visit('index.php/cli/index')
                cy.wait(1000)

                cy.exec('php ../../system/ee/eecli.php cache:clear').then((result) => {
                    expect(result.code).to.eq(0)
                    expect(result.stderr).to.be.empty
                    expect(result.stdout).to.not.contain('on line')
                    expect(result.stdout).to.not.contain('caught:')
                    expect(result.stdout).to.contain('All caches are cleared!')
                    cy.readFile('../../system/user/cache/default_site/tag_cache/index.html').should('not.exist')
                    cy.readFile('../../system/user/cache/default_site/page_cache/index.html').should('not.exist');
                })
            })
        })

    })

    describe('create add-on', function() {
        it('create add-on', function() {
            cy.exec('php ../../system/ee/eecli.php make:addon "Cypress Addon" -v 0.1.0 -d "Some good description" -a "ExpressionEngine" -u https://expressionengine.com').then((result) => {
                expect(result.code).to.eq(0)
                expect(result.stderr).to.be.empty
                expect(result.stdout).to.not.contain('on line')
                expect(result.stdout).to.not.contain('caught:')
                expect(result.stdout).to.contain('Your add-on has been created successfully!')
                cy.log('all files are in place');
                cy.readFile('../../system/user/addons/cypress_addon/index.html').should('exist')
                cy.readFile('../../system/user/addons/cypress_addon/addon.setup.php').should('exist')
                cy.readFile('../../system/user/addons/cypress_addon/mod.cypress_addon.php').should('exist')
                cy.readFile('../../system/user/addons/cypress_addon/upd.cypress_addon.php').should('exist')
                cy.readFile('../../system/user/addons/cypress_addon/language/index.html').should('exist')
                cy.readFile('../../system/user/addons/cypress_addon/language/english/index.html').should('exist')
                cy.readFile('../../system/user/addons/cypress_addon/language/english/cypress_addon_lang.php').should('exist')
            })
        })

        it('add CP routes', function() {
            cy.exec('php ../../system/ee/eecli.php make:cp-route index --addon=cypress_addon').then((result) => {
                expect(result.code).to.eq(0)
                expect(result.stderr).to.be.empty
                expect(result.stdout).to.not.contain('on line')
                expect(result.stdout).to.not.contain('caught:')
                expect(result.stdout).to.contain('Control panel route created successfully!')

                cy.readFile('../../system/user/addons/cypress_addon/mcp.cypress_addon.php').should('exist')
                cy.readFile('../../system/user/addons/cypress_addon/ControlPanel/Routes/Index.php').should('exist')

                cy.exec('php ../../system/ee/eecli.php make:cp-route view --addon=cypress_addon').then((result) => {
                    expect(result.code).to.eq(0)
                    expect(result.stderr).to.be.empty
                    expect(result.stdout).to.not.contain('on line')
                    expect(result.stdout).to.not.contain('caught:')
                    expect(result.stdout).to.contain('Control panel route created successfully!')

                    cy.readFile('../../system/user/addons/cypress_addon/ControlPanel/Routes/View.php').should('exist')

                })

            })
        })

        it('install and add sidebar', function() {
            cy.log('the add-on can be installed');
            cy.authVisit('admin.php?/cp/addons')
            addonsPage.get('first_party_addons').find('.add-on-card:contains("Cypress Addon") a').click()
            addonsPage.hasAlert()
            cy.get('div.app-notice.app-notice--inline').first().invoke('text').should('include', 'Add-Ons Installed')
            cy.get('div.app-notice.app-notice--inline').first().invoke('text').should('include', "Cypress Addon")
            addonsPage.get('uninstalled_addons').find('.add-on-card__title').should('not.contain', "Cypress Addon")

            cy.log('add sidebar')
            cy.exec('php ../../system/ee/eecli.php make:sidebar --addon=cypress_addon').then((result) => {
                expect(result.code).to.eq(0)
                expect(result.stderr).to.be.empty
                expect(result.stdout).to.not.contain('on line')
                expect(result.stdout).to.not.contain('caught:')
                expect(result.stdout).to.contain('Sidebar created successfully!')

                cy.readFile('../../system/user/addons/cypress_addon/ControlPanel/Sidebar.php').should('exist')
            })
        })

        it('can navigate to a settings page', function() {
            cy.authVisit('admin.php?/cp/addons')
            const btn = addonsPage.get('first_party_section').find('.add-on-card:contains("Cypress Addon")').find('.js-dropdown-toggle')
            btn.click()
            btn.next('.dropdown').find('a:contains("Settings")').click()
            cy.hasNoErrors()
            cy.get('.secondary-sidebar__cypress_addon').should('exist')
            cy.get('.secondary-sidebar__cypress_addon .sidebar__link').contains('View')
        })

        it('check template tag', function() {
            cy.exec('php ../../system/ee/eecli.php make:template-tag cypress --addon=cypress_addon').then((result) => {
                expect(result.code).to.eq(0)
                expect(result.stderr).to.be.empty
                expect(result.stdout).to.not.contain('on line')
                expect(result.stdout).to.not.contain('caught:')
                expect(result.stdout).to.contain('Tag created successfully!')

                cy.readFile('../../system/user/addons/cypress_addon/Tags/Cypress.php').should('exist')

                cy.visit('index.php/cli/tag')
                cy.get('#single_tag').should('contain', 'My tag')
            })
        })

        it('check fieldtype', function() {
            cy.exec('php ../../system/ee/eecli.php make:fieldtype cypress_addon --addon=cypress_addon').then((result) => {
                expect(result.code).to.eq(0)
                expect(result.stderr).to.be.empty
                expect(result.stdout).to.not.contain('on line')
                expect(result.stdout).to.not.contain('caught:')
                expect(result.stdout).to.contain('Fieldtype created successfully!')

                cy.readFile('../../system/user/addons/cypress_addon/ft.cypress_addon.php').should('exist')

                // can add fieldtype
                cy.authVisit('admin.php?/cp/fields/create/1')
                cy.get('[data-input-value=field_type] .select__button').click({force: true})
                cy.get('div[class="select__dropdown-item"]').contains("Cypress").click({force: true})
                cy.get('input[type="text"][name = "field_label"]').type('Cypress')
                cy.hasNoErrors()
                cy.get('body').type('{ctrl}', {release: false}).type('s')
                cy.get('p').contains('has been created')

                // add content to entry
                cy.authVisit('admin.php?/cp/publish/edit/entry/1')
                cy.get('.field-instruct:contains("Cypress")').parent().find('input[type=text]').type('test')
                cy.get('body').type('{ctrl}', {release: false}).type('s')
                cy.get('p').contains('Entry Updated')

                cy.visit('index.php/cli/field')
                cy.get('.field_tag').should('contain', 'Magic!')
            })
        })

        after(function() {
            // uninstall the add-on and remove the files
            cy.authVisit('admin.php?/cp/addons')
            const btn = addonsPage.get('first_party_section').find('.add-on-card:contains("Cypress Addon")').find('.js-dropdown-toggle')
            btn.click()
            btn.next('.dropdown').find('a:contains("Uninstall")').click()
            addonsPage.get('modal_submit_button').should('be.visible')

            cy.get('div.modal:visible #fieldset-confirm button').should('be.visible')
            cy.get('div.modal:visible #fieldset-confirm button').trigger('click')

            addonsPage.get('modal_submit_button').contains('Confirm, and Uninstall').click() // Submits a form
            cy.hasNoErrors();

            // The filter should not change
            addonsPage.hasAlert()

            addonsPage.get('alert').contains("Add-Ons Uninstalled")
            addonsPage.get('alert').contains("Cypress Addon");

            cy.task('filesystem:delete', '../../system/user/addons/cypress_addon')
        })

    })

})
