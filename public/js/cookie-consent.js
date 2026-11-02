/* Privacy-first consent UI for GrindFlow. No optional script is loaded here. */
(function () {
  "use strict";

  if (window.GrindFlowCookieConsent) return;

  const COOKIE_NAME = "gf_cookie_consent_v1";
  const MAX_AGE = 60 * 60 * 24 * 180;
  const choices = ["accepted", "rejected"];

  function storedChoice() {
    const item = document.cookie.split(";").map(function (part) {
      return part.trim();
    }).find(function (part) {
      return part.startsWith(COOKIE_NAME + "=");
    });
    if (!item) return null;
    const value = item.slice(COOKIE_NAME.length + 1);
    return choices.includes(value) ? value : null;
  }

  let choice = storedChoice() || "rejected";
  let isOpen = storedChoice() === null;

  function persist(value) {
    const secure = window.location && window.location.protocol === "https:" ? "; Secure" : "";
    document.cookie = COOKIE_NAME + "=" + value +
      "; Max-Age=" + MAX_AGE + "; Path=/; SameSite=Lax" + secure;
  }

  function render() {
    document.querySelectorAll("[data-gf-cookie-banner]").forEach(function (banner) {
      banner.hidden = !isOpen;
    });
  }

  function notify() {
    window.dispatchEvent(new CustomEvent("grindflow:cookie-consent", {
      detail: { choice: choice, optionalAllowed: choice === "accepted" }
    }));
  }

  function choose(value) {
    if (!choices.includes(value)) return;
    choice = value;
    persist(value);
    isOpen = false;
    render();
    notify();
  }

  function openPreferences() {
    // Opening preferences revokes a prior opt-in; abandoning them means reject.
    if (choice === "accepted") {
      choice = "rejected";
      persist(choice);
      notify();
    }
    isOpen = true;
    render();
    const reject = document.querySelector(
      '[data-gf-cookie-banner] [data-gf-cookie-action="reject"]'
    );
    if (reject) reject.focus();
  }

  function init() {
    document.querySelectorAll("[data-gf-cookie-banner]").forEach(function (banner) {
      banner.addEventListener("click", function (event) {
        const button = event.target && event.target.closest
          ? event.target.closest("[data-gf-cookie-action]") : null;
        if (!button) return;
        const action = button.getAttribute("data-gf-cookie-action");
        choose(action === "accept" ? "accepted" : "rejected");
      });
      banner.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          event.preventDefault();
          choose("rejected");
        }
      });
    });
    document.querySelectorAll("[data-gf-cookie-open]").forEach(function (link) {
      link.addEventListener("click", function (event) {
        event.preventDefault();
        openPreferences();
      });
    });
    render();
  }

  window.GrindFlowCookieConsent = Object.freeze({
    getChoice: function () { return choice; },
    optionalAllowed: function () { return choice === "accepted"; },
    openPreferences: openPreferences
  });
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
