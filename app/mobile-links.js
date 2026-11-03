// What the app runs when it starts, in the official app and in Aty alike: the link of a request for consent, in a notice or on
// a child's page, opens the request inside the app. An account of Aty cannot open the site in a browser, and anyone else would
// have to sign in there again. The only JavaScript of this plugin in the app.
const that = this;

class FamilyConsentLinks extends that.CoreContentLinksHandlerBase {
    constructor() {
        super();
        this.name = 'local_oksigeniafamily_consent';
        this.pattern = /\/local\/oksigeniafamily\/consent\.php/;
    }

    getActions(siteIds, url, params) {
        const id = parseInt(params.id || '0', 10);
        const method = id ? 'mobile_consent_view' : 'mobile_consents_view';
        const args = id ? {id} : {};
        return [{
            action: (siteId) => that.CoreNavigatorService.navigateToSitePath(
                'siteplugins/content/local_oksigeniafamily/' + method + '/' + that.Md5.hashAsciiStr(JSON.stringify(args)),
                {
                    params: {title: that.TranslateService.instant('plugin.local_oksigeniafamily.consent:title'), args},
                    siteId,
                }
            ),
        }];
    }
}

that.CoreContentLinksDelegate.registerHandler(new FamilyConsentLinks());
