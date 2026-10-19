// The cookie banner (cookie-consent module) publishes this object; it is absent when the module
// is not on the page, which counts as no consent.
interface GuildForgeCookieConsentApi {
    /** Whether the visitor consented to the cookie category (required categories are always true). */
    hasConsent(categorySlug: string): boolean;
}

declare var GuildForgeCookieConsent: GuildForgeCookieConsentApi | undefined;
