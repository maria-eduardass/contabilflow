// JavaScript vanilla do ContábilFlow (sem scripts inline: a CSP bloqueia).

document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    // Confirmação para ações destrutivas: <form data-confirm="mensagem">.
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }

    // Evita duplo clique: desabilita os botões depois que o envio começou.
    window.setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = true;
        });
    }, 0);
});

// Lembrete rápido: prévia do texto, entrega por WhatsApp/e-mail/cópia e registro no histórico.
(function () {
    var opener = document.querySelector('[data-lembrete-abrir]');
    var modalEl = document.getElementById('lembrete-modal');
    if (!opener || !modalEl) {
        return;
    }

    var textarea = modalEl.querySelector('[data-lembrete-texto]');
    var badge = modalEl.querySelector('[data-lembrete-badge]');
    var errorBox = modalEl.querySelector('[data-lembrete-erro]');
    var okBox = modalEl.querySelector('[data-lembrete-ok]');
    var whatsappLink = modalEl.querySelector('[data-lembrete-canal="whatsapp"]');
    var emailLink = modalEl.querySelector('[data-lembrete-canal="email"]');
    var copyButton = modalEl.querySelector('[data-lembrete-canal="copiado"]');
    var state = { whatsapp: null, mailto: null, registered: false };

    function csrfToken() {
        var field = document.querySelector('input[name="_csrf"]');
        return field ? field.value : '';
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
    }

    function setLink(link, url, missingHint) {
        if (url) {
            link.classList.remove('disabled');
            link.setAttribute('aria-disabled', 'false');
            link.removeAttribute('tabindex');
            link.removeAttribute('title');
        } else {
            link.classList.add('disabled');
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('tabindex', '-1');
            link.setAttribute('title', missingHint);
        }
    }

    // O contador pode editar o texto; o link é refeito com o texto atual na hora do clique.
    function urlFor(kind) {
        var text = textarea.value;
        if (kind === 'whatsapp' && state.whatsapp) {
            return state.whatsapp.split('?text=')[0] + '?text=' + encodeURIComponent(text);
        }
        if (kind === 'email' && state.mailto) {
            return state.mailto.split('&body=')[0] + '&body=' + encodeURIComponent(text);
        }
        return null;
    }

    function register(channel) {
        fetch(opener.getAttribute('data-registro-url'), {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken()
            },
            body: new URLSearchParams({ canal: channel, mensagem: textarea.value }).toString()
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('registro recusado');
            }
            state.registered = true;
            okBox.classList.remove('d-none');
        }).catch(function () {
            // O envio já aconteceu; só avisamos que o histórico não foi atualizado.
            showError('A mensagem foi enviada, mas não foi possível registrá-la no histórico.');
        });
    }

    opener.addEventListener('click', function () {
        errorBox.classList.add('d-none');
        okBox.classList.add('d-none');
        state.registered = false;
        opener.disabled = true;

        fetch(opener.getAttribute('data-preview-url'), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().then(function (body) {
                return { ok: response.ok, body: body };
            });
        }).then(function (result) {
            if (!result.ok) {
                var message = result.body && result.body.error ? result.body.error.message : 'Não foi possível gerar o lembrete.';
                throw new Error(message);
            }
            var data = result.body;
            textarea.value = data.mensagem;
            badge.className = 'badge ' + data.semaforoBadge;
            badge.textContent = data.semaforoLabel;
            state.whatsapp = data.whatsappUrl;
            state.mailto = data.mailtoUrl;
            setLink(whatsappLink, state.whatsapp, 'Empresa sem WhatsApp cadastrado');
            setLink(emailLink, state.mailto, 'Empresa sem e-mail cadastrado');
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }).catch(function (error) {
            window.alert(error.message);
        }).then(function () {
            opener.disabled = false;
        });
    });

    [whatsappLink, emailLink].forEach(function (link) {
        link.addEventListener('click', function (event) {
            var kind = link.getAttribute('data-lembrete-canal');
            var url = urlFor(kind);
            if (!url) {
                event.preventDefault();
                return;
            }
            link.setAttribute('href', url);
            register(kind);
        });
    });

    copyButton.addEventListener('click', function () {
        var text = textarea.value;
        var done = function () {
            register('copiado');
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () {
                showError('Não foi possível copiar. Selecione o texto e use Ctrl+C.');
            });
            return;
        }
        textarea.select();
        if (document.execCommand('copy')) {
            done();
        } else {
            showError('Não foi possível copiar. Selecione o texto e use Ctrl+C.');
        }
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (state.registered) {
            window.location.reload();
        }
    });
})();
