(function () {
  'use strict';

  function decodeBase64Url(value) {
    var base64 = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
    base64 += '='.repeat((4 - (base64.length % 4)) % 4);
    var binary = atob(base64);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return bytes;
  }

  function encodeBase64Url(value) {
    if (value === null || value === undefined) return null;
    var bytes = value instanceof ArrayBuffer ? new Uint8Array(value) : new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
    var binary = '';
    for (var i = 0; i < bytes.length; i += 1) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function publicKeyOptions(options) {
    var converted = Object.assign({}, options);
    converted.challenge = decodeBase64Url(converted.challenge);
    if (converted.user && converted.user.id) {
      converted.user = Object.assign({}, converted.user, { id: decodeBase64Url(converted.user.id) });
    }
    ['allowCredentials', 'excludeCredentials'].forEach(function (key) {
      converted[key] = (converted[key] || []).map(function (item) {
        return Object.assign({}, item, { id: decodeBase64Url(item.id) });
      });
    });
    return converted;
  }

  function credentialJson(credential) {
    var response = credential.response;
    var result = {
      id: credential.id,
      rawId: encodeBase64Url(credential.rawId),
      type: credential.type,
      authenticatorAttachment: credential.authenticatorAttachment || null,
      clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {},
      response: { clientDataJSON: encodeBase64Url(response.clientDataJSON) }
    };
    if (response.attestationObject) {
      result.response.attestationObject = encodeBase64Url(response.attestationObject);
      result.response.transports = response.getTransports ? response.getTransports() : [];
    } else {
      result.response.authenticatorData = encodeBase64Url(response.authenticatorData);
      result.response.signature = encodeBase64Url(response.signature);
      result.response.userHandle = response.userHandle ? encodeBase64Url(response.userHandle) : null;
    }
    return result;
  }

  async function postJson(url, body, signal) {
    var response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      signal: signal
    });
    var data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.success) throw new Error(data.message || 'The passkey request failed.');
    return data;
  }

  function message(element, value, isError) {
    if (!element) return;
    element.textContent = value || '';
    element.hidden = !value;
    element.classList.toggle('passkey-message-error', !!isError);
  }

  async function assertion(config, mediation, signal) {
    var options = await postJson(config.optionsUrl, { _token: config.csrf }, signal);
    var credential = await navigator.credentials.get({
      publicKey: publicKeyOptions(options.publicKey),
      mediation: mediation,
      signal: signal
    });
    if (!credential) return;
    var completed = await postJson(config.completeUrl, {
      _token: config.csrf,
      challenge_id: options.challenge_id,
      credential: credentialJson(credential)
    }, signal);
    window.location.assign(completed.redirect || '/');
  }

  window.passkeysInitLogin = function (config) {
    var button = document.getElementById(config.buttonId);
    var status = document.getElementById(config.statusId);
    if (!button) return;
    if (button.dataset.passkeyLoginBound === '1') return;
    button.dataset.passkeyLoginBound = '1';
    if (!window.PublicKeyCredential || !navigator.credentials) {
      button.hidden = true;
      return;
    }

    var conditionalController = null;
    button.addEventListener('click', async function () {
      if (conditionalController) conditionalController.abort();
      button.disabled = true;
      message(status, 'Waiting for your passkey...', false);
      try {
        await assertion(config, 'optional');
      } catch (error) {
        if (error.name !== 'AbortError' && error.name !== 'NotAllowedError') message(status, error.message || 'Passkey sign-in was cancelled.', true);
      } finally {
        button.disabled = false;
      }
    });

    if (PublicKeyCredential.isConditionalMediationAvailable) {
      PublicKeyCredential.isConditionalMediationAvailable().then(function (available) {
        if (!available) return;
        conditionalController = new AbortController();
        assertion(config, 'conditional', conditionalController.signal).catch(function (error) {
          if (error.name !== 'AbortError' && error.name !== 'NotAllowedError') console.warn('Conditional passkey sign-in unavailable.');
        });
      }).catch(function () {});
    }
  };

  window.passkeysInitRegistration = function (config) {
    var form = document.getElementById(config.formId);
    var status = document.getElementById(config.statusId);
    if (!form) return;
    if (form.dataset.passkeyRegistrationBound === '1') return;
    form.dataset.passkeyRegistrationBound = '1';
    if (!window.PublicKeyCredential || !navigator.credentials) {
      form.hidden = true;
      message(status, 'This browser does not support passkeys. You can keep using your password and authenticator app.', true);
      return;
    }

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      message(status, 'Waiting for your device...', false);
      try {
        var options = await postJson(config.optionsUrl, {
          _token: config.csrf,
          name: form.elements.name.value,
          current_password: form.elements.current_password.value
        });
        var credential = await navigator.credentials.create({ publicKey: publicKeyOptions(options.publicKey) });
        if (!credential) throw new Error('Passkey registration was cancelled.');
        await postJson(config.completeUrl, {
          _token: config.csrf,
          challenge_id: options.challenge_id,
          credential: credentialJson(credential)
        });
        window.location.assign('/?page=passkeys&success=' + encodeURIComponent('Passkey added.'));
      } catch (error) {
        if (error.name === 'NotAllowedError') message(status, 'Passkey registration was cancelled or timed out.', true);
        else message(status, error.message || 'The passkey could not be added.', true);
      } finally {
        form.elements.current_password.value = '';
        button.disabled = false;
      }
    });
  };

  function initPasskeyControls() {
    document.querySelectorAll('[data-passkey-confirm]').forEach(function (form) {
      if (form.dataset.passkeyConfirmBound === '1') return;
      form.dataset.passkeyConfirmBound = '1';
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.passkeyConfirm || 'Continue?')) event.preventDefault();
      });
    });
    var login = document.querySelector('[data-passkey-login]');
    if (login) {
      window.passkeysInitLogin({
        csrf: login.dataset.csrf || '',
        optionsUrl: login.dataset.optionsUrl || '',
        completeUrl: login.dataset.completeUrl || '',
        buttonId: login.id,
        statusId: login.dataset.statusId || ''
      });
    }
    var registration = document.querySelector('[data-passkey-register]');
    if (registration) {
      window.passkeysInitRegistration({
        csrf: registration.dataset.csrf || '',
        optionsUrl: registration.dataset.optionsUrl || '',
        completeUrl: registration.dataset.completeUrl || '',
        formId: registration.id,
        statusId: registration.dataset.statusId || ''
      });
    }
  }

  initPasskeyControls.pageInitializerId = 'passkey-controls';
  if (window.ProjectAlpha && typeof window.ProjectAlpha.registerPage === 'function') {
    window.ProjectAlpha.registerPage('passkeys', initPasskeyControls);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPasskeyControls, { once: true });
  } else {
    initPasskeyControls();
  }
})();
